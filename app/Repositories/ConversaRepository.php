<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela conversas (Fase 14): uma por canal e pessoa. Só SQL; escritas de negócio passam pelo ActionExecutor. */
final class ConversaRepository
{
    private const SELECT = "SELECT c.*, TRIM(ct.nome || ' ' || COALESCE(ct.sobrenome, '')) AS contato_nome, e.nome_fantasia AS empresa_nome
        FROM conversas c LEFT JOIN contatos ct ON ct.id = c.contato_id LEFT JOIN empresas e ON e.id = c.empresa_id";

    public function inserir(array $dados): int
    {
        $cols = array_keys($dados);
        DB::conexao()->prepare('INSERT INTO conversas (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($dados);
        return (int) DB::conexao()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($dados)));
        DB::conexao()->prepare("UPDATE conversas SET {$sets} WHERE id = :__id")->execute($dados + ['__id' => $id]);
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare(self::SELECT . ' WHERE c.id = :id AND c.arquivado_em IS NULL');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    public function porIdentificador(string $canal, string $identificador): ?array
    {
        $st = DB::conexao()->prepare(self::SELECT . ' WHERE c.canal = :c AND c.identificador = :i');
        $st->execute(['c' => $canal, 'i' => $identificador]);
        return $st->fetch() ?: null;
    }

    /** Incrementa/zera o contador de não lidas de forma atômica. */
    public function somarNaoLidas(int $id, int $delta): void
    {
        DB::conexao()->prepare('UPDATE conversas SET nao_lidas = MAX(0, nao_lidas + :d) WHERE id = :id')->execute(['d' => $delta, 'id' => $id]);
    }

    /**
     * Lista paginada (mais recente primeiro). Filtros: canal, situacao (abertas | resolvidas | todas), nao_lidas (bool), q (nome, identificador, contato, empresa, prévia), contato_id, empresa_id.
     * @return array{linhas:list<array>,total:int,pagina:int,por_pagina:int,paginas:int}
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 25): array
    {
        $onde = ['c.arquivado_em IS NULL'];
        $params = [];
        if (in_array($filtros['canal'] ?? '', ['whatsapp', 'instagram', 'email'], true)) {
            $onde[] = 'c.canal = :canal';
            $params['canal'] = $filtros['canal'];
        }
        $situacao = $filtros['situacao'] ?? 'abertas';
        if ($situacao === 'abertas' || $situacao === 'resolvidas') {
            $onde[] = 'c.status = :status';
            $params['status'] = $situacao === 'abertas' ? 'aberta' : 'resolvida';
        }
        if (!empty($filtros['nao_lidas'])) {
            $onde[] = 'c.nao_lidas > 0';
        }
        foreach (['contato_id', 'empresa_id'] as $k) {
            if (!empty($filtros[$k])) {
                $onde[] = "c.{$k} = :{$k}";
                $params[$k] = (int) $filtros[$k];
            }
        }
        $q = trim((string) ($filtros['q'] ?? ''));
        if ($q !== '') {
            $onde[] = "(busca_norm(COALESCE(c.nome, '') || ' ' || c.identificador || ' ' || COALESCE(ct.nome, '') || ' ' || COALESCE(e.nome_fantasia, '') || ' ' || COALESCE(c.ultima_previa, '')) LIKE :q ESCAPE '\\')";
            $params['q'] = '%' . addcslashes(normalizar_busca($q), '%_\\') . '%';
        }
        $where = implode(' AND ', $onde);

        $st = DB::conexao()->prepare('SELECT COUNT(*) FROM conversas c LEFT JOIN contatos ct ON ct.id = c.contato_id LEFT JOIN empresas e ON e.id = c.empresa_id WHERE ' . $where);
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($paginas, $pagina));
        $st = DB::conexao()->prepare(self::SELECT . " WHERE {$where} ORDER BY (c.nao_lidas > 0) DESC, c.ultima_mensagem_em DESC, c.id DESC LIMIT " . (int) $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina));
        $st->execute($params);
        return ['linhas' => $st->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => $paginas];
    }

    /** Conversas abertas com mensagens não lidas (selo do menu). */
    public function contarComNaoLidas(): int
    {
        return (int) DB::conexao()->query("SELECT COUNT(*) FROM conversas WHERE arquivado_em IS NULL AND status = 'aberta' AND nao_lidas > 0")->fetchColumn();
    }

    /** @return list<array> conversas de um contato ou de uma empresa */
    public function doRegistro(string $campo, int $id, int $limite = 20): array
    {
        if (!in_array($campo, ['contato_id', 'empresa_id'], true)) {
            throw new \InvalidArgumentException("Campo inválido: {$campo}");
        }
        $st = DB::conexao()->prepare(self::SELECT . " WHERE c.{$campo} = :id AND c.arquivado_em IS NULL ORDER BY c.ultima_mensagem_em DESC LIMIT " . (int) $limite);
        $st->execute(['id' => $id]);
        return $st->fetchAll();
    }
}
