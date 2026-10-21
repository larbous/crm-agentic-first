<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/**
 * Pagamentos e assinaturas vindos do Asaas que ainda não viraram cobrança (empresa não encontrada, ou o Asaas não
 * respondeu na hora). Não é uma entidade de negócio: não passa pelo ActionExecutor, como `asaas_webhooks`.
 */
final class AsaasPendenciaRepository
{
    /**
     * Cria a pendência ou, se a chave já existe, guarda o payload mais recente e conta mais uma tentativa
     * (reabre uma que estivesse resolvida: o item voltou a não ter onde ficar).
     *
     * @param array{chave:string,evento:string,customer_id:string,cliente_nome:?string,documento:?string,descricao:?string,valor:?int,vencimento:?string,motivo:string,payload:string} $d
     */
    public function registrar(array $d): void
    {
        $agora = agora();
        DB::conexao()->prepare(
            'INSERT INTO asaas_pendencias (chave, evento, customer_id, cliente_nome, documento, descricao, valor, vencimento, motivo, payload, tentativas, ultima_tentativa_em, criado_em)
             VALUES (:chave, :evento, :customer_id, :cliente_nome, :documento, :descricao, :valor, :vencimento, :motivo, :payload, 1, :agora, :agora)
             ON CONFLICT (chave) DO UPDATE SET
                evento = excluded.evento, customer_id = excluded.customer_id, motivo = excluded.motivo, payload = excluded.payload,
                cliente_nome = COALESCE(excluded.cliente_nome, cliente_nome), documento = COALESCE(excluded.documento, documento),
                descricao = COALESCE(excluded.descricao, descricao), valor = COALESCE(excluded.valor, valor),
                vencimento = COALESCE(excluded.vencimento, vencimento),
                tentativas = tentativas + 1, ultima_tentativa_em = excluded.ultima_tentativa_em, resolvido_em = NULL'
        )->execute($d + ['agora' => $agora]);
    }

    public function resolver(string $chave): void
    {
        DB::conexao()->prepare('UPDATE asaas_pendencias SET resolvido_em = :q WHERE chave = :c AND resolvido_em IS NULL')
            ->execute(['q' => agora(), 'c' => $chave]);
    }

    public function contarAbertas(): int
    {
        return (int) DB::conexao()->query('SELECT COUNT(*) FROM asaas_pendencias WHERE resolvido_em IS NULL')->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function abertas(int $limite = 200): array
    {
        $st = DB::conexao()->prepare('SELECT * FROM asaas_pendencias WHERE resolvido_em IS NULL ORDER BY criado_em, id LIMIT :l');
        $st->bindValue('l', max(1, $limite), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Abertas que valem uma nova tentativa agora: a última foi há mais de $espera segundos. O espaçamento evita
     * bater na API do Asaas a cada minuto por algo que só se resolve quando o operador cadastra a empresa.
     *
     * @return list<array<string,mixed>>
     */
    public function paraRetentar(int $espera, int $limite): array
    {
        $limiar = date('Y-m-d H:i:s', time() - $espera);
        $st = DB::conexao()->prepare('SELECT * FROM asaas_pendencias WHERE resolvido_em IS NULL AND ultima_tentativa_em <= :l ORDER BY ultima_tentativa_em, id LIMIT :n');
        $st->bindValue('l', $limiar);
        $st->bindValue('n', max(1, $limite), \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
