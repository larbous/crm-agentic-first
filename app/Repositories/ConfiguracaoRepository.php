<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela configuracoes (chave/valor). */
final class ConfiguracaoRepository
{
    public function obter(string $chave, ?string $padrao = null): ?string
    {
        $st = DB::conexao()->prepare('SELECT valor FROM configuracoes WHERE chave = :c');
        $st->execute(['c' => $chave]);
        $v = $st->fetchColumn();
        return $v === false ? $padrao : ($v === null ? $padrao : (string) $v);
    }

    public function definir(string $chave, ?string $valor): void
    {
        DB::conexao()->prepare(
            'INSERT INTO configuracoes (chave, valor, atualizado_em) VALUES (:c, :v, :q)
             ON CONFLICT (chave) DO UPDATE SET valor = excluded.valor, atualizado_em = excluded.atualizado_em'
        )->execute(['c' => $chave, 'v' => $valor, 'q' => agora()]);
    }
}
