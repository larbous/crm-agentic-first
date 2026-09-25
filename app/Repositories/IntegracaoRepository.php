<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Idempotência da integração com o Opensquad: um registro por evento recebido. Não é uma entidade de negócio. */
final class IntegracaoRepository
{
    public static function chave(string $squad, string $run, string $eventoId): string
    {
        return $squad . '|' . $run . '|' . $eventoId;
    }

    /** Evento já registrado (ok ou erro) ou null se nunca chegou. */
    public function porChave(string $chave): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM integracao_eventos WHERE chave = :c');
        $st->execute(['c' => $chave]);
        return $st->fetch() ?: null;
    }

    /** Grava (ou regrava, se um envio anterior falhou) o resultado do evento. */
    public function registrar(string $squad, string $run, string $eventoId, string $tipo, string $status, array $resposta): void
    {
        $agora = agora();
        $st = DB::conexao()->prepare(
            'INSERT INTO integracao_eventos (chave, squad, run, evento_id, tipo, status, resposta, recebido_em, processado_em)
             VALUES (:c, :s, :r, :e, :t, :st, :resp, :q, :q)
             ON CONFLICT (chave) DO UPDATE SET tipo = excluded.tipo, status = excluded.status,
                resposta = excluded.resposta, processado_em = excluded.processado_em'
        );
        $st->execute([
            'c' => self::chave($squad, $run, $eventoId), 's' => $squad, 'r' => $run, 'e' => $eventoId, 't' => $tipo,
            'st' => $status, 'resp' => json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'q' => $agora,
        ]);
    }
}
