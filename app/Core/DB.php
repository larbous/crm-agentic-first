<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/** Conexão PDO/SQLite com os PRAGMAs obrigatórios do projeto. */
final class DB
{
    private static ?PDO $conexao = null;

    public static function conexao(): PDO
    {
        return self::$conexao ??= self::conectar((string) Config::obter('db.caminho'));
    }

    public static function conectar(string $caminho): PDO
    {
        if ($caminho !== ':memory:') {
            $pasta = dirname($caminho);
            if (!is_dir($pasta)) {
                mkdir($pasta, 0775, true);
            }
        }
        $pdo = new PDO('sqlite:' . $caminho, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    /** Substitui a conexão compartilhada (testes). */
    public static function definir(?PDO $pdo): void
    {
        self::$conexao = $pdo;
    }
}
