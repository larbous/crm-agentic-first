<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela execucoes: uma linha por chamada de IA (modelo, tokens, duração, status). */
final class ExecucaoRepository
{
    /** Colunas aceitas em iniciar()/atualizar(). */
    private const COLUNAS = [
        'agente_id', 'squad_id', 'squad_execucao_id', 'etapa_ordem', 'entidade', 'registro_id', 'entrada', 'saida',
        'status', 'modelo', 'tokens_entrada', 'tokens_saida', 'duracao_ms', 'erro', 'concluido_em', 'simulacao',
    ];

    public function iniciar(array $dados): int
    {
        $dados = array_intersect_key($dados, array_flip(self::COLUNAS));
        $dados += ['status' => 'rodando', 'iniciado_em' => agora()];
        $cols = array_keys($dados);
        DB::conexao()->prepare('INSERT INTO execucoes (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($dados);
        return (int) DB::conexao()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $dados = array_intersect_key($dados, array_flip(self::COLUNAS));
        if ($dados === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($dados)));
        DB::conexao()->prepare("UPDATE execucoes SET {$sets} WHERE id = :__id")->execute($dados + ['__id' => $id]);
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM execucoes WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /**
     * Histórico paginado (mais recentes primeiro), com o nome do agente. Filtros: status, agente_id, tipo (agentes|roteador).
     * @return array{linhas:list<array>,total:int,pagina:int,por_pagina:int,paginas:int}
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 30): array
    {
        $onde = ['1 = 1'];
        $params = [];
        if (($filtros['status'] ?? '') !== '') {
            $onde[] = 'x.status = :status';
            $params['status'] = $filtros['status'];
        }
        if (($filtros['agente_id'] ?? '') !== '') {
            $onde[] = 'x.agente_id = :agente_id';
            $params['agente_id'] = (int) $filtros['agente_id'];
        }
        if (($filtros['tipo'] ?? '') === 'agentes') {
            $onde[] = 'x.agente_id IS NOT NULL';
        } elseif (($filtros['tipo'] ?? '') === 'roteador') {
            $onde[] = 'x.agente_id IS NULL AND x.squad_id IS NULL';
        }
        $where = implode(' AND ', $onde);

        $st = DB::conexao()->prepare("SELECT COUNT(*) FROM execucoes x WHERE {$where}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($paginas, $pagina));
        $st = DB::conexao()->prepare(
            "SELECT x.*, a.nome AS agente_nome, a.slug AS agente_slug,
                    (SELECT COUNT(*) FROM acoes_pendentes p WHERE p.execucao_id = x.id AND p.status = 'pendente') AS pendentes
             FROM execucoes x LEFT JOIN agentes a ON a.id = x.agente_id
             WHERE {$where} ORDER BY x.id DESC LIMIT {$porPagina} OFFSET " . (($pagina - 1) * $porPagina)
        );
        $st->execute($params);
        return ['linhas' => $st->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => $paginas];
    }

    /**
     * Tokens do mês por modelo. $mes: "AAAA-MM".
     * @return list<array{modelo:string,execucoes:int,tokens_entrada:int,tokens_saida:int}>
     */
    public function totaisDoMes(string $mes): array
    {
        $st = DB::conexao()->prepare(
            "SELECT COALESCE(modelo, '—') AS modelo, COUNT(*) AS execucoes,
                    COALESCE(SUM(tokens_entrada), 0) AS tokens_entrada, COALESCE(SUM(tokens_saida), 0) AS tokens_saida
             FROM execucoes WHERE substr(iniciado_em, 1, 7) = :mes GROUP BY modelo ORDER BY tokens_entrada + tokens_saida DESC"
        );
        $st->execute(['mes' => $mes]);
        return array_map(static fn (array $l): array => [
            'modelo' => (string) $l['modelo'], 'execucoes' => (int) $l['execucoes'],
            'tokens_entrada' => (int) $l['tokens_entrada'], 'tokens_saida' => (int) $l['tokens_saida'],
        ], $st->fetchAll());
    }

    /** Execuções de uma etapa ainda não decididas pelo operador (para retomar squads na Fase 6). */
    public function pendentesDaExecucao(int $execucaoId): int
    {
        $st = DB::conexao()->prepare("SELECT COUNT(*) FROM acoes_pendentes WHERE execucao_id = :id AND status = 'pendente'");
        $st->execute(['id' => $execucaoId]);
        return (int) $st->fetchColumn();
    }
}
