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

    /**
     * Procura o cliente já cadastrado no Asaas antes de criar: a API aceita clientes duplicados de propósito
     * (a própria documentação manda consultar antes), então criar direto duplicaria todo cliente que o operador
     * já tivesse cadastrado pelo painel. Procura primeiro por `externalReference` (o id da empresa aqui, que só
     * existe em cadastro feito por este CRM) e depois pelo documento, que é obrigatório no Asaas e por isso
     * existe em qualquer cadastro feito à mão.
     *
     * @return array{ok:bool,id:?string,erro:?string}
     */
    public static function obterOuCriarCliente(array $empresa): array
    {
        $documento = so_digitos((string) ($empresa['cnpj'] ?? ''));
        foreach ([['externalReference', (string) $empresa['id']], ['cpfCnpj', $documento]] as [$filtro, $valor]) {
            if ($valor === '') {
                continue;
            }
            $achado = self::procurarCliente($filtro, $valor);
            if (!$achado['ok']) {
                return $achado; // falha de rede/credencial: não cria por cima, para não duplicar
            }
            if ($achado['id'] !== null) {
                return $achado;
            }
        }

        $r = self::requisitar('POST', '/customers', self::corpoCliente($empresa));
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'id' => null, 'erro' => self::erroDoCorpo($r)];
        }
        $dados = json_decode($r['corpo'], true);
        return isset($dados['id']) ? ['ok' => true, 'id' => (string) $dados['id'], 'erro' => null] : ['ok' => false, 'id' => null, 'erro' => 'Resposta do Asaas sem id do cliente.'];
    }

    /**
     * Um cliente do Asaas pelo filtro indicado, ou id null quando não existe. Cliente removido no painel é
     * ignorado (`deleted`), senão reaproveitaríamos um cadastro que o Asaas recusa na hora de cobrar. Havendo
     * mais de um (duplicata criada antes desta busca existir), fica com o primeiro: a escolha precisa ser
     * sempre a mesma entre chamadas, senão cada emissão apontaria para um cadastro diferente.
     *
     * @return array{ok:bool,id:?string,erro:?string}
     */
    private static function procurarCliente(string $filtro, string $valor): array
    {
        $r = self::requisitar('GET', '/customers?' . http_build_query([$filtro => $valor, 'limit' => 100]), null);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'id' => null, 'erro' => self::erroDoCorpo($r)];
        }
        foreach ((array) (json_decode($r['corpo'], true)['data'] ?? []) as $cliente) {
            if (!empty($cliente['id']) && empty($cliente['deleted'])) {
                return ['ok' => true, 'id' => (string) $cliente['id'], 'erro' => null];
            }
        }
        return ['ok' => true, 'id' => null, 'erro' => null];
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
            'municipalInscription' => $texto('inscricao_municipal'),
            'stateInscription' => $texto('inscricao_estadual'),
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

    // ---- Leitura do que nasce no painel do Asaas (importação) ------------------------------------

    /**
     * Cliente do Asaas pelo id. Serve para descobrir de qual empresa do CRM é um pagamento criado no painel: o
     * `externalReference` (id da empresa, quando o cliente foi criado por este CRM) ou o documento.
     *
     * @return array{ok:bool,http:int,cliente:?array{id:string,nome:string,documento:string,referencia:string,removido:bool},erro:?string}
     */
    public static function obterCliente(string $id): array
    {
        $r = self::requisitar('GET', '/customers/' . rawurlencode($id), null);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'http' => $r['status'], 'cliente' => null, 'erro' => self::erroDoCorpo($r)];
        }
        $d = json_decode($r['corpo'], true);
        if (!is_array($d) || empty($d['id'])) {
            return ['ok' => false, 'http' => $r['status'], 'cliente' => null, 'erro' => 'Resposta do Asaas sem dados do cliente.'];
        }
        return ['ok' => true, 'http' => $r['status'], 'erro' => null, 'cliente' => [
            'id' => (string) $d['id'],
            'nome' => trim((string) ($d['name'] ?? '')) !== '' ? trim((string) $d['name']) : trim((string) ($d['company'] ?? '')),
            'documento' => so_digitos((string) ($d['cpfCnpj'] ?? '')),
            'referencia' => trim((string) ($d['externalReference'] ?? '')),
            'removido' => !empty($d['deleted']),
        ]];
    }

    /**
     * Assinatura do Asaas pelo id, já normalizada (ver assinaturaDoObjeto). Um pagamento de ciclo só traz o id da
     * assinatura (`payment.subscription`), então ciclo e valor vêm daqui.
     *
     * @return array{ok:bool,http:int,assinatura:?array,erro:?string}
     */
    public static function obterAssinatura(string $id): array
    {
        $r = self::requisitar('GET', '/subscriptions/' . rawurlencode($id), null);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            return ['ok' => false, 'http' => $r['status'], 'assinatura' => null, 'erro' => self::erroDoCorpo($r)];
        }
        $d = json_decode($r['corpo'], true);
        if (!is_array($d) || empty($d['id'])) {
            return ['ok' => false, 'http' => $r['status'], 'assinatura' => null, 'erro' => 'Resposta do Asaas sem dados da assinatura.'];
        }
        return ['ok' => true, 'http' => $r['status'], 'assinatura' => self::assinaturaDoObjeto($d), 'erro' => null];
    }

    /**
     * Objeto `subscription` (da API ou do webhook) no vocabulário do CRM. Ciclo fora de mensal/anual vira null:
     * o CRM só distingue esses dois, e o ciclo original fica em `ciclo_asaas` para quem precisar avisar o operador.
     *
     * @return array{id:string,customer:string,valor:int,ciclo:?string,ciclo_asaas:string,forma_pagamento:?string,descricao:string,vencimento:string,situacao:string,removida:bool,referencia:string}
     */
    public static function assinaturaDoObjeto(array $d): array
    {
        $removida = !empty($d['deleted']);
        return [
            'id' => (string) ($d['id'] ?? ''),
            'customer' => (string) ($d['customer'] ?? ''),
            'valor' => (int) (reais_para_centavos($d['value'] ?? null) ?? 0),
            'ciclo' => self::cicloLocal((string) ($d['cycle'] ?? '')),
            'ciclo_asaas' => (string) ($d['cycle'] ?? ''),
            'forma_pagamento' => self::formaPagamentoLocal((string) ($d['billingType'] ?? '')),
            'descricao' => trim((string) ($d['description'] ?? '')),
            'vencimento' => substr(trim((string) ($d['nextDueDate'] ?? '')), 0, 10),
            'situacao' => $removida ? 'excluida' : self::situacaoAssinatura((string) ($d['status'] ?? '')),
            'removida' => $removida,
            'referencia' => trim((string) ($d['externalReference'] ?? '')),
        ];
    }

    /** MONTHLY/YEARLY → mensal/anual; qualquer outro ciclo (semanal, trimestral, semestral…) não existe no CRM: null. */
    public static function cicloLocal(string $cycle): ?string
    {
        return match ($cycle) {
            'MONTHLY' => 'mensal',
            'YEARLY' => 'anual',
            default => null,
        };
    }

    /** Nome do ciclo do Asaas em português, para a nota que avisa que o CRM não o representa. */
    public static function cicloRotulo(string $cycle): string
    {
        return match ($cycle) {
            'WEEKLY' => 'semanal',
            'BIWEEKLY' => 'quinzenal',
            'BIMONTHLY' => 'bimestral',
            'QUARTERLY' => 'trimestral',
            'SEMIANNUALLY' => 'semestral',
            default => mb_strtolower($cycle),
        };
    }

    /** ACTIVE/INACTIVE/EXPIRED do Asaas → ativa/inativa/expirada. */
    public static function situacaoAssinatura(string $status): string
    {
        return match ($status) {
            'ACTIVE' => 'ativa',
            'INACTIVE' => 'inativa',
            'EXPIRED' => 'expirada',
            default => mb_strtolower($status),
        };
    }

    /** `billingType` → forma de pagamento do CRM. Null para o que o CRM não tem (débito, transferência…): quem chama decide o padrão. */
    public static function formaPagamentoLocal(string $billingType): ?string
    {
        return match ($billingType) {
            'BOLETO' => 'boleto',
            'PIX' => 'pix',
            'CREDIT_CARD' => 'cartao',
            'UNDEFINED' => 'indefinido',
            default => null,
        };
    }

    /**
     * Status local a partir do próprio objeto `payment` (não do evento): serve para importar um pagamento que o CRM
     * nunca viu, onde o evento que chegou (ex.: PAYMENT_UPDATED) não diz em que pé a cobrança está. Sem `status`
     * reconhecível cai no que o evento diz, e por fim em null.
     */
    public static function statusDoPagamento(array $payment, ?string $evento = null): ?string
    {
        if (!empty($payment['deleted'])) {
            return 'cancelado';
        }
        $status = match ((string) ($payment['status'] ?? '')) {
            'PENDING', 'AWAITING_RISK_ANALYSIS', 'APPROVED_BY_RISK_ANALYSIS' => 'pendente',
            'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => 'pago',
            'OVERDUE' => 'vencido',
            'REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'REPROVED_BY_RISK_ANALYSIS',
            'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL' => 'cancelado',
            default => null,
        };
        return $status ?? ($evento !== null ? self::mapearStatus($evento) : null);
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
