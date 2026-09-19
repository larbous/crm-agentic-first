<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;

final class TarefaRepository extends BaseRepository
{
    private const ABERTAS = "a.status IN ('pendente', 'andamento')";

    public function tabela(): string
    {
        return 'tarefas';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, e.nome_fantasia AS empresa_nome, TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome,
                    n.titulo AS negocio_titulo
                FROM tarefas a
                LEFT JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN contatos c ON c.id = a.contato_id
                LEFT JOIN negocios n ON n.id = a.negocio_id";
    }

    protected function ordemPadrao(): string
    {
        return 'a.vencimento IS NULL, a.vencimento ASC, a.id DESC';
    }

    /**
     * Grupos da tela de tarefas: hoje | atrasadas | proximas | todas.
     * $hoje e $limite (hoje + 7 dias) em ISO (YYYY-MM-DD).
     */
    public function grupo(string $grupo, string $hoje, string $limite): array
    {
        $venc = 'substr(a.vencimento, 1, 10)';
        [$onde, $params] = match ($grupo) {
            'hoje'      => [self::ABERTAS . " AND {$venc} = :hoje", ['hoje' => $hoje]],
            'atrasadas' => [self::ABERTAS . " AND {$venc} < :hoje", ['hoje' => $hoje]],
            'proximas'  => [self::ABERTAS . " AND {$venc} > :hoje AND {$venc} <= :limite", ['hoje' => $hoje, 'limite' => $limite]],
            'todas'     => ['1 = 1', []],
            default     => throw new InvalidArgumentException("Grupo de tarefas inválido: {$grupo}"),
        };
        $ordem = $grupo === 'todas'
            ? 'CASE WHEN ' . self::ABERTAS . ' THEN 0 ELSE 1 END, a.vencimento IS NULL, a.vencimento ASC, a.id DESC'
            : 'a.vencimento ASC, a.id DESC';
        $st = $this->pdo()->prepare($this->selectBase() . " WHERE a.arquivado_em IS NULL AND {$onde} ORDER BY {$ordem} LIMIT 500");
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array{hoje:int,atrasadas:int,proximas:int,todas:int} */
    public function contagens(string $hoje, string $limite): array
    {
        $venc = 'substr(vencimento, 1, 10)';
        $ab = "status IN ('pendente', 'andamento') AND arquivado_em IS NULL";
        $st = $this->pdo()->prepare(
            "SELECT
               SUM(CASE WHEN {$ab} AND {$venc} = :hoje THEN 1 ELSE 0 END) AS hoje,
               SUM(CASE WHEN {$ab} AND {$venc} < :hoje THEN 1 ELSE 0 END) AS atrasadas,
               SUM(CASE WHEN {$ab} AND {$venc} > :hoje AND {$venc} <= :limite THEN 1 ELSE 0 END) AS proximas,
               SUM(CASE WHEN arquivado_em IS NULL THEN 1 ELSE 0 END) AS todas
             FROM tarefas"
        );
        $st->execute(['hoje' => $hoje, 'limite' => $limite]);
        $r = $st->fetch();
        return ['hoje' => (int) $r['hoje'], 'atrasadas' => (int) $r['atrasadas'], 'proximas' => (int) $r['proximas'], 'todas' => (int) $r['todas']];
    }

    /** Tarefas ligadas a um registro (empresa_id, contato_id ou negocio_id); abertas primeiro. */
    public function porVinculo(string $campo, int $id, int $limite = 50): array
    {
        if (!in_array($campo, ['empresa_id', 'contato_id', 'negocio_id'], true)) {
            throw new InvalidArgumentException("Campo de vínculo inválido: {$campo}");
        }
        $limite = max(1, min(200, $limite));
        $st = $this->pdo()->prepare(
            $this->selectBase() . " WHERE a.{$campo} = :id AND a.arquivado_em IS NULL
             ORDER BY CASE WHEN " . self::ABERTAS . " THEN 0 ELSE 1 END, a.vencimento IS NULL, a.vencimento ASC, a.id DESC LIMIT {$limite}"
        );
        $st->execute(['id' => $id]);
        return $st->fetchAll();
    }
}
