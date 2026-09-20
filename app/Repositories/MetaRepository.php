<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Metas comerciais (SPEC §4.11) e as consultas que calculam o realizado de cada tipo. O progresso não é gravado:
 * é lido dos registros na hora (ver App\Services\Metas para as definições de cada tipo).
 */
final class MetaRepository extends BaseRepository
{
    /** Status de contrato que já valeram como receita recorrente (o de hoje pode ter mudado depois do período). */
    private const STATUS_MRR = "('assinado', 'ativo', 'vencido', 'renovado')";

    public function tabela(): string
    {
        return 'metas';
    }

    protected function ordenaveis(): array
    {
        return ['tipo' => 'a.tipo', 'periodo' => 'a.periodo', 'valor_alvo' => 'a.valor_alvo', 'data_inicio' => 'a.data_inicio', 'data_fim' => 'a.data_fim'];
    }

    protected function filtraveis(): array
    {
        return ['tipo' => 'a.tipo', 'periodo' => 'a.periodo'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.data_inicio DESC, a.id DESC';
    }

    /** Filtro "vigencia": vigentes (hoje dentro do período), futuras ou encerradas. */
    protected function filtroEspecial(string $chave, mixed $valor, array &$params): ?string
    {
        if ($chave !== 'vigencia') {
            return null;
        }
        $condicao = match ($valor) {
            'vigentes'   => 'a.data_inicio <= :vig_hoje AND a.data_fim >= :vig_hoje',
            'futuras'    => 'a.data_inicio > :vig_hoje',
            'encerradas' => 'a.data_fim < :vig_hoje',
            default      => null,
        };
        if ($condicao !== null) {
            $params['vig_hoje'] = hoje();
        }
        return $condicao;
    }

    /** Metas cujo período contém a data, das que terminam antes para as que terminam depois. */
    public function vigentes(string $data): array
    {
        $st = $this->pdo()->prepare(
            'SELECT * FROM metas WHERE arquivado_em IS NULL AND data_inicio <= :d AND data_fim >= :d ORDER BY data_fim ASC, tipo ASC, id ASC'
        );
        $st->execute(['d' => $data]);
        return $st->fetchAll();
    }

    /** Faturamento: soma do valor fechado (centavos) dos negócios ganhos com fechamento no período. */
    public function faturamento(string $inicio, string $fim): int
    {
        return (int) $this->escalar(
            "SELECT COALESCE(SUM(valor_fechado), 0) FROM negocios
             WHERE arquivado_em IS NULL AND status = 'ganho' AND substr(data_fechamento, 1, 10) BETWEEN :i AND :f",
            ['i' => $inicio, 'f' => $fim],
        );
    }

    public function negociosGanhos(string $inicio, string $fim): int
    {
        return (int) $this->escalar(
            "SELECT COUNT(*) FROM negocios
             WHERE arquivado_em IS NULL AND status = 'ganho' AND substr(data_fechamento, 1, 10) BETWEEN :i AND :f",
            ['i' => $inicio, 'f' => $fim],
        );
    }

    /** Empresas que viraram clientes no período (cliente_desde). */
    public function novosClientes(string $inicio, string $fim): int
    {
        return (int) $this->escalar(
            'SELECT COUNT(*) FROM empresas WHERE arquivado_em IS NULL AND substr(cliente_desde, 1, 10) BETWEEN :i AND :f',
            ['i' => $inicio, 'f' => $fim],
        );
    }

    /** Propostas (por número, então versões novas da mesma proposta não contam de novo) enviadas no período. */
    public function propostasEnviadas(string $inicio, string $fim): int
    {
        return (int) $this->escalar(
            'SELECT COUNT(DISTINCT numero) FROM propostas
             WHERE arquivado_em IS NULL AND enviada_em IS NOT NULL AND substr(enviada_em, 1, 10) BETWEEN :i AND :f',
            ['i' => $inicio, 'f' => $fim],
        );
    }

    /** MRR (centavos) na data: soma do valor mensal dos contratos cuja vigência cobre a data. Sem data de início, não conta. */
    public function mrrEm(string $data): int
    {
        return (int) $this->escalar(
            'SELECT COALESCE(SUM(valor_mensal), 0) FROM contratos
             WHERE arquivado_em IS NULL AND status IN ' . self::STATUS_MRR . '
               AND valor_mensal IS NOT NULL AND data_inicio IS NOT NULL AND data_inicio <= :d
               AND (data_fim IS NULL OR data_fim >= :d)',
            ['d' => $data],
        );
    }

    private function escalar(string $sql, array $params): mixed
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }
}
