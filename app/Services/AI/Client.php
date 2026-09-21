<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ExecucaoRepository;

/**
 * Único ponto de chamada à IA (cURL, sem SDK). Fala com um ou mais provedores (`Provedor`): tenta o primeiro da lista
 * `ia.provedores` (padrão "anthropic,gemini") e, se ele estiver instável (rede, timeout, 429/529/5xx depois de uma nova
 * tentativa), passa para o próximo dentro de um orçamento total de tempo. Um disjuntor por provedor evita pagar o timeout
 * a cada chamada durante uma queda. Erros de configuração (401/403) e de requisição (4xx) não trocam de provedor.
 * Cada chamada vira uma linha em `execucoes` (modelo, provedor, tokens, duração, status). Chaves vêm de config.local.php
 * e nunca são gravadas nem registradas.
 */
final class Client
{
    public const URL = ProvedorAnthropic::URL;
    public const VERSAO_API = ProvedorAnthropic::VERSAO_API;

    /** Ordem padrão dos provedores se `ia.provedores` não estiver em `configuracoes`. */
    public const PROVEDORES_PADRAO = 'anthropic,gemini';
    /** Orçamento total (segundos) de uma chamada, somando todas as tentativas; `ia.deadline_total` em `configuracoes` sobrescreve. */
    public const DEADLINE_PADRAO = 100;
    /** Sem pelo menos isto de orçamento restante, não vale abrir uma tentativa em outro provedor. */
    public const RESTANTE_MINIMO = 8;

    private readonly Disjuntor $disjuntor;

    public function __construct(?Disjuntor $disjuntor = null)
    {
        $this->disjuntor = $disjuntor ?? new Disjuntor();
    }

    /** Substitui o transporte HTTP da Anthropic (testes). O do Gemini é `ProvedorGemini::definirTransporte`. */
    public static function definirTransporte(?callable $transporte): void
    {
        ProvedorAnthropic::definirTransporte($transporte);
    }

    /** Há ao menos um provedor com credencial. */
    public function configurado(): bool
    {
        return $this->provedores() !== [];
    }

    /**
     * @param array $meta agente_id, squad_id, squad_execucao_id, etapa_ordem, entidade, registro_id, simulacao (opcionais);
     *                    execucao_id reaproveita uma linha já criada (execução tirada da fila do worker) em vez de criar outra
     * @param array $opcoes web_search (bool: liga a ferramenta de busca na web; só provedores que a suportam), timeout (segundos por tentativa),
     *                      temperatura (float; null omite o parâmetro — padrão 0, usado pelo roteador),
     *                      anexos (lista de ['mime' => string, 'base64' => string]: áudios; só provedores que os aceitam, hoje o Gemini)
     * @throws IaErro
     */
    public function chamar(string $modelo, string $sistema, string $usuario, array $meta = [], int $maxTokens = 1024, array $opcoes = []): RespostaIA
    {
        $execucoes = new ExecucaoRepository();
        $reaproveitar = isset($meta['execucao_id']) ? (int) $meta['execucao_id'] : null;
        unset($meta['execucao_id']);
        if ($reaproveitar !== null) {
            $execucaoId = $reaproveitar;
            $execucoes->atualizar($execucaoId, ['entrada' => $usuario, 'modelo' => $modelo, 'status' => 'rodando', 'iniciado_em' => agora()]);
        } else {
            $execucaoId = $execucoes->iniciar($meta + ['entrada' => $usuario, 'modelo' => $modelo, 'status' => 'rodando']);
        }
        $inicio = hrtime(true);
        $decorridoSeg = static fn (): int => (int) ((hrtime(true) - $inicio) / 1_000_000_000);
        $duracao = static fn (): int => (int) ((hrtime(true) - $inicio) / 1_000_000);
        $tentativas = 0;
        $falhar = static function (string $paraOperador, string $tecnico, ?string $provedor = null) use ($execucoes, $execucaoId, $duracao, &$tentativas): never {
            $execucoes->atualizar($execucaoId, [
                'status' => 'erro', 'erro' => mb_substr($tecnico, 0, 500), 'duracao_ms' => $duracao(), 'concluido_em' => agora(),
                'provedor' => $provedor, 'tentativas' => $tentativas > 0 ? $tentativas : null,
            ]);
            throw new IaErro($paraOperador);
        };

        $buscaWeb = !empty($opcoes['web_search']);
        $anexos = array_values((array) ($opcoes['anexos'] ?? []));
        $ordem = $this->ordenar($this->provedores($buscaWeb, $anexos !== []));
        if ($ordem === []) {
            $falhar(
                match (true) {
                    $anexos !== [] && $this->provedores() !== [] => 'Esta tarefa usa áudio, disponível só com o Gemini. Defina gemini.api_key em config.local.php.',
                    $buscaWeb && $this->provedores() !== [] => 'Esta tarefa usa busca na web, disponível só com a Anthropic. Defina anthropic.api_key em config.local.php.',
                    default => 'A IA não está configurada. Defina anthropic.api_key em config.local.php ou use os comandos com / (digite /ajuda).',
                },
                'api_key ausente',
            );
        }

        $timeoutBase = isset($opcoes['timeout']) ? (int) $opcoes['timeout'] : (int) Config::obter('anthropic.timeout', 30);
        $deadline = (int) ((new ConfiguracaoRepository())->obter('ia.deadline_total') ?: self::DEADLINE_PADRAO);
        $temperatura = array_key_exists('temperatura', $opcoes) ? $opcoes['temperatura'] : 0;
        $temperatura = $temperatura === null ? null : (float) $temperatura;

        $resposta = null;
        $usado = null;
        $modeloUsado = $modelo;
        $ultima = null;
        $ultimoProvedor = null;
        $falhasTecnicas = [];
        foreach ($ordem as $i => $provedor) {
            $restante = $deadline - $decorridoSeg();
            if ($i > 0 && $restante < self::RESTANTE_MINIMO) {
                break;
            }
            $req = new RequisicaoIA(
                $provedor->modeloPara($modelo), $sistema, $usuario, $maxTokens, $temperatura, $buscaWeb,
                $i === 0 ? $timeoutBase : max(5, min($timeoutBase, $restante)), $anexos,
            );
            $r = $provedor->enviar($req);
            $tentativas++;
            if ($r->repetivel()) {
                usleep(800_000);
                $r = $provedor->enviar($req);
                $tentativas++;
            }

            if ($r->ok()) {
                $this->disjuntor->registrarSucesso($provedor->nome());
                IaCreditos::limpar($provedor->nome());
                $resposta = $r;
                $usado = $provedor;
                $modeloUsado = $req->modelo;
                break;
            }
            $ultima = $r;
            $ultimoProvedor = $provedor->nome();
            $falhasTecnicas[] = $provedor->nome() . ': ' . $r->tecnico;
            if ($r->semCredito()) {
                IaCreditos::marcar($provedor->nome()); // alerta no topo das telas e tarefas dependentes suspensas
            }
            if (!$r->instavel()) {
                break; // configuração ou requisição inválida: outro provedor não resolve
            }
            $this->disjuntor->registrarFalha($provedor->nome(), $r->tecnico);
        }

        if ($resposta === null || $usado === null) {
            $falhar($ultima?->paraOperador((string) $ultimoProvedor) ?? 'A IA está indisponível no momento.', implode(' | ', $falhasTecnicas), $ultimoProvedor);
        }

        $ms = $duracao();
        $execucoes->atualizar($execucaoId, [
            'status' => 'concluida', 'saida' => $resposta->texto, 'modelo' => $modeloUsado, 'provedor' => $usado->nome(), 'tentativas' => $tentativas,
            'tokens_entrada' => $resposta->tokensEntrada, 'tokens_saida' => $resposta->tokensSaida, 'duracao_ms' => $ms, 'concluido_em' => agora(),
        ]);
        return new RespostaIA($resposta->texto, $execucaoId, $modeloUsado, $resposta->tokensEntrada, $resposta->tokensSaida, $ms, $resposta->parada, $usado->nome());
    }

    /**
     * Provedores com credencial, na ordem de `ia.provedores`.
     * @return list<Provedor>
     */
    private function provedores(bool $exigirBuscaWeb = false, bool $exigirAudio = false): array
    {
        $todos = ['anthropic' => new ProvedorAnthropic(), 'gemini' => new ProvedorGemini()];
        $lista = (string) ((new ConfiguracaoRepository())->obter('ia.provedores') ?: self::PROVEDORES_PADRAO);
        $saida = [];
        foreach (array_unique(array_map('trim', explode(',', $lista))) as $nome) {
            $p = $todos[$nome] ?? null;
            if ($p !== null && $p->configurado() && (!$exigirBuscaWeb || $p->suportaBuscaWeb()) && (!$exigirAudio || $p->suportaAudio())) {
                $saida[] = $p;
            }
        }
        return $saida;
    }

    /**
     * Provedores com disjuntor aberto ou sem crédito vão para o fim da fila: só são tentados se os outros falharem (última chance).
     * @param list<Provedor> $provedores
     * @return list<Provedor>
     */
    private function ordenar(array $provedores): array
    {
        $fora = fn (Provedor $p): bool => $this->disjuntor->aberto($p->nome()) || IaCreditos::bloqueado($p->nome());
        $saudaveis = array_values(array_filter($provedores, fn (Provedor $p): bool => !$fora($p)));
        $abertos = array_values(array_filter($provedores, $fora));
        return [...$saudaveis, ...$abertos];
    }
}
