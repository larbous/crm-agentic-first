<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Acesso à tabela usuarios. */
final class UsuarioRepository
{
    public function buscarPorEmail(string $email): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM usuarios WHERE email = :email LIMIT 1');
        $st->execute(['email' => $email]);
        return $st->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    public function criar(string $nome, string $email, string $senhaHash): int
    {
        $agora = agora();
        $st = DB::conexao()->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, criado_em, atualizado_em)
             VALUES (:nome, :email, :hash, :agora, :agora)'
        );
        $st->execute(['nome' => $nome, 'email' => $email, 'hash' => $senhaHash, 'agora' => $agora]);
        return (int) DB::conexao()->lastInsertId();
    }

    public function atualizarSenha(int $id, string $senhaHash): void
    {
        $st = DB::conexao()->prepare('UPDATE usuarios SET senha_hash = :hash, atualizado_em = :agora WHERE id = :id');
        $st->execute(['hash' => $senhaHash, 'agora' => agora(), 'id' => $id]);
    }

    public function registrarLogin(int $id, string $quando): void
    {
        $st = DB::conexao()->prepare('UPDATE usuarios SET ultimo_login_em = :quando WHERE id = :id');
        $st->execute(['quando' => $quando, 'id' => $id]);
    }
}
