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
        'status', 'modelo', 'tokens_entrada', 'tokens_saida', 'duracao_ms', 'erro', 'concluido_em',
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
}
