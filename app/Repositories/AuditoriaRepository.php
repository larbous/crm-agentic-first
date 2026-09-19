<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;
use PDO;

/** Tabela log_auditoria (append-only; só desfeito_em é atualizado). */
final class AuditoriaRepository
{
    private function pdo(): PDO
    {
        return DB::conexao();
    }

    public function inserir(array $linha): int
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO log_auditoria (data, origem, entidade, registro_id, acao, antes, depois, execucao_id)
             VALUES (:data, :origem, :entidade, :registro_id, :acao, :antes, :depois, :execucao_id)'
        );
        $st->execute($linha);
        return (int) $this->pdo()->lastInsertId();
    }

    public function encontrar(int $id): ?array
    {
        $st = $this->pdo()->prepare('SELECT * FROM log_auditoria WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** Última entrada que pode ser desfeita (não desfeita e que não seja um "desfazer"). */
    public function ultimaDesfazivel(): ?array
    {
        $r = $this->pdo()->query(
            "SELECT * FROM log_auditoria WHERE desfeito_em IS NULL AND acao <> 'desfazer' ORDER BY id DESC LIMIT 1"
        )->fetch();
        return $r ?: null;
    }

    public function marcarDesfeito(int $id, string $quando): void
    {
        $this->pdo()->prepare('UPDATE log_auditoria SET desfeito_em = :q WHERE id = :id')->execute(['q' => $quando, 'id' => $id]);
    }

    /**
     * Listagem filtrável e paginada. Filtros: entidade, origem, acao, registro_id.
     * @return array{linhas:list<array>,total:int,pagina:int,por_pagina:int,paginas:int}
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $onde = ['1 = 1'];
        $params = [];
        foreach (['entidade', 'acao', 'registro_id'] as $campo) {
            if (($filtros[$campo] ?? '') !== '') {
                $onde[] = "{$campo} = :{$campo}";
                $params[$campo] = $filtros[$campo];
            }
        }
        if (($filtros['origem'] ?? '') !== '') {
            $onde[] = '(origem = :origem OR origem LIKE :origem_prefixo)';
            $params['origem'] = $filtros['origem'];
            $params['origem_prefixo'] = $filtros['origem'] . ':%';
        }
        $where = implode(' AND ', $onde);

        $st = $this->pdo()->prepare("SELECT COUNT(*) FROM log_auditoria WHERE {$where}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($paginas, $pagina));
        $st = $this->pdo()->prepare(
            "SELECT * FROM log_auditoria WHERE {$where} ORDER BY id DESC LIMIT {$porPagina} OFFSET " . (($pagina - 1) * $porPagina)
        );
        $st->execute($params);

        return ['linhas' => $st->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => $paginas];
    }
}
