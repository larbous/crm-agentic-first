<?php

declare(strict_types=1);

namespace App\Repositories;

/** Despesas da estrutura (contas a pagar do próprio negócio, sem cliente). Só SQL. */
final class DespesaRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'despesas';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, c.nome AS categoria_nome
                FROM despesas a
                LEFT JOIN categorias_despesa c ON c.id = a.categoria_id";
    }

    protected function ordenaveis(): array
    {
        return [
            'descricao' => 'a.descricao COLLATE pt_br', 'categoria' => 'c.nome COLLATE pt_br', 'fornecedor' => 'a.fornecedor COLLATE pt_br',
            'valor' => 'a.valor', 'vencimento' => 'a.vencimento', 'status' => 'a.status',
        ];
    }

    protected function filtraveis(): array
    {
        return ['status' => 'a.status', 'categoria_id' => 'a.categoria_id', 'meio_pagamento' => 'a.meio_pagamento', 'forma_pagamento' => 'a.forma_pagamento'];
    }

    protected function buscaveis(): array
    {
        return ['a.descricao', 'a.fornecedor', 'c.nome'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.vencimento DESC, a.id DESC';
    }

    /** Filtro "vencidas": a pagar com vencimento anterior a hoje. */
    protected function filtroEspecial(string $chave, mixed $valor, array &$params): ?string
    {
        if ($chave === 'situacao' && $valor === 'vencidas') {
            $params['hoje_venc'] = hoje();
            return "(a.status = 'pendente' AND a.vencimento < :hoje_venc)";
        }
        return null;
    }

    /**
     * Totais para o topo da lista: a pagar (pendentes), vencidas e pagas no mês [de, ate], em centavos.
     * @return array{a_pagar:int,vencidas:int,pago_no_mes:int}
     */
    public function resumo(string $hoje, string $de, string $ate): array
    {
        $st = $this->pdo()->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'pendente' THEN valor END), 0) AS a_pagar,
                COALESCE(SUM(CASE WHEN status = 'pendente' AND vencimento < :hoje THEN valor END), 0) AS vencidas,
                COALESCE(SUM(CASE WHEN status = 'pago' AND data_pagamento BETWEEN :de AND :ate THEN valor END), 0) AS pago_no_mes
             FROM despesas WHERE arquivado_em IS NULL"
        );
        $st->execute(['hoje' => $hoje, 'de' => $de, 'ate' => $ate]);
        return array_map('intval', $st->fetch());
    }
}
