<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgenteRepository;
use App\Services\AI\AgenteDefinicao;

/**
 * Escrita de agentes (definições em `agentes`/`agentes_versoes`) pelo ActionExecutor. São configuração do sistema,
 * como `configuracoes`: não vão para log_auditoria (o histórico é a tabela de versões) e não são desfeitas por lá.
 */
trait AcoesAgentes
{
    /**
     * Salva a definição de um agente. Sem $agenteId (novo ou importação): slug novo cria a versão 1 (ou a versão do
     * arquivo); slug existente vira uma nova versão desse agente. Com $agenteId (edição): o slug não pode mudar.
     * @param string|array $definicao JSON ou array de um .agent.json
     */
    public function salvarAgente(string|array $definicao, ?int $agenteId = null): Resultado
    {
        $v = AgenteDefinicao::validar($definicao);
        if (!$v['ok']) {
            $erros = [];
            foreach ($v['erros'] as $i => $mensagem) {
                $erros['definicao_' . ($i + 1)] = $mensagem;
            }
            return Resultado::falha($erros);
        }
        $nova = $v['definicao'];
        $repo = new AgenteRepository();

        $r = $this->transacao(function () use ($repo, $nova, $agenteId): Resultado {
            $existente = $agenteId !== null ? $repo->encontrar($agenteId) : $repo->porSlug($nova['slug']);
            if ($agenteId !== null && $existente === null) {
                return Resultado::erroGeral('Agente não encontrado.');
            }
            if ($existente !== null && $existente['slug'] !== $nova['slug']) {
                return Resultado::erroGeral('O slug de um agente não pode ser alterado. Para usar outro slug, importe como um novo agente.');
            }

            if ($existente === null) {
                $id = $repo->inserir($nova);
                return Resultado::sucesso($id, "Agente \"{$nova['nome']}\" criado (versão {$nova['versao']}).");
            }

            $atual = $existente['def'];
            $semVersao = static fn (array $d): array => array_diff_key($d, ['versao' => 1]);
            if (json_encode($semVersao($atual)) === json_encode($semVersao($nova))) {
                return Resultado::sucesso((int) $existente['id'], 'Nada a alterar.');
            }
            $nova['versao'] = (int) $existente['versao'] + 1;
            $repo->novaVersao((int) $existente['id'], $nova);
            return Resultado::sucesso((int) $existente['id'], "Agente \"{$nova['nome']}\" salvo (versão {$nova['versao']}).");
        });
        if ($r->ok) {
            Agendador::sincronizar();
        }
        return $r;
    }

    /** Volta a uma versão anterior: grava a definição antiga como uma nova versão (o histórico nunca é reescrito). */
    public function restaurarVersaoAgente(int $agenteId, int $versao): Resultado
    {
        $repo = new AgenteRepository();
        $antiga = $repo->versao($agenteId, $versao);
        if ($antiga === null) {
            return Resultado::erroGeral('Versão não encontrada.');
        }
        return $this->salvarAgente($antiga, $agenteId);
    }

    public function definirAgenteAtivo(int $agenteId, bool $ativo): Resultado
    {
        $repo = new AgenteRepository();
        $agente = $repo->encontrar($agenteId);
        if ($agente === null) {
            return Resultado::erroGeral('Agente não encontrado.');
        }
        $repo->definirAtivo($agenteId, $ativo);
        Agendador::sincronizar();
        return Resultado::sucesso($agenteId, 'Agente "' . $agente['nome'] . '" ' . ($ativo ? 'ativado.' : 'desativado.'));
    }
}
