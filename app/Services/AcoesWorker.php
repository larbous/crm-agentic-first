<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Repositorios;

/**
 * Mudanças de estado feitas pelo worker (SPEC §11): proposta que expira e contrato que vence. Passam pelo mesmo
 * caminho de qualquer escrita (validação, auditoria com origem "sistema", atividade na timeline e evento).
 */
trait AcoesWorker
{
    /** Enviada/visualizada com a validade vencida → expirada. */
    public function expirarProposta(int $id, string $origem = 'sistema'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $p = Repositorios::propostas()->encontrar($id);
            if ($p === null) {
                return $this->documentoNaoEncontrado('Proposta');
            }
            if (!proposta_expirada($p)) {
                return Resultado::sucesso($id, 'Nada a alterar.', $p);
            }
            [$novo, $logId] = $this->gravarCampos('propostas', $p, ['status' => 'expirada'], $origem, 'expirar_proposta');
            $this->atividadeDocumento('proposta', "Proposta {$p['numero']} expirou", 'Validade: ' . data_br($p['validade']), $p);
            return Resultado::sucesso($id, "Proposta {$p['numero']} expirada.", $novo, $logId);
        });
    }

    /** Contrato assinado/ativo com a data de fim passada → vencido (dispara `contrato.vencido`). */
    public function vencerContrato(int $id, string $origem = 'sistema'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $c = Repositorios::contratos()->encontrar($id);
            if ($c === null) {
                return $this->documentoNaoEncontrado('Contrato');
            }
            if (!in_array($c['status'], ['assinado', 'ativo'], true) || $c['data_fim'] === null || $c['data_fim'] >= hoje()) {
                return Resultado::sucesso($id, 'Nada a alterar.', $c);
            }
            [$novo, $logId] = $this->gravarCampos('contratos', $c, ['status' => 'vencido'], $origem, 'vencer_contrato');
            $this->atividadeDocumento('contrato', "Contrato {$c['numero']} venceu", 'Fim da vigência: ' . data_br($c['data_fim']), $c);
            $this->enfileirar('contrato.vencido', ['entidade' => 'contratos', 'id' => $id, 'origem' => $origem, 'registro' => $novo]);
            return Resultado::sucesso($id, "Contrato {$c['numero']} vencido.", $novo, $logId);
        });
    }
}
