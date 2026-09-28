<?php

declare(strict_types=1);

namespace App\Services\Asaas;

use App\Core\Config;

/**
 * Único ponto de chamada à API do Asaas (Fase 17): cliente, cobrança avulsa (payment) e recorrente (subscription).
 * Cobrança recorrente é acompanhada como uma linha só (o ciclo mais recente): o Asaas gera um "payment" por ciclo,
 * ligado à assinatura por `payment.subscription`, e é isso que o webhook usa para achar a cobrança de volta.
 */
final class Client
{
    /** @var (callable(array):array{status:int,corpo:string,erro:?string})|null transporte de teste */
    private static $transporte = null;

    /** Substitui o transporte HTTP (testes). Recebe ['metodo','caminho','corpo'] e devolve status/corpo/erro. */
    public static function definirTransporte(?callable $transporte): void
    {
        self::$transporte = $transporte;
    }

    public static function configurado(): bool
    {
        return self::$transporte !== null || trim((string) Config::obter('asaas.api_key', '')) !== '';
    }

    /** @return array{ok:bool,id:?string,erro:?string} */
    public static function obterOuCriarCliente(array $empresa): array
    {
        $documento = so_digitos((string) ($empresa['cnpj'] ?? ''));
        $corpo = array_filter([
            'name' => (string) $empresa['nome_fantasia'],
            'cpfCnpj' => $documento !== '' ? $documento : null,
            'email' => ($empresa['email_geral'] ?? null) ?: null,
            'phone' => so_digitos((string) ($empresa['telefone'] ?? '')) ?: null,
            'mobilePhone' => so_digitos((string) ($empresa['whatsapp'] ?? '')) ?: null,
            'externalReference' => (string) $empresa['id'],
        ], static fn ($v) => $v !== null && $v !== '');

        $r = self::requisitar('POST', '/customers', $corpo);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'id' => null, 'erro' => self::erroDoCorpo($r)];
        }
        $dados = json_decode($r['corpo'], true);
        return isset($dados['id']) ? ['ok' => true, 'id' => (string) $dados['id'], 'erro' => null] : ['ok' => false, 'id' => null, 'erro' => 'Resposta do Asaas sem id do cliente.'];
    }

    /** @return array{ok:bool,id:?string,tipo:?string,url_fatura:?string,erro:?string} */
    public static function criarCobranca(string $clienteId, array $cobranca): array
    {
        $corpo = [
            'customer' => $clienteId,
            'billingType' => self::tipoCobranca((string) $cobranca['forma_pagamento']),
            'value' => centavos_para_reais((int) $cobranca['valor']),
            'dueDate' => (string) $cobranca['vencimento'],
            'description' => (string) $cobranca['descricao'],
            'externalReference' => (string) $cobranca['id'],
        ];
        $r = self::requisitar('POST', '/payments', $corpo);
        return self::respostaEmissao($r, 'payment');
    }

    /** @return array{ok:bool,id:?string,tipo:?string,url_fatura:?string,erro:?string} */
    public static function criarAssinatura(string $clienteId, array $cobranca): array
    {
        $corpo = [
            'customer' => $clienteId,
            'billingType' => self::tipoCobranca((string) $cobranca['forma_pagamento']),
            'value' => centavos_para_reais((int) $cobranca['valor']),
            'nextDueDate' => (string) $cobranca['vencimento'],
            'cycle' => (string) $cobranca['ciclo'] === 'anual' ? 'YEARLY' : 'MONTHLY',
            'description' => (string) $cobranca['descricao'],
            'externalReference' => (string) $cobranca['id'],
        ];
        $r = self::requisitar('POST', '/subscriptions', $corpo);
        return self::respostaEmissao($r, 'subscription');
    }

    /** @return array{ok:bool,erro:?string} */
    public static function cancelar(string $asaasId, string $tipo): array
    {
        $caminho = $tipo === 'subscription' ? "/subscriptions/{$asaasId}" : "/payments/{$asaasId}";
        $r = self::requisitar('DELETE', $caminho, null);
        return $r['status'] >= 200 && $r['status'] < 300 ? ['ok' => true, 'erro' => null] : ['ok' => false, 'erro' => self::erroDoCorpo($r)];
    }

    /** billingType do Asaas a partir da forma de pagamento escolhida no CRM. */
    public static function tipoCobranca(string $formaPagamento): string
    {
        return match ($formaPagamento) {
            'boleto' => 'BOLETO', 'pix' => 'PIX', 'cartao' => 'CREDIT_CARD', default => 'UNDEFINED',
        };
    }

    /**
     * Status local a partir do evento do webhook (SPEC/roadmap: pendente, pago, vencido, cancelado). Null = evento ignorado
     * (não muda o status, ex.: PAYMENT_UPDATED sem mudança de situação).
     */
    public static function mapearStatus(string $evento): ?string
    {
        return match ($evento) {
            'PAYMENT_CREATED', 'PAYMENT_RESTORED' => 'pendente',
            'PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED', 'PAYMENT_RECEIVED_IN_CASH' => 'pago',
            'PAYMENT_OVERDUE' => 'vencido',
            'PAYMENT_DELETED', 'PAYMENT_REFUNDED', 'PAYMENT_REFUND_IN_PROGRESS' => 'cancelado',
            default => null,
        };
    }

    /**
     * Rótulo curto (cabe no `nfe_status`, 40 caracteres) a partir do status da nota fiscal (`invoice.status`).
     * Cobre tanto o Emissor Nacional quanto prefeituras com integração própria (ex.: Nota Fiscal Paulistana) —
     * o Asaas abstrai isso e devolve os mesmos status independente do município.
     */
    public static function statusNota(string $status, ?string $mensagemErro = null): string
    {
        return match ($status) {
            'SCHEDULED' => 'Agendada',
            'SYNCHRONIZED', 'SYNCED' => 'Sincronizando com a prefeitura',
            'AUTHORIZED' => 'Emitida',
            'PROCESSING_CANCELLATION' => 'Cancelamento em processamento',
            'CANCELED' => 'Cancelada',
            'CANCELLATION_DENIED' => 'Cancelamento negado',
            'ERROR' => mb_strimwidth('Erro' . ($mensagemErro ? ": {$mensagemErro}" : ' na emissão'), 0, 40, '…'),
            default => mb_strimwidth($status, 0, 40, '…'),
        };
    }

    /** Taxa cobrada pelo Asaas numa cobrança recebida: diferença entre o valor bruto e o líquido (`payment.value` − `payment.netValue`), em centavos. */
    public static function taxaDoPagamento(array $payment): int
    {
        if (!isset($payment['value'], $payment['netValue'])) {
            return 0;
        }
        $bruto = (int) round(((float) $payment['value']) * 100);
        $liquido = (int) round(((float) $payment['netValue']) * 100);
        return max(0, $bruto - $liquido);
    }

    /** `meio_pagamento` das despesas (SPEC §4.14) a partir do `billingType` do Asaas. */
    public static function meioPagamentoDespesa(?string $billingType): string
    {
        return match ($billingType) {
            'BOLETO' => 'boleto',
            'PIX' => 'pix',
            'CREDIT_CARD' => 'cartao_credito',
            'DEBIT_CARD' => 'cartao_debito',
            default => 'outro',
        };
    }

    // ---- Apoio ----------------------------------------------------------------------------

    /** @return array{ok:bool,id:?string,tipo:?string,url_fatura:?string,erro:?string} */
    private static function respostaEmissao(array $r, string $tipo): array
    {
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'id' => null, 'tipo' => null, 'url_fatura' => null, 'erro' => self::erroDoCorpo($r)];
        }
        $dados = json_decode($r['corpo'], true);
        if (!isset($dados['id'])) {
            return ['ok' => false, 'id' => null, 'tipo' => null, 'url_fatura' => null, 'erro' => 'Resposta do Asaas sem id.'];
        }
        return ['ok' => true, 'id' => (string) $dados['id'], 'tipo' => $tipo, 'url_fatura' => isset($dados['invoiceUrl']) ? (string) $dados['invoiceUrl'] : null, 'erro' => null];
    }

    private static function erroDoCorpo(array $r): string
    {
        if ($r['status'] === 0) {
            return 'Falha de conexão com o Asaas' . ($r['erro'] !== null ? ": {$r['erro']}" : '.');
        }
        $dados = json_decode($r['corpo'], true);
        $msg = $dados['errors'][0]['description'] ?? null;
        return is_string($msg) && $msg !== '' ? $msg : "O Asaas recusou a requisição (HTTP {$r['status']}).";
    }

    private static function requisitar(string $metodo, string $caminho, ?array $corpo): array
    {
        if (self::$transporte !== null) {
            return (self::$transporte)(['metodo' => $metodo, 'caminho' => $caminho, 'corpo' => $corpo]);
        }
        $chave = (string) Config::obter('asaas.api_key', '');
        return Http::chamar($metodo, self::baseUrl() . $caminho, $corpo, ['access_token: ' . $chave]);
    }

    private static function baseUrl(): string
    {
        return Config::obter('asaas.ambiente', 'sandbox') === 'producao'
            ? 'https://api.asaas.com/v3'
            : 'https://sandbox.asaas.com/api/v3';
    }
}
