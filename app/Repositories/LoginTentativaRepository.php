<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tentativas de login que falharam (proteção contra força bruta). Só SQL; não é entidade de negócio. */
final class LoginTentativaRepository
{
    public function registrar(string $ip, string $email): void
    {
        $st = DB::conexao()->prepare('INSERT INTO login_tentativas (ip, email, criado_em) VALUES (:ip, :email, :q)');
        $st->execute(['ip' => $ip, 'email' => $email, 'q' => agora()]);
    }

    /** Falhas do IP desde $desde (data/hora ISO). */
    public function contarPorIp(string $ip, string $desde): int
    {
        $st = DB::conexao()->prepare('SELECT COUNT(*) FROM login_tentativas WHERE ip = :ip AND criado_em >= :d');
        $st->execute(['ip' => $ip, 'd' => $desde]);
        return (int) $st->fetchColumn();
    }

    /** Falhas contra o e-mail (de qualquer IP) desde $desde. */
    public function contarPorEmail(string $email, string $desde): int
    {
        $st = DB::conexao()->prepare('SELECT COUNT(*) FROM login_tentativas WHERE email = :e AND criado_em >= :d');
        $st->execute(['e' => $email, 'd' => $desde]);
        return (int) $st->fetchColumn();
    }

    /** Data/hora da falha mais antiga do IP dentro da janela (para dizer quando o bloqueio termina). */
    public function maisAntigaDoIp(string $ip, string $desde): ?string
    {
        $st = DB::conexao()->prepare('SELECT MIN(criado_em) FROM login_tentativas WHERE ip = :ip AND criado_em >= :d');
        $st->execute(['ip' => $ip, 'd' => $desde]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }

    /** Login bem-sucedido: zera as falhas daquele IP. */
    public function limparDoIp(string $ip): void
    {
        DB::conexao()->prepare('DELETE FROM login_tentativas WHERE ip = :ip')->execute(['ip' => $ip]);
    }

    /** Remove o que já saiu da janela (manutenção oportunista). */
    public function limparAntigas(string $antesDe): void
    {
        DB::conexao()->prepare('DELETE FROM login_tentativas WHERE criado_em < :d')->execute(['d' => $antesDe]);
    }
}
