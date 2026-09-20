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
        'status', 'modelo', 'tokens_entrada', 'tokens_saida', 'duracao_ms', 'erro', 'iniciado_em', 'concluido_em', 'simulacao',
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
        } elseif (($filtros['tipo'] ?? '') === 'squads') {
            $onde[] = 'x.squad_id IS NOT NULL AND x.squad_execucao_id IS NULL';
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
            "SELECT x.*, a.nome AS agente_nome, a.slug AS agente_slug, s.nome AS squad_nome,
                    (SELECT COUNT(*) FROM acoes_pendentes p WHERE p.execucao_id = x.id AND p.status = 'pendente') AS pendentes
             FROM execucoes x LEFT JOIN agentes a ON a.id = x.agente_id LEFT JOIN squads s ON s.id = x.squad_id
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
             FROM execucoes WHERE substr(iniciado_em, 1, 7) = :mes AND modelo IS NOT NULL GROUP BY modelo ORDER BY tokens_entrada + tokens_saida DESC"
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

    // ---- Fila do worker (SPEC §11) e squads (Fase 6) -----------------------------------------

    /** Põe um agente ou squad na fila; o worker o executa. Devolve o id da execução. */
    public function enfileirar(array $dados): int
    {
        return $this->iniciar($dados + ['status' => 'fila']);
    }

    /** Primeiras execuções na fila (agentes e squads; etapas de squad nunca ficam na fila). */
    public function daFila(int $limite): array
    {
        $st = DB::conexao()->prepare("SELECT * FROM execucoes WHERE status = 'fila' ORDER BY id LIMIT " . max(1, $limite));
        $st->execute();
        return $st->fetchAll();
    }

    /** Assume uma execução da fila (fila → rodando) de forma atômica. Falso = outro processo já a assumiu ou ela foi cancelada. */
    public function assumir(int $id): bool
    {
        $st = DB::conexao()->prepare("UPDATE execucoes SET status = 'rodando' WHERE id = :id AND status = 'fila'");
        $st->execute(['id' => $id]);
        return $st->rowCount() === 1;
    }

    /** Já existe execução do mesmo agente/squad para o mesmo registro na fila ou rodando? (aguardando aprovação não bloqueia) */
    public function emAndamento(string $tipo, int $donoId, ?string $entidade, ?int $registroId): bool
    {
        $coluna = $tipo === 'agente' ? 'agente_id' : 'squad_id';
        $st = DB::conexao()->prepare(
            "SELECT 1 FROM execucoes WHERE {$coluna} = :d AND squad_execucao_id IS NULL AND status IN ('fila', 'rodando')
               AND COALESCE(entidade, '') = :e AND COALESCE(registro_id, 0) = CAST(:r AS INTEGER) LIMIT 1"
        );
        $st->execute(['d' => $donoId, 'e' => $entidade ?? '', 'r' => $registroId ?? 0]);
        return $st->fetchColumn() !== false;
    }

    /** Quantas execuções (fora as etapas de squad) do agente/squad começaram desde o instante indicado. */
    public function iniciadasDesde(string $tipo, int $donoId, string $desde): int
    {
        $coluna = $tipo === 'agente' ? 'agente_id' : 'squad_id';
        $st = DB::conexao()->prepare("SELECT COUNT(*) FROM execucoes WHERE {$coluna} = :d AND squad_execucao_id IS NULL AND iniciado_em >= :s");
        $st->execute(['d' => $donoId, 's' => $desde]);
        return (int) $st->fetchColumn();
    }

    /**
     * Marca como erro o que ficou "rodando" sem ninguém rodando: cabeçalhos de squad (só o worker os executa, e ele
     * roda um por vez) e execuções mais antigas que $limite (processo morto no meio da chamada).
     */
    public function encerrarInterrompidas(string $limiteIso): int
    {
        $st = DB::conexao()->prepare(
            "UPDATE execucoes SET status = 'erro', erro = 'Interrompida antes de terminar (processo encerrado ou tempo esgotado).', concluido_em = :q
             WHERE status = 'rodando' AND ((squad_id IS NOT NULL AND squad_execucao_id IS NULL) OR iniciado_em < :lim)"
        );
        $st->execute(['q' => agora(), 'lim' => $limiteIso]);
        return $st->rowCount();
    }

    /** Uma etapa de squad foi decidida por completo: o cabeçalho que esperava a aprovação volta para a fila. */
    public function retomarSquadDaEtapa(int $etapaExecucaoId): void
    {
        DB::conexao()->prepare(
            "UPDATE execucoes SET status = 'fila'
             WHERE status = 'aguardando_aprovacao' AND squad_execucao_id IS NULL
               AND id = (SELECT squad_execucao_id FROM execucoes WHERE id = :id)"
        )->execute(['id' => $etapaExecucaoId]);
    }

    /** Cancela uma execução de squad que ainda não terminou nem está rodando. */
    public function cancelarSquad(int $id): bool
    {
        $st = DB::conexao()->prepare(
            "UPDATE execucoes SET status = 'cancelada', concluido_em = :q
             WHERE id = :id AND squad_id IS NOT NULL AND squad_execucao_id IS NULL AND status IN ('fila', 'aguardando_aprovacao')"
        );
        $st->execute(['q' => agora(), 'id' => $id]);
        return $st->rowCount() === 1;
    }

    /** Linhas das etapas de IA de uma execução de squad, em ordem. */
    public function etapasDoSquad(int $cabecalhoId): array
    {
        $st = DB::conexao()->prepare(
            "SELECT x.*, a.nome AS agente_nome, a.slug AS agente_slug,
                    (SELECT COUNT(*) FROM acoes_pendentes p WHERE p.execucao_id = x.id AND p.status = 'pendente') AS pendentes
             FROM execucoes x LEFT JOIN agentes a ON a.id = x.agente_id
             WHERE x.squad_execucao_id = :id ORDER BY x.etapa_ordem, x.id"
        );
        $st->execute(['id' => $cabecalhoId]);
        return $st->fetchAll();
    }

    /** Últimas execuções (cabeçalhos) de um squad. */
    public function doSquad(int $squadId, int $limite = 15): array
    {
        $st = DB::conexao()->prepare(
            'SELECT * FROM execucoes WHERE squad_id = :s AND squad_execucao_id IS NULL ORDER BY id DESC LIMIT ' . max(1, $limite)
        );
        $st->execute(['s' => $squadId]);
        return $st->fetchAll();
    }

    /** Soma de tokens das etapas de uma execução de squad. */
    public function tokensDoSquad(int $cabecalhoId): array
    {
        $st = DB::conexao()->prepare(
            'SELECT COALESCE(SUM(tokens_entrada), 0) AS entrada, COALESCE(SUM(tokens_saida), 0) AS saida FROM execucoes WHERE squad_execucao_id = :id'
        );
        $st->execute(['id' => $cabecalhoId]);
        $l = $st->fetch();
        return ['entrada' => (int) $l['entrada'], 'saida' => (int) $l['saida']];
    }
}
