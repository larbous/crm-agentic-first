<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela contrato_comentarios: conversa entre o cliente (link público) e a equipe. Sem soft delete. */
final class ContratoComentarioRepository
{
    public function inserir(int $contratoId, string $autor, string $nome, string $texto, ?string $ip): int
    {
        $st = DB::conexao()->prepare(
            'INSERT INTO contrato_comentarios (contrato_id, autor, nome, texto, ip, criado_em) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$contratoId, $autor, $nome, $texto, $ip, agora()]);
        return (int) DB::conexao()->lastInsertId();
    }

    /** @return list<array<string,mixed>> do mais antigo para o mais novo */
    public function porContrato(int $contratoId): array
    {
        $st = DB::conexao()->prepare('SELECT * FROM contrato_comentarios WHERE contrato_id = ? ORDER BY id');
        $st->execute([$contratoId]);
        return $st->fetchAll();
    }

    public function contar(int $contratoId, string $autor): int
    {
        $st = DB::conexao()->prepare('SELECT COUNT(*) FROM contrato_comentarios WHERE contrato_id = ? AND autor = ?');
        $st->execute([$contratoId, $autor]);
        return (int) $st->fetchColumn();
    }
}
