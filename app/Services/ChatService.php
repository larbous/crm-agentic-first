<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ChatRepository;
use App\Services\AI\CommandRouter;
use App\Services\AI\ContextBuilder;
use App\Services\AI\IaErro;
use InvalidArgumentException;
use Throwable;

/**
 * Orquestra o chat (SPEC §3): recebe a mensagem, escolhe o caminho (comando "/", agente/squad direto ou
 * linguagem natural), grava operador e resposta em chat_mensagens (só exibição) e trata os cliques nos botões
 * das respostas (desambiguar, criar, confirmar, cancelar, desfazer) sem nova chamada à IA.
 */
final class ChatService
{
    public const MAX_MENSAGEM = 2000;

    public function __construct(
        private readonly ChatRepository $chat = new ChatRepository(),
        private readonly CommandRouter $router = new CommandRouter(),
        private readonly ChatComandos $comandos = new ChatComandos(),
        private readonly ChatAcoes $acoes = new ChatAcoes(),
        private readonly ActionExecutor $executor = new ActionExecutor(),
    ) {
    }

    /**
     * @param string|null $caminho caminho da tela aberta (ex.: "/empresas/12"), usado como contexto
     * @return array{operador:array,resposta:array} linhas de chat_mensagens
     * @throws InvalidArgumentException mensagem vazia ou grande demais
     */
    public function enviar(string $mensagem, ?string $caminho): array
    {
        $mensagem = trim($mensagem);
        if ($mensagem === '') {
            throw new InvalidArgumentException('Escreva uma mensagem.');
        }
        if (mb_strlen($mensagem) > self::MAX_MENSAGEM) {
            throw new InvalidArgumentException('A mensagem é longa demais (máximo de ' . self::MAX_MENSAGEM . ' caracteres).');
        }

        $tela = ContextBuilder::tela($caminho);
        $ultimaRef = $this->chat->ultimaRef();
        $operadorId = $this->chat->inserir('operador', $mensagem, null, ['tela' => $tela, 'ultima_ref' => $ultimaRef]);

        try {
            $resposta = match ($mensagem[0]) {
                '/'     => $this->comandos->executar($mensagem, $tela, $ultimaRef),
                '@'     => $this->agenteDireto($mensagem, $tela, $ultimaRef),
                '#'     => $this->agenteDireto($mensagem, $tela, $ultimaRef, 'squad'),
                default => $this->linguagemNatural($mensagem, $tela, $ultimaRef),
            };
        } catch (IaErro $e) {
            $resposta = RespostaChat::erro($e->getMessage());
        } catch (Throwable $e) {
            error_log((string) $e);
            $resposta = RespostaChat::erro('Erro interno ao processar o pedido. Confira a auditoria antes de repetir.');
        }

        return ['operador' => $this->chat->encontrar($operadorId), 'resposta' => $this->salvar($resposta)];
    }

    /**
     * Clique em um botão de uma resposta.
     * @param string $acao escolher | criar | confirmar | cancelar | desfazer
     * @return array{atualizada:?array,resposta:?array} mensagem original atualizada e/ou nova resposta
     * @throws InvalidArgumentException pedido inválido ou já respondido
     */
    public function acionar(int $mensagemId, string $acao, ?int $opcao): array
    {
        $msg = $this->chat->encontrar($mensagemId);
        $p = $msg['payload'] ?? null;
        if ($msg === null || $msg['papel'] !== 'sistema' || !is_array($p)) {
            throw new InvalidArgumentException('Mensagem não encontrada.');
        }
        $tipo = (string) ($p['tipo'] ?? '');

        try {
            if ($acao === 'desfazer') {
                if ($tipo !== 'acao' || empty($p['ok']) || !empty($p['desfeito']) || empty($p['log_id'])) {
                    throw new InvalidArgumentException('Esta ação não pode ser desfeita por aqui.');
                }
                $r = $this->executor->desfazer((int) $p['log_id'], 'humano');
                if (!$r->ok) {
                    return ['atualizada' => null, 'resposta' => $this->salvar(RespostaChat::erro($r->mensagem))];
                }
                $p['desfeito'] = true;
                $this->chat->atualizarPayload($mensagemId, $p);
                return ['atualizada' => $this->chat->encontrar($mensagemId), 'resposta' => $this->salvar(RespostaChat::desfeito($r->mensagem))];
            }

            if (!in_array($tipo, ['escolha', 'criar_ref', 'confirmar'], true)) {
                throw new InvalidArgumentException('Esta mensagem não tem ação pendente.');
            }
            if (($p['estado'] ?? '') !== 'aberta') {
                throw new InvalidArgumentException('Esta pergunta já foi respondida.');
            }
            $estado = (array) ($p['pendente'] ?? []);

            if ($acao === 'cancelar') {
                $resposta = RespostaChat::texto('Cancelado. Nada foi alterado.');
                $p['estado'] = 'cancelada';
            } elseif ($acao === 'escolher' && $tipo === 'escolha') {
                $escolhida = $p['opcoes'][$opcao ?? -1]['id'] ?? null;
                if ($escolhida === null) {
                    throw new InvalidArgumentException('Opção inválida.');
                }
                $p['estado'] = 'resolvida';
                $p['escolhida'] = $escolhida;
                $this->chat->atualizarPayload($mensagemId, $p);
                $resposta = $this->acoes->escolher($estado, (string) $p['ref'], (int) $escolhida);
            } elseif ($acao === 'criar' && $tipo === 'criar_ref') {
                $p['estado'] = 'resolvida';
                $this->chat->atualizarPayload($mensagemId, $p);
                $resposta = $this->acoes->criarReferencia($estado, (string) $p['ref'], (string) $p['termo']);
            } elseif ($acao === 'confirmar' && $tipo === 'confirmar') {
                $p['estado'] = 'confirmada';
                $this->chat->atualizarPayload($mensagemId, $p);
                $resposta = $this->acoes->avancar(['confirmado' => true] + $estado);
            } else {
                throw new InvalidArgumentException('Ação inválida para esta mensagem.');
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (IaErro $e) {
            $resposta = RespostaChat::erro($e->getMessage());
        } catch (Throwable $e) {
            error_log((string) $e);
            $resposta = RespostaChat::erro('Erro interno ao processar o pedido. Confira a auditoria antes de repetir.');
        }

        $this->chat->atualizarPayload($mensagemId, $p);
        return ['atualizada' => $this->chat->encontrar($mensagemId), 'resposta' => $this->salvar($resposta)];
    }

    public function historico(int $limite = 40): array
    {
        return $this->chat->recentes($limite);
    }

    /** "@slug [registro ou texto]" (agente) ou "#slug ..." (squad): só ele é acionado (SPEC §3.1); o registro é resolvido pelo servidor. */
    private function agenteDireto(string $mensagem, ?array $tela, ?array $ultimaRef, string $tipo = 'agente'): array
    {
        $sigla = $tipo === 'squad' ? '#' : '@';
        if (preg_match('/^' . $sigla . '([a-z0-9][a-z0-9-]*)(?:\s+(.+))?$/su', $mensagem, $m) !== 1) {
            return RespostaChat::erro("Use {$sigla}slug [nome do registro]. Digite " . ($tipo === 'squad' ? '/squads' : '/agentes') . ' para ver os disponíveis.');
        }
        $texto = trim($m[2] ?? '');
        return $this->acoes->avancar([
            'plano' => [
                'tipo' => $tipo, 'slug' => $m[1], 'alvo' => $texto !== '' ? ['nome' => $texto] : [], 'entrada' => null,
                'texto' => $texto !== '' ? $texto : null, 'texto_livre' => true,
            ],
            'resolvidas' => [], 'criadas' => [], 'confirmado' => false, 'execucao_id' => null, 'tela' => $tela, 'ultima_ref' => $ultimaRef,
        ]);
    }

    private function linguagemNatural(string $mensagem, ?array $tela, ?array $ultimaRef): array
    {
        $r = $this->router->rotear($mensagem, $tela, $ultimaRef);
        if (!$r['ok']) {
            return RespostaChat::naoEntendi();
        }
        return $this->acoes->avancar([
            'plano' => $r['plano'], 'resolvidas' => [], 'criadas' => [], 'confirmado' => false,
            'execucao_id' => $r['execucao_id'], 'tela' => $tela, 'ultima_ref' => $ultimaRef,
        ]);
    }

    /** Grava a resposta em chat_mensagens e devolve a linha. */
    private function salvar(array $resposta): array
    {
        $id = $this->chat->inserir(
            'sistema',
            $resposta['conteudo'],
            $resposta['payload'],
            ($resposta['ultima_ref'] ?? null) !== null ? ['ultima_ref' => $resposta['ultima_ref']] : null,
        );
        return $this->chat->encontrar($id);
    }
}
