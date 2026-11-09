<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Idempotência do webhook do Asaas (Fase 17): hash do corpo bruto, como `worker_marcas`. Não é uma entidade de negócio. */
final class AsaasWebhookRepository
{
    /** Registra o evento se o hash ainda não existir. Falso = já processado antes (webhook reenviado; não repetir). */
    public function registrarSeNovo(string $hash, string $evento, string $payload): bool
    {
        $st = DB::conexao()->prepare('INSERT OR IGNORE INTO asaas_webhooks (hash, evento, payload, recebido_em) VALUES (:h, :e, :p, :q)');
        $st->execute(['h' => $hash, 'e' => $evento, 'p' => $payload, 'q' => agora()]);
        return $st->rowCount() === 1;
    }

    /** Eventos PAYMENT_* recebidos, na ordem de chegada (para a reconciliação de assinaturas). @return list<array{evento:string,payload:string}> */
    public function eventosDePagamento(): array
    {
        return DB::conexao()->query("SELECT evento, payload FROM asaas_webhooks WHERE evento LIKE 'PAYMENT\\_%' ESCAPE '\\' ORDER BY id")->fetchAll();
    }
}
