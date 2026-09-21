<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FormularioRepository;
use App\Repositories\PesquisaRepository;
use App\Repositories\Repositorios;
use PDOException;

/**
 * Pesquisas de satisfação / NPS (Fase 12) pelo ActionExecutor: criar o envio individual (link com token), receber a
 * resposta pública, cancelar, marcar como entregue e expirar. Como as submissões de formulário, a linha de `pesquisas`
 * é o próprio registro (sem log_auditoria); o que aparece na timeline da empresa e as tarefas geradas passam pelo
 * caminho normal de escrita, e os eventos `pesquisa.criada` / `pesquisa.respondida` alimentam agentes e squads.
 */
trait AcoesPesquisas
{
    /**
     * Cria uma pesquisa para a empresa, com link individual. Automática (origem "sistema", gatilho ≠ manual) também abre
     * uma tarefa "Enviar pesquisa" com o link, porque o CRM ainda não entrega mensagens (Fase 14).
     * @param array $formulario linha de FormularioRepository (tipo "pesquisa", ativo)
     */
    public function criarPesquisa(array $formulario, int $empresaId, ?int $contatoId = null, ?int $contratoId = null, string $gatilho = 'manual', string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        if (($formulario['tipo'] ?? '') !== 'pesquisa' || (int) ($formulario['ativo'] ?? 0) !== 1) {
            return Resultado::erroGeral('Escolha uma pesquisa ativa.');
        }
        $empresa = Repositorios::empresas()->encontrar($empresaId);
        if ($empresa === null) {
            return Resultado::erroGeral('Empresa não encontrada.');
        }
        $repo = new PesquisaRepository();

        return $this->transacao(function () use ($formulario, $empresa, $contatoId, $contratoId, $gatilho, $origem, $repo): Resultado {
            $contato = $repo->contatoParaPesquisa((int) $empresa['id'], $contatoId);
            do {
                $token = bin2hex(random_bytes(20));
            } while ($repo->tokenExiste($token));
            $agora = agora();
            try {
                $id = $repo->inserir([
                    'formulario_id' => (int) $formulario['id'], 'token' => $token, 'empresa_id' => (int) $empresa['id'], 'contato_id' => $contato,
                    'contrato_id' => $contratoId, 'gatilho' => $gatilho, 'status' => 'pendente',
                    'expira_em' => date('Y-m-d H:i:s', strtotime('+' . max(1, (int) $formulario['validade_dias']) . ' days')),
                    'criado_em' => $agora, 'atualizado_em' => $agora, 'criado_por' => $origem,
                ]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    return Resultado::erroGeral('Este contrato já tem uma pesquisa deste modelo.');
                }
                throw $e;
            }
            $pesquisa = $repo->encontrar($id);
            $link = url_publica('/nps/' . $token);

            $this->criar('atividades', [
                'tipo' => 'sistema', 'assunto' => 'Pesquisa NPS criada: ' . $formulario['nome'],
                'descricao' => 'Link individual válido até ' . data_br(substr((string) $pesquisa['expira_em'], 0, 10)) . '.',
                'empresa_id' => (int) $empresa['id'], 'contato_id' => $contato,
            ], 'sistema');

            if ($gatilho !== 'manual') {
                $quem = trim((string) ($pesquisa['contato_nome'] ?? '')) !== '' ? $pesquisa['contato_nome'] . ' (' . $empresa['nome_fantasia'] . ')' : (string) $empresa['nome_fantasia'];
                $this->criar('tarefas', [
                    'titulo' => 'Enviar pesquisa NPS: ' . mb_substr((string) $empresa['nome_fantasia'], 0, 120),
                    'descricao' => "Enviar a {$quem} o link da pesquisa \"{$formulario['nome']}\" (WhatsApp ou e-mail) e marcar como enviada em Pesquisas NPS.\n\n{$link}",
                    'tipo' => 'enviar', 'prioridade' => 'media', 'vencimento' => hoje(),
                    'empresa_id' => (int) $empresa['id'], 'contato_id' => $contato,
                ], 'sistema');
            }

            $this->enfileirar('pesquisa.criada', [
                'evento' => 'pesquisa.criada', 'entidade' => 'empresas', 'id' => (int) $empresa['id'], 'origem' => $origem,
                'registro' => $empresa, 'pesquisa_id' => $id, 'formulario_id' => (int) $formulario['id'], 'gatilho' => $gatilho,
            ]);
            return Resultado::sucesso($id, 'Pesquisa criada. Envie o link ao cliente.', $pesquisa);
        });
    }

    /**
     * Grava a resposta do link público. `$entrada` traz os valores por `c<id do campo>` (como nos formulários de captação).
     * Falha com erros por campo, ou com `_` (já respondida, expirada, cancelada). Só uma resposta por link.
     * @param array $meta ip
     */
    public function responderPesquisa(string $token, array $entrada, array $meta = []): Resultado
    {
        $repo = new PesquisaRepository();
        $pesquisa = $repo->porToken($token);
        if ($pesquisa === null) {
            return Resultado::falha(['_' => 'Pesquisa não encontrada.']);
        }
        if ($pesquisa['status'] === 'pendente' && strtotime((string) $pesquisa['expira_em']) < time()) {
            $this->expirarPesquisa((int) $pesquisa['id']);
            $pesquisa['status'] = 'expirada';
        }
        if ($pesquisa['status'] !== 'pendente') {
            return Resultado::falha(['_' => match ($pesquisa['status']) {
                'respondida' => 'Esta pesquisa já foi respondida. Obrigado!',
                'expirada' => 'O prazo para responder esta pesquisa terminou.',
                default => 'Esta pesquisa não está mais disponível.',
            }]);
        }

        $formularioAtivo = (new FormularioRepository())->encontrar((int) $pesquisa['formulario_id']);
        if ($formularioAtivo === null || (int) $formularioAtivo['ativo'] !== 1) {
            return Resultado::falha(['_' => 'Esta pesquisa não está mais disponível.']);
        }
        $campos = (new FormularioRepository())->campos((int) $pesquisa['formulario_id']);
        [$nota, $comentario, $respostas, $erros] = $this->lerRespostaPesquisa($campos, $entrada);
        if ($erros !== []) {
            return Resultado::falha($erros);
        }
        $categoria = Nps::categoria((int) $nota);
        $origem = 'formulario:' . (int) $pesquisa['formulario_id'];

        return $this->transacao(function () use ($repo, $pesquisa, $nota, $comentario, $respostas, $categoria, $origem, $meta): Resultado {
            $agora = agora();
            $gravou = $repo->registrarResposta((int) $pesquisa['id'], [
                'status' => 'respondida', 'nota' => $nota, 'categoria' => $categoria, 'comentario' => $comentario,
                'respostas' => $respostas === [] ? null : json_encode($respostas, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'respondida_em' => $agora, 'atualizado_em' => $agora, 'ip' => mb_substr((string) ($meta['ip'] ?? ''), 0, 45) ?: null,
            ]);
            if (!$gravou) {
                return Resultado::falha(['_' => 'Esta pesquisa já foi respondida. Obrigado!']);
            }
            $empresaId = (int) $pesquisa['empresa_id'];
            $rotulo = Nps::CATEGORIAS[$categoria];
            $this->criar('atividades', [
                'tipo' => 'sistema', 'assunto' => "Pesquisa NPS respondida: nota {$nota} ({$rotulo})",
                'descricao' => trim(($comentario !== null ? "Comentário: {$comentario}" : '') . "\n" . $this->resumoRespostas($respostas)),
                'empresa_id' => $empresaId, 'contato_id' => $pesquisa['contato_id'] !== null ? (int) $pesquisa['contato_id'] : null,
            ], 'sistema');

            $formulario = (new FormularioRepository())->encontrar((int) $pesquisa['formulario_id']);
            if ($categoria === 'detrator' && $formulario !== null && (int) $formulario['tarefa_detrator'] === 1) {
                $this->criar('tarefas', [
                    'titulo' => 'Ligar para ' . mb_substr((string) $pesquisa['empresa_nome'], 0, 120) . ": nota {$nota} no NPS",
                    'descricao' => "Cliente detrator na pesquisa \"{$pesquisa['formulario_nome']}\".\n" . ($comentario !== null ? "Comentário: {$comentario}" : 'Sem comentário.'),
                    'tipo' => 'ligar', 'prioridade' => 'alta', 'vencimento' => hoje(),
                    'empresa_id' => $empresaId, 'contato_id' => $pesquisa['contato_id'] !== null ? (int) $pesquisa['contato_id'] : null,
                ], 'sistema');
            }

            $this->enfileirar('pesquisa.respondida', [
                'evento' => 'pesquisa.respondida', 'entidade' => 'empresas', 'id' => $empresaId, 'origem' => $origem,
                'registro' => Repositorios::empresas()->encontrar($empresaId) ?? [], 'pesquisa_id' => (int) $pesquisa['id'],
                'formulario_id' => (int) $pesquisa['formulario_id'], 'nota' => $nota, 'categoria' => $categoria,
            ]);
            return Resultado::sucesso((int) $pesquisa['id'], (string) ($formulario['mensagem_sucesso'] ?? 'Obrigado pela sua resposta!'), ['nota' => $nota, 'categoria' => $categoria]);
        });
    }

    /** Cancela uma pesquisa ainda pendente (o link deixa de funcionar). */
    public function cancelarPesquisa(int $id): Resultado
    {
        $repo = new PesquisaRepository();
        $p = $repo->encontrar($id);
        if ($p === null) {
            return Resultado::erroGeral('Pesquisa não encontrada.');
        }
        if ($p['status'] !== 'pendente') {
            return Resultado::erroGeral('Só é possível cancelar uma pesquisa pendente.');
        }
        $repo->atualizar($id, ['status' => 'cancelada', 'atualizado_em' => agora()]);
        return Resultado::sucesso($id, 'Pesquisa cancelada. O link deixou de funcionar.');
    }

    /** Registra que o operador entregou o link ao cliente (tira a pesquisa da fila "a enviar"). */
    public function marcarPesquisaEnviada(int $id): Resultado
    {
        $repo = new PesquisaRepository();
        $p = $repo->encontrar($id);
        if ($p === null) {
            return Resultado::erroGeral('Pesquisa não encontrada.');
        }
        if ($p['enviada_em'] === null) {
            $repo->atualizar($id, ['enviada_em' => agora(), 'atualizado_em' => agora()]);
        }
        return Resultado::sucesso($id, 'Marcada como enviada.');
    }

    /** Pendente com o prazo vencido → expirada (worker). */
    public function expirarPesquisa(int $id): Resultado
    {
        $repo = new PesquisaRepository();
        $p = $repo->encontrar($id);
        if ($p === null || $p['status'] !== 'pendente') {
            return Resultado::sucesso($id, 'Nada a alterar.');
        }
        $repo->atualizar($id, ['status' => 'expirada', 'atualizado_em' => agora()]);
        return Resultado::sucesso($id, 'Pesquisa expirada.');
    }

    // ---- Apoio ----------------------------------------------------------------------------

    /**
     * Lê e valida a resposta: nota inteira de 0 a 10 (obrigatória), comentário e perguntas extras.
     * @param list<array> $campos campos do formulário (FormularioRepository::campos)
     * @return array{0:?int,1:?string,2:list<array{rotulo:string,valor:string}>,3:array<string,string>}
     */
    private function lerRespostaPesquisa(array $campos, array $entrada): array
    {
        $erros = [];
        $nota = null;
        $comentario = null;
        $respostas = [];
        foreach ($campos as $c) {
            $nome = 'c' . $c['id'];
            $bruto = $entrada[$nome] ?? '';
            $valor = is_string($bruto) ? trim($bruto) : '';
            $obrigatorio = (int) $c['obrigatorio'] === 1;
            $destino = (string) $c['campo_destino'];

            if ($destino === 'pesquisa.nota') {
                if ($valor === '' || !ctype_digit($valor) || (int) $valor > 10) {
                    $erros[$nome] = 'Escolha uma nota de 0 a 10.';
                } else {
                    $nota = (int) $valor;
                }
                continue;
            }
            if ($c['tipo'] === 'checkbox') {
                $valor = in_array($bruto, ['1', 1, true, 'on'], true) ? 'Sim' : '';
                if ($obrigatorio && $valor === '') {
                    $erros[$nome] = 'Marque esta opção para continuar.';
                }
            } elseif ($valor === '') {
                if ($obrigatorio) {
                    $erros[$nome] = 'Este campo é obrigatório.';
                }
                continue;
            } elseif ($c['tipo'] === 'select' && !in_array($valor, (array) $c['opcoes'], true)) {
                $erros[$nome] = 'Escolha uma das opções.';
                continue;
            } elseif ($c['tipo'] === 'numero' && !is_numeric(str_replace(',', '.', $valor))) {
                $erros[$nome] = 'Informe um número.';
                continue;
            }
            $valor = mb_substr($valor, 0, 2000);
            if ($destino === 'pesquisa.comentario') {
                $comentario = $valor !== '' ? $valor : null;
            } elseif ($valor !== '') {
                $respostas[] = ['rotulo' => (string) $c['rotulo'], 'valor' => $valor];
            }
        }
        if ($nota === null && $erros === []) {
            $erros['_'] = 'A pesquisa não tem o campo de nota.';
        }
        return [$nota, $comentario, $respostas, $erros];
    }

    /** @param list<array{rotulo:string,valor:string}> $respostas */
    private function resumoRespostas(array $respostas): string
    {
        return implode("\n", array_map(static fn (array $r): string => "{$r['rotulo']}: {$r['valor']}", $respostas));
    }
}
