<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SquadRepository;
use App\Services\AI\SquadDefinicao;

/**
 * Escrita de squads (definições em `squads`/`squads_versoes`) pelo ActionExecutor. São configuração do sistema, como
 * agentes e `configuracoes`: não vão para log_auditoria (o histórico é a tabela de versões). Depois de salvar, os
 * agendamentos (gatilho "agendado") são sincronizados.
 */
trait AcoesSquads
{
    /**
     * Salva a definição de um squad. Sem $squadId (novo ou importação): slug novo cria a versão 1 (ou a versão do
     * arquivo); slug existente vira uma nova versão desse squad. Com $squadId (edição): o slug não pode mudar.
     * @param string|array $definicao JSON ou array de um .squad.json
     */
    public function salvarSquad(string|array $definicao, ?int $squadId = null): Resultado
    {
        $v = SquadDefinicao::validar($definicao);
        if (!$v['ok']) {
            $erros = [];
            foreach ($v['erros'] as $i => $mensagem) {
                $erros['definicao_' . ($i + 1)] = $mensagem;
            }
            return Resultado::falha($erros);
        }
        $nova = $v['definicao'];
        $repo = new SquadRepository();

        $r = $this->transacao(function () use ($repo, $nova, $squadId): Resultado {
            $existente = $squadId !== null ? $repo->encontrar($squadId) : $repo->porSlug($nova['slug']);
            if ($squadId !== null && $existente === null) {
                return Resultado::erroGeral('Squad não encontrado.');
            }
            if ($existente !== null && $existente['slug'] !== $nova['slug']) {
                return Resultado::erroGeral('O slug de um squad não pode ser alterado. Para usar outro slug, importe como um novo squad.');
            }

            if ($existente === null) {
                $id = $repo->inserir($nova);
                return Resultado::sucesso($id, "Squad \"{$nova['nome']}\" criado (versão {$nova['versao']}).");
            }

            $semVersao = static fn (array $d): array => array_diff_key($d, ['versao' => 1]);
            if (json_encode($semVersao($existente['def'])) === json_encode($semVersao($nova))) {
                return Resultado::sucesso((int) $existente['id'], 'Nada a alterar.');
            }
            $nova['versao'] = (int) $existente['versao'] + 1;
            $repo->novaVersao((int) $existente['id'], $nova);
            return Resultado::sucesso((int) $existente['id'], "Squad \"{$nova['nome']}\" salvo (versão {$nova['versao']}).");
        });
        if ($r->ok) {
            Agendador::sincronizar();
        }
        return $r;
    }

    /** Volta a uma versão anterior: grava a definição antiga como uma nova versão (o histórico nunca é reescrito). */
    public function restaurarVersaoSquad(int $squadId, int $versao): Resultado
    {
        $antiga = (new SquadRepository())->versao($squadId, $versao);
        if ($antiga === null) {
            return Resultado::erroGeral('Versão não encontrada.');
        }
        return $this->salvarSquad($antiga, $squadId);
    }

    public function definirSquadAtivo(int $squadId, bool $ativo): Resultado
    {
        $repo = new SquadRepository();
        $squad = $repo->encontrar($squadId);
        if ($squad === null) {
            return Resultado::erroGeral('Squad não encontrado.');
        }
        $repo->definirAtivo($squadId, $ativo);
        Agendador::sincronizar();
        return Resultado::sucesso($squadId, 'Squad "' . $squad['nome'] . '" ' . ($ativo ? 'ativado.' : 'desativado.'));
    }
}
