<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela ia_saude_provedor: estado do disjuntor de cada provedor de IA (uma linha por provedor). */
final class IaSaudeRepository
{
    /** @return array{falhas_seguidas:int,primeira_falha_em:?string,aberto_ate:?string,ultimo_erro:?string}|null */
    public function obter(string $provedor): ?array
    {
        $st = DB::conexao()->prepare('SELECT falhas_seguidas, primeira_falha_em, aberto_ate, ultimo_erro FROM ia_saude_provedor WHERE provedor = :p');
        $st->execute(['p' => $provedor]);
        $l = $st->fetch();
        return $l ? ['falhas_seguidas' => (int) $l['falhas_seguidas']] + $l : null;
    }

    public function gravar(string $provedor, int $falhas, ?string $primeiraFalhaEm, ?string $abertoAte, ?string $erro): void
    {
        DB::conexao()->prepare(
            'INSERT INTO ia_saude_provedor (provedor, falhas_seguidas, primeira_falha_em, aberto_ate, ultimo_erro, atualizado_em)
             VALUES (:p, :f, :i, :a, :e, :q)
             ON CONFLICT (provedor) DO UPDATE SET falhas_seguidas = excluded.falhas_seguidas, primeira_falha_em = excluded.primeira_falha_em,
                 aberto_ate = excluded.aberto_ate, ultimo_erro = excluded.ultimo_erro, atualizado_em = excluded.atualizado_em'
        )->execute(['p' => $provedor, 'f' => $falhas, 'i' => $primeiraFalhaEm, 'a' => $abertoAte, 'e' => $erro !== null ? mb_substr($erro, 0, 300) : null, 'q' => agora()]);
    }

    /** @return list<array> estado de todos os provedores que já tiveram falha registrada */
    public function todos(): array
    {
        return DB::conexao()->query('SELECT * FROM ia_saude_provedor ORDER BY provedor')->fetchAll();
    }
}
