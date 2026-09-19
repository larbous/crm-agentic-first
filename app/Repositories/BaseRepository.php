<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;
use InvalidArgumentException;
use PDO;

/**
 * Base dos repositórios de entidades de negócio: único lugar com SQL, sempre com prepared statements.
 * Nomes de tabela vêm das subclasses (constantes) e nomes de coluna são validados contra o schema do banco.
 */
abstract class BaseRepository
{
    /** @var array<string,list<string>> */
    private static array $colunasCache = [];

    /** Alias da tabela principal nas consultas de listagem. */
    protected string $alias = 'a';

    abstract public function tabela(): string;

    protected function pdo(): PDO
    {
        return DB::conexao();
    }

    /** SELECT base da listagem (pode ter JOINs e colunas calculadas). Deve usar o alias {$this->alias}. */
    protected function selectBase(): string
    {
        return "SELECT {$this->alias}.* FROM {$this->tabela()} {$this->alias}";
    }

    /** chave de ordenação => expressão SQL */
    protected function ordenaveis(): array
    {
        return ['id' => "{$this->alias}.id"];
    }

    /** chave de filtro => expressão SQL (comparação de igualdade) */
    protected function filtraveis(): array
    {
        return [];
    }

    /** Expressões SQL pesquisadas por texto (sem acento/caixa). */
    protected function buscaveis(): array
    {
        return [];
    }

    /** Ordenação padrão quando nenhuma é pedida. */
    protected function ordemPadrao(): string
    {
        return "{$this->alias}.id DESC";
    }

    /** @return list<string> */
    public function colunas(): array
    {
        $t = $this->tabela();
        return self::$colunasCache[$t] ??= array_column($this->pdo()->query("PRAGMA table_info({$t})")->fetchAll(), 'name');
    }

    public function encontrar(int $id, bool $incluirArquivados = false): ?array
    {
        $sql = $this->selectBase() . " WHERE {$this->alias}.id = :id"
            . ($incluirArquivados ? '' : " AND {$this->alias}.arquivado_em IS NULL");
        $st = $this->pdo()->prepare($sql);
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** Existe e não está arquivado? */
    public function existe(int $id): bool
    {
        $st = $this->pdo()->prepare("SELECT 1 FROM {$this->tabela()} WHERE id = :id AND arquivado_em IS NULL");
        $st->execute(['id' => $id]);
        return $st->fetchColumn() !== false;
    }

    public function inserir(array $dados): int
    {
        $cols = $this->validarColunas(array_keys($dados));
        $sql = "INSERT INTO {$this->tabela()} (" . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')';
        $this->pdo()->prepare($sql)->execute($dados);
        return (int) $this->pdo()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        if ($dados === []) {
            return;
        }
        $cols = $this->validarColunas(array_keys($dados));
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", $cols));
        $this->pdo()->prepare("UPDATE {$this->tabela()} SET {$sets} WHERE id = :__id")->execute($dados + ['__id' => $id]);
    }

    /**
     * Listagem paginada.
     * $opts: busca (string), filtros [chave => valor], tag_id, ordem (chave), dir (asc|desc), pagina, por_pagina.
     * @return array{linhas:list<array>,total:int,pagina:int,por_pagina:int,paginas:int}
     */
    public function listar(array $opts = []): array
    {
        $onde = ["{$this->alias}.arquivado_em IS NULL"];
        $params = [];

        $busca = trim((string) ($opts['busca'] ?? ''));
        if ($busca !== '' && $this->buscaveis() !== []) {
            $ors = [];
            foreach ($this->buscaveis() as $i => $expr) {
                $ors[] = "busca_norm({$expr}) LIKE :busca ESCAPE '\\'";
            }
            $onde[] = '(' . implode(' OR ', $ors) . ')';
            $params['busca'] = '%' . addcslashes(normalizar_busca($busca), '%_\\') . '%';
        }

        $filtraveis = $this->filtraveis();
        foreach ((array) ($opts['filtros'] ?? []) as $chave => $valor) {
            if ($valor === null || $valor === '' || !isset($filtraveis[$chave])) {
                continue;
            }
            $onde[] = "{$filtraveis[$chave]} = :f_{$chave}";
            $params["f_{$chave}"] = $valor;
        }

        if (!empty($opts['tag_id'])) {
            $onde[] = "EXISTS (SELECT 1 FROM taggables tg WHERE tg.entidade = '{$this->tabela()}' "
                . "AND tg.registro_id = {$this->alias}.id AND tg.tag_id = :tag_id)";
            $params['tag_id'] = (int) $opts['tag_id'];
        }

        $where = ' WHERE ' . implode(' AND ', $onde);
        $ordenaveis = $this->ordenaveis();
        $chaveOrdem = (string) ($opts['ordem'] ?? '');
        $dir = strtolower((string) ($opts['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $orderBy = isset($ordenaveis[$chaveOrdem])
            ? "{$ordenaveis[$chaveOrdem]} {$dir}, {$this->alias}.id DESC"
            : $this->ordemPadrao();

        $porPagina = max(1, min(200, (int) ($opts['por_pagina'] ?? 25)));
        $sqlBase = $this->selectBase() . $where;

        $st = $this->pdo()->prepare("SELECT COUNT(*) FROM ({$sqlBase})");
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($paginas, (int) ($opts['pagina'] ?? 1)));
        $st = $this->pdo()->prepare("{$sqlBase} ORDER BY {$orderBy} LIMIT {$porPagina} OFFSET " . (($pagina - 1) * $porPagina));
        $st->execute($params);

        return ['linhas' => $st->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => $paginas];
    }

    /** Todas as linhas ativas (para selects e listas curtas). */
    public function todas(?string $ordem = null): array
    {
        $ordem = $ordem !== null && isset($this->ordenaveis()[$ordem]) ? $this->ordenaveis()[$ordem] : $this->ordemPadrao();
        return $this->pdo()->query($this->selectBase() . " WHERE {$this->alias}.arquivado_em IS NULL ORDER BY {$ordem}")->fetchAll();
    }

    /** Já existe registro ativo com o mesmo valor (comparação sem acento/caixa) na coluna, exceto $ignorarId? */
    public function valorDuplicado(string $coluna, string $valor, ?int $ignorarId = null, array $escopo = []): bool
    {
        $this->validarColunas([$coluna, ...array_keys($escopo)]);
        $sql = "SELECT 1 FROM {$this->tabela()} WHERE arquivado_em IS NULL AND busca_norm({$coluna}) = :v";
        $params = ['v' => normalizar_busca($valor)];
        if ($ignorarId !== null) {
            $sql .= ' AND id <> :ign';
            $params['ign'] = $ignorarId;
        }
        foreach ($escopo as $col => $val) {
            $sql .= " AND {$col} = :e_{$col}";
            $params["e_{$col}"] = $val;
        }
        $st = $this->pdo()->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        return $st->fetchColumn() !== false;
    }

    /** @param list<string> $cols */
    private function validarColunas(array $cols): array
    {
        $validas = $this->colunas();
        foreach ($cols as $c) {
            if (!in_array($c, $validas, true)) {
                throw new InvalidArgumentException("Coluna inválida em {$this->tabela()}: {$c}");
            }
        }
        return $cols;
    }
}
