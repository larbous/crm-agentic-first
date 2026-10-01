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
        $r = self::requisitar('POST', '/customers', self::corpoCliente($empresa));
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'id' => null, 'erro' => self::erroDoCorpo($r)];
        }
        $dados = json_decode($r['corpo'], true);
        return isset($dados['id']) ? ['ok' => true, 'id' => (string) $dados['id'], 'erro' => null] : ['ok' => false, 'id' => null, 'erro' => 'Resposta do Asaas sem id do cliente.'];
    }

    /** Atualiza o cadastro do cliente já existente no Asaas com os dados atuais da empresa. */
    public static function atualizarCliente(string $clienteId, array $empresa): array
    {
        $r = self::requisitar('PUT', "/customers/{$clienteId}", self::corpoCliente($empresa));
        return $r['status'] >= 200 && $r['status'] < 300 ? ['ok' => true, 'erro' => null] : ['ok' => false, 'erro' => self::erroDoCorpo($r)];
    }

    /** No Asaas, `name` é a razão social e `company` o nome fantasia; só vão os campos preenchidos. */
    private static function corpoCliente(array $empresa): array
    {
        $texto = static fn (string $campo): ?string => trim((string) ($empresa[$campo] ?? '')) !== '' ? trim((string) $empresa[$campo]) : null;
        $documento = so_digitos((string) ($empresa['cnpj'] ?? ''));

        return array_filter([
            'name' => $texto('razao_social') ?? (string) $empresa['nome_fantasia'],
            'company' => $texto('nome_fantasia'),
            'cpfCnpj' => $documento,
            'email' => $texto('email_geral'),
            'phone' => self::telefone((string) ($empresa['telefone'] ?? '')),
            'mobilePhone' => self::telefone((string) ($empresa['whatsapp'] ?? '')),
            'postalCode' => so_digitos((string) ($empresa['cep'] ?? '')),
            'address' => $texto('logradouro'),
            'addressNumber' => $texto('numero'),
            'complement' => $texto('complemento'),
            'province' => $texto('bairro'),
            'externalReference' => (string) $empresa['id'],
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Telefone no padrão do Asaas: só dígitos, DDD + número, sem código do país — (xx) xxxx-xxxx (10) ou (xx) xxxxx-xxxx (11).
     * Remove o 55 inicial quando sobram 10/11 dígitos (por isso um DDD 55 sem código do país não é mexido). Fora do
     * padrão devolve null: o campo é omitido, porque um telefone inválido faz o Asaas recusar o cadastro inteiro.
     */
    public static function telefone(string $valor): ?string
    {
        $digitos = so_digitos($valor);
        if (in_array(strlen($digitos), [12, 13], true) && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }
        return in_array(strlen($digitos), [10, 11], true) ? $digitos : null;
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
