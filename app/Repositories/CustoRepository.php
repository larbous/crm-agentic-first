<?php

declare(strict_types=1);

namespace App\Repositories;

/** Custos (Fase 17): lançamentos manuais vinculados a empresa e/ou negócio, usados no DRE. Só SQL. */
final class CustoRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'custos';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, e.nome_fantasia AS empresa_nome, n.titulo AS negocio_titulo
                FROM custos a
                LEFT JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN negocios n ON n.id = a.negocio_id";
    }

    protected function ordenaveis(): array
    {
        return ['descricao' => 'a.descricao COLLATE pt_br', 'empresa' => 'e.nome_fantasia COLLATE pt_br', 'valor' => 'a.valor', 'data' => 'a.data', 'categoria' => 'a.categoria COLLATE pt_br'];
    }

    protected function filtraveis(): array
    {
        return ['empresa_id' => 'a.empresa_id', 'negocio_id' => 'a.negocio_id', 'categoria' => 'a.categoria', 'recorrente' => 'a.recorrente'];
    }

    protected function buscaveis(): array
    {
        return ['a.descricao', 'a.categoria', 'e.nome_fantasia'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.data DESC, a.id DESC';
    }

    public function daEmpresa(int $empresaId, int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        $st = $this->pdo()->prepare($this->selectBase() . " WHERE a.empresa_id = :id AND a.arquivado_em IS NULL ORDER BY a.data DESC, a.id DESC LIMIT {$limite}");
        $st->execute(['id' => $empresaId]);
        return $st->fetchAll();
    }

    /** Soma de custos por empresa no período [de, ate] (ISO, inclusive). Custos sem empresa (só negócio) não entram no DRE por cliente. Para o DRE. @return array<int,int> */
    public function porEmpresaNoPeriodo(string $de, string $ate): array
    {
        $st = $this->pdo()->prepare(
            'SELECT empresa_id, COALESCE(SUM(valor), 0) AS total FROM custos
             WHERE arquivado_em IS NULL AND empresa_id IS NOT NULL AND data BETWEEN :de AND :ate
             GROUP BY empresa_id'
        );
        $st->execute(['de' => $de, 'ate' => $ate]);
        return array_map('intval', array_column($st->fetchAll(), 'total', 'empresa_id'));
    }
}
