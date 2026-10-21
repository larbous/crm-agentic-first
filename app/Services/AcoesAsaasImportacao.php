<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AsaasPendenciaRepository;
use App\Repositories\Repositorios;
use App\Services\Asaas\Client as AsaasClient;
use PDOException;
use Throwable;

/**
 * Asaas como fonte da verdade: o que nasce ou muda no painel do Asaas chega ao CRM pelo webhook.
 *
 * - Pagamento ou assinatura que o CRM nunca viu vira uma cobrança (a empresa é achada pelo cliente do Asaas: id já
 *   ligado, `externalReference` ou documento). Se a empresa não existe ou o Asaas não respondeu, o item fica em
 *   `asaas_pendencias` e o worker tenta de novo — nunca é descartado em silêncio.
 * - Eventos `SUBSCRIPTION_*` mantêm ciclo e situação da assinatura; excluir a assinatura cancela o que ainda está
 *   em aberto (o Asaas também remove os pagamentos pendentes e vencidos dela).
 * - Cobrança criada pelo próprio CRM cujo id ainda não foi gravado (o webhook chega antes da resposta da
 *   emissão) é reconhecida pelo `externalReference` e completada, em vez de duplicada.
 */
trait AcoesAsaasImportacao
{
    // ---- Pagamento que o CRM não conhece ------------------------------------------------------

    /** Chamado por processarEventoAsaas quando nenhuma cobrança local bate com o pagamento nem com a assinatura. */
    private function importarPagamentoAsaas(string $evento, array $payment): Resultado
    {
        $status = AsaasClient::statusDoPagamento($payment, $evento) ?? 'pendente';
        if ($status === 'cancelado') {
            return Resultado::sucesso(null, 'Pagamento cancelado ou removido sem registro local (ignorado).');
        }
        $clienteId = (string) ($payment['customer'] ?? '');
        if ($clienteId === '' || empty($payment['id'])) {
            return Resultado::sucesso(null, 'Evento sem cliente ou sem id de pagamento (ignorado).');
        }

        $valorPagamento = reais_para_centavos($payment['value'] ?? null);
        $local = $this->cobrancaLocalSemAsaas([$payment['externalReference'] ?? null], [$valorPagamento]);

        $assinatura = null;
        if ($local === null && !empty($payment['subscription'])) {
            $busca = AsaasClient::obterAssinatura((string) $payment['subscription']);
            if ($busca['ok']) {
                $assinatura = $busca['assinatura'];
                $local = $this->cobrancaLocalSemAsaas([$assinatura['referencia']], [$valorPagamento, $assinatura['valor']]);
            } elseif ($busca['http'] !== 404) {
                // 404 = assinatura já removida: segue só com o pagamento. Qualquer outra falha é do momento: tenta depois.
                return $this->guardarPendenciaAsaas($evento, $payment, null, $clienteId, 'Não foi possível consultar a assinatura no Asaas: ' . $busca['erro']);
            }
        }

        $campos = $this->camposAsaasDoPagamento($payment, $assinatura, $clienteId, $status);
        $chave = $this->chaveAsaas($payment, $assinatura['id'] ?? null);

        if ($local !== null) {
            return $this->vincularCobrancaLocalAsaas($local, $campos, $payment, $chave);
        }

        $dono = $this->resolverEmpresaAsaas($clienteId);
        if ($dono['empresa'] === null) {
            return $this->guardarPendenciaAsaas($evento, $payment, null, $clienteId, $dono['motivo'], $dono['cliente']);
        }

        $ehAssinatura = $campos['asaas_tipo'] === 'subscription';
        $descricao = trim((string) ($payment['description'] ?? ''));
        if ($descricao === '') {
            $descricao = $assinatura['descricao'] ?? '';
        }
        if ($descricao === '') {
            $descricao = $ehAssinatura ? 'Assinatura Asaas ' . $campos['asaas_id'] : 'Cobrança Asaas ' . $payment['id'];
        }
        $dados = [
            'empresa_id' => (int) $dono['empresa']['id'],
            'tipo' => $ehAssinatura ? 'recorrente' : 'avulsa',
            'descricao' => mb_strimwidth($descricao, 0, 200, '…'),
            'valor' => $valorPagamento ?? ($assinatura['valor'] ?? 0),
            'ciclo' => $assinatura['ciclo'] ?? null,
            'forma_pagamento' => AsaasClient::formaPagamentoLocal((string) ($payment['billingType'] ?? '')) ?? ($assinatura['forma_pagamento'] ?? null) ?? 'indefinido',
            'notas' => $assinatura !== null && $assinatura['ciclo'] === null && $assinatura['ciclo_asaas'] !== ''
                ? self::notaCicloAsaas($assinatura['ciclo_asaas'], null)
                : null,
        ];
        $campos += ['vencimento' => hoje()];

        return $this->gravarCobrancaImportada($dados + $campos, $evento, $payment, $chave);
    }

    // ---- Eventos de assinatura (SUBSCRIPTION_*) ------------------------------------------------

    /**
     * Mantém a cobrança recorrente em dia com a assinatura do Asaas: ciclo, situação (ativa/inativa/expirada/excluida)
     * e, quando a assinatura é excluída, o cancelamento do que ainda está em aberto. Valor, descrição e forma de
     * pagamento NÃO vêm daqui: a linha espelha o pagamento do ciclo (é ele que o cliente paga), e o Asaas já avisa
     * por PAYMENT_UPDATED quando uma alteração na assinatura muda os pagamentos pendentes.
     */
    public function processarEventoAssinaturaAsaas(string $evento, array $objeto): Resultado
    {
        if (empty($objeto['id'])) {
            return Resultado::sucesso(null, 'Evento de assinatura sem id (ignorado).');
        }
        $a = AsaasClient::assinaturaDoObjeto($objeto);
        $repo = Repositorios::cobrancas();
        $cobranca = $repo->porAsaasId($a['id']);

        if ($cobranca === null) {
            // Só a criação importa uma assinatura nova. Uma alteração em assinatura desconhecida não diz em que pé
            // está o ciclo atual; quem traz as antigas é a conciliação.
            return $evento === 'SUBSCRIPTION_CREATED' && !$a['removida']
                ? $this->importarAssinaturaAsaas($evento, $objeto, $a)
                : Resultado::sucesso(null, 'Assinatura sem registro local (ignorada).');
        }

        // O próprio evento é mais confiável que o corpo: a documentação não garante `deleted: true` em SUBSCRIPTION_DELETED.
        $situacao = match ($evento) {
            'SUBSCRIPTION_DELETED' => 'excluida',
            'SUBSCRIPTION_INACTIVATED' => 'inativa',
            default => $a['situacao'],
        };
        $removida = $a['removida'] || $evento === 'SUBSCRIPTION_DELETED';

        $depois = [];
        if (($cobranca['asaas_assinatura_status'] ?? null) !== $situacao) {
            $depois['asaas_assinatura_status'] = $situacao;
        }
        if ($a['ciclo_asaas'] !== '' && $a['ciclo'] !== ($cobranca['ciclo'] ?? null)) {
            $depois['ciclo'] = $a['ciclo'];
            if ($a['ciclo'] === null) {
                $depois['notas'] = self::notaCicloAsaas($a['ciclo_asaas'], $cobranca['notas'] ?? null);
            }
        }
        if ($removida && in_array($cobranca['status'], ['pendente', 'vencido'], true)) {
            $depois['status'] = 'cancelado';
        }
        if ($depois === []) {
            return Resultado::sucesso((int) $cobranca['id'], 'Nada a atualizar.');
        }

        return $this->transacao(function () use ($repo, $cobranca, $depois): Resultado {
            $id = (int) $cobranca['id'];
            $antes = array_intersect_key($cobranca, $depois);
            $repo->atualizar($id, $depois + ['atualizado_em' => agora()]);
            $logId = Audit::registrar('sistema', 'cobrancas', $id, 'assinatura_asaas', $antes, $depois);
            return Resultado::sucesso($id, 'Assinatura atualizada pelo Asaas.', $repo->encontrar($id), $logId);
        });
    }

    /** SUBSCRIPTION_CREATED de uma assinatura que o CRM não conhece: vira cobrança recorrente "pendente" até o primeiro pagamento chegar. */
    private function importarAssinaturaAsaas(string $evento, array $objeto, array $a): Resultado
    {
        $clienteId = $a['customer'];
        if ($clienteId === '') {
            return Resultado::sucesso(null, 'Assinatura sem cliente (ignorada).');
        }
        $campos = [
            'status' => 'pendente', 'asaas_customer_id' => $clienteId, 'asaas_id' => $a['id'], 'asaas_payment_id' => null,
            'asaas_tipo' => 'subscription', 'url_fatura' => null, 'asaas_assinatura_status' => $a['situacao'], 'asaas_erro' => null,
        ];
        $chave = 'sub:' . $a['id'];

        $local = $this->cobrancaLocalSemAsaas([$a['referencia']], [$a['valor']]);
        if ($local !== null) {
            return $this->vincularCobrancaLocalAsaas($local, $campos + ($a['vencimento'] !== '' ? ['vencimento' => $a['vencimento']] : []), null, $chave);
        }

        $dono = $this->resolverEmpresaAsaas($clienteId);
        if ($dono['empresa'] === null) {
            return $this->guardarPendenciaAsaas($evento, null, $objeto, $clienteId, $dono['motivo'], $dono['cliente'], $a);
        }
        $dados = [
            'empresa_id' => (int) $dono['empresa']['id'],
            'tipo' => 'recorrente',
            'descricao' => mb_strimwidth($a['descricao'] !== '' ? $a['descricao'] : 'Assinatura Asaas ' . $a['id'], 0, 200, '…'),
            'valor' => $a['valor'],
            'ciclo' => $a['ciclo'],
            'forma_pagamento' => $a['forma_pagamento'] ?? 'indefinido',
            'vencimento' => $a['vencimento'] !== '' ? $a['vencimento'] : hoje(),
            'notas' => $a['ciclo'] === null && $a['ciclo_asaas'] !== '' ? self::notaCicloAsaas($a['ciclo_asaas'], null) : null,
        ];

        return $this->gravarCobrancaImportada($dados + $campos, $evento, null, $chave);
    }

    // ---- Empresa dona do cliente do Asaas ------------------------------------------------------

    /**
     * Acha a empresa do CRM a que pertence um cliente do Asaas: id já ligado, `externalReference` (id da empresa,
     * quando o cliente foi criado por este CRM) ou documento. Quando acha por um dos dois últimos, grava o id do
     * cliente na empresa para os próximos eventos nem precisarem consultar o Asaas.
     *
     * @return array{empresa:?array,cliente:array,motivo:string}
     */
    private function resolverEmpresaAsaas(string $clienteId): array
    {
        $empresas = Repositorios::empresas();
        $ligada = $empresas->porAsaasCustomerId($clienteId);
        if ($ligada !== null) {
            return ['empresa' => $ligada, 'cliente' => [], 'motivo' => ''];
        }

        $busca = AsaasClient::obterCliente($clienteId);
        if (!$busca['ok']) {
            return ['empresa' => null, 'cliente' => [], 'motivo' => 'Não foi possível consultar o cliente no Asaas: ' . $busca['erro']];
        }
        $cliente = $busca['cliente'];

        $empresa = null;
        if ($cliente['referencia'] !== '' && ctype_digit($cliente['referencia'])) {
            $candidata = $empresas->encontrar((int) $cliente['referencia']);
            // Não confia na referência se a empresa já está ligada a OUTRO cliente do Asaas.
            if ($candidata !== null && ($candidata['asaas_customer_id'] === null || $candidata['asaas_customer_id'] === $clienteId)) {
                $empresa = $candidata;
            }
        }
        if ($empresa === null && $cliente['documento'] !== '') {
            $achada = $empresas->porCnpj($cliente['documento']);
            $empresa = $achada !== null ? $empresas->encontrar((int) $achada['id']) : null;
        }

        if ($empresa === null) {
            $motivo = $cliente['documento'] === ''
                ? 'O cliente «' . $cliente['nome'] . '» do Asaas não tem CNPJ/CPF e não foi criado por este CRM: não há como achar a empresa.'
                : 'Nenhuma empresa do CRM com o documento ' . documento_formatado($cliente['documento']) . ' (cliente «' . $cliente['nome'] . '» no Asaas): cadastre a empresa ou preencha o CNPJ/CPF dela.';
            return ['empresa' => null, 'cliente' => $cliente, 'motivo' => $motivo];
        }
        if ($empresa['asaas_customer_id'] === null) {
            // Cache do id de integração (mesmo critério de emitirCobranca): não é mudança de negócio, sem auditoria própria.
            $empresas->atualizar((int) $empresa['id'], ['asaas_customer_id' => $clienteId, 'atualizado_em' => agora()]);
            $empresa['asaas_customer_id'] = $clienteId;
        }
        return ['empresa' => $empresa, 'cliente' => $cliente, 'motivo' => ''];
    }

    // ---- Gravação -------------------------------------------------------------------------------

    /** Campos que vêm do Asaas e valem tanto para linha nova quanto para completar uma cobrança criada pelo CRM. */
    private function camposAsaasDoPagamento(array $payment, ?array $assinatura, string $clienteId, string $status): array
    {
        $assinaturaId = $assinatura['id'] ?? (string) ($payment['subscription'] ?? '');
        $ehAssinatura = $assinaturaId !== '';
        $campos = [
            'status' => $status,
            'asaas_customer_id' => $clienteId,
            'asaas_id' => $ehAssinatura ? $assinaturaId : (string) $payment['id'],
            'asaas_payment_id' => (string) $payment['id'],
            'asaas_tipo' => $ehAssinatura ? 'subscription' : 'payment',
            'url_fatura' => !empty($payment['invoiceUrl']) ? (string) $payment['invoiceUrl'] : null,
            'asaas_assinatura_status' => $ehAssinatura ? ($assinatura['situacao'] ?? null) : null,
            'asaas_erro' => null,
        ];
        $vencimento = substr(trim((string) ($payment['dueDate'] ?? '')), 0, 10);
        if ($vencimento !== '') {
            $campos['vencimento'] = $vencimento;
        }
        if ($status === 'pago') {
            $campos['data_pagamento'] = substr((string) ($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? hoje()), 0, 10);
        }
        return $campos;
    }

    /** Insere a cobrança importada. @param array<string,mixed> $linha */
    private function gravarCobrancaImportada(array $linha, string $evento, ?array $payment, string $chave): Resultado
    {
        $repo = Repositorios::cobrancas();
        $asaasId = (string) $linha['asaas_id'];
        try {
            return $this->transacao(function () use ($repo, $linha, $payment, $chave, $asaasId): Resultado {
                // Reconfere dentro da transação: outro webhook do mesmo pagamento pode ter criado a linha agora há pouco.
                $existente = $repo->porAsaasId($asaasId);
                if ($existente !== null) {
                    return Resultado::sucesso((int) $existente['id'], 'Cobrança já importada.');
                }
                $agora = agora();
                $id = $repo->inserir($linha + ['criado_em' => $agora, 'atualizado_em' => $agora, 'criado_por' => 'sistema']);
                $registro = $repo->encontrar($id);
                $depois = array_intersect_key($registro, array_flip($repo->colunas()));
                $logId = Audit::registrar('sistema', 'cobrancas', $id, 'importar_asaas', null, $depois);
                if ($linha['status'] === 'pago' && $payment !== null) {
                    $this->lancarTaxaAsaas($registro, $payment, (string) ($linha['data_pagamento'] ?? hoje()));
                }
                (new AsaasPendenciaRepository())->resolver($chave);
                return Resultado::sucesso($id, 'Cobrança importada do Asaas.', $registro, $logId);
            });
        } catch (PDOException $e) {
            // Corrida entre dois webhooks do mesmo pagamento (índice único em asaas_id): a linha existe agora, é só atualizá-la.
            if ($repo->porAsaasId($asaasId) !== null && $payment !== null) {
                return $this->processarEventoAsaas($evento, $payment);
            }
            throw $e;
        }
    }

    /**
     * Cobrança criada pelo CRM que o Asaas já conhece mas cujo id ainda não foi gravado aqui (a emissão chama a API
     * fora da transação, e o webhook pode chegar antes da resposta). O CRM manda o id da cobrança como
     * `externalReference`; para não casar com um número que o operador digitou no painel por outro motivo, exige
     * também o mesmo valor.
     *
     * @param list<mixed> $referencias
     * @param list<?int> $valores
     */
    private function cobrancaLocalSemAsaas(array $referencias, array $valores): ?array
    {
        $valores = array_values(array_filter($valores, static fn ($v) => $v !== null));
        foreach ($referencias as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '' || !ctype_digit($ref)) {
                continue;
            }
            $local = Repositorios::cobrancas()->encontrar((int) $ref);
            if ($local !== null && $local['asaas_id'] === null && in_array((int) $local['valor'], $valores, true)) {
                return $local;
            }
        }
        return null;
    }

    /** Completa, com o que o Asaas devolveu, uma cobrança do CRM que ainda não tinha o id. */
    private function vincularCobrancaLocalAsaas(array $local, array $campos, ?array $payment, string $chave): Resultado
    {
        return $this->transacao(function () use ($local, $campos, $payment, $chave): Resultado {
            $repo = Repositorios::cobrancas();
            $id = (int) $local['id'];
            $atual = $repo->encontrar($id);
            if ($atual === null || $atual['asaas_id'] !== null) {
                return Resultado::sucesso($id, 'Cobrança já vinculada ao Asaas.');
            }
            $antes = array_intersect_key($atual, $campos);
            $repo->atualizar($id, $campos + ['atualizado_em' => agora()]);
            $logId = Audit::registrar('sistema', 'cobrancas', $id, 'vincular_asaas', $antes, $campos);
            if (($campos['status'] ?? null) === 'pago' && $payment !== null) {
                $this->lancarTaxaAsaas($repo->encontrar($id) ?? $atual, $payment, (string) ($campos['data_pagamento'] ?? hoje()));
            }
            (new AsaasPendenciaRepository())->resolver($chave);
            return Resultado::sucesso($id, 'Cobrança do CRM vinculada ao Asaas.', $repo->encontrar($id), $logId);
        });
    }

    // ---- Pendências -----------------------------------------------------------------------------

    /** Uma pendência por assinatura (`sub:ID`) ou por pagamento avulso (`pay:ID`). */
    private function chaveAsaas(array $payment, ?string $assinaturaId): string
    {
        $sub = $assinaturaId ?? (string) ($payment['subscription'] ?? '');
        return $sub !== '' ? 'sub:' . $sub : 'pay:' . $payment['id'];
    }

    /**
     * Guarda o que não pôde ser importado agora. Devolve sucesso (e não erro): o webhook precisa responder 200, senão
     * o Asaas reenvia e, depois de 15 falhas seguidas, pausa a fila inteira.
     *
     * @param array<string,mixed>|null $payment
     * @param array<string,mixed>|null $objetoAssinatura
     * @param array<string,mixed> $cliente
     * @param array<string,mixed>|null $assinatura assinatura normalizada, quando já se tem
     */
    private function guardarPendenciaAsaas(string $evento, ?array $payment, ?array $objetoAssinatura, string $clienteId, string $motivo, array $cliente = [], ?array $assinatura = null): Resultado
    {
        $chave = $payment !== null ? $this->chaveAsaas($payment, $assinatura['id'] ?? null) : 'sub:' . ($objetoAssinatura['id'] ?? '?');
        (new AsaasPendenciaRepository())->registrar([
            'chave' => $chave,
            'evento' => $evento,
            'customer_id' => $clienteId,
            'cliente_nome' => ($cliente['nome'] ?? '') !== '' ? (string) $cliente['nome'] : null,
            'documento' => ($cliente['documento'] ?? '') !== '' ? (string) $cliente['documento'] : null,
            'descricao' => $payment !== null
                ? (trim((string) ($payment['description'] ?? '')) ?: null)
                : (($assinatura['descricao'] ?? '') !== '' ? (string) $assinatura['descricao'] : null),
            'valor' => $payment !== null ? reais_para_centavos($payment['value'] ?? null) : ($assinatura['valor'] ?? null),
            'vencimento' => $payment !== null
                ? (substr(trim((string) ($payment['dueDate'] ?? '')), 0, 10) ?: null)
                : (($assinatura['vencimento'] ?? '') !== '' ? (string) $assinatura['vencimento'] : null),
            'motivo' => mb_strimwidth($motivo, 0, 300, '…'),
            'payload' => json_encode(['payment' => $payment, 'subscription' => $objetoAssinatura], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return Resultado::sucesso(null, 'Aguardando vínculo com uma empresa: ' . $motivo);
    }

    /**
     * Rotina do worker: tenta de novo o que ficou pendente. Reprocessa o evento guardado pelo mesmo caminho do
     * webhook; se agora a empresa existe (ou o Asaas voltou), a cobrança nasce e a pendência é resolvida.
     * Devolve quantas foram resolvidas.
     */
    public function reprocessarPendenciasAsaas(int $limite = 20, int $espera = 300): int
    {
        if (!AsaasClient::configurado()) {
            return 0;
        }
        $repo = new AsaasPendenciaRepository();
        $resolvidas = 0;
        foreach ($repo->paraRetentar($espera, $limite) as $p) {
            $dados = (array) json_decode((string) $p['payload'], true);
            try {
                if (!empty($dados['payment'])) {
                    $this->processarEventoAsaas((string) $p['evento'], (array) $dados['payment']);
                } elseif (!empty($dados['subscription'])) {
                    $this->processarEventoAssinaturaAsaas((string) $p['evento'], (array) $dados['subscription']);
                }
            } catch (Throwable $e) {
                error_log('Pendência Asaas ' . $p['chave'] . ': ' . $e->getMessage());
                continue;
            }
            if ($this->cobrancaDaChaveAsaas((string) $p['chave']) !== null) {
                $repo->resolver((string) $p['chave']);
                $resolvidas++;
            }
        }
        return $resolvidas;
    }

    private function cobrancaDaChaveAsaas(string $chave): ?array
    {
        $id = substr($chave, 4);
        $repo = Repositorios::cobrancas();
        return str_starts_with($chave, 'sub:') ? $repo->porAsaasId($id) : ($repo->porAsaasId($id) ?? $repo->porAsaasPaymentId($id));
    }

    /** Linha para `notas` avisando que o ciclo da assinatura no Asaas não existe no CRM (que só distingue mensal e anual). */
    private static function notaCicloAsaas(string $cicloAsaas, ?string $notasAtuais): string
    {
        $linha = 'Ciclo no Asaas: ' . AsaasClient::cicloRotulo($cicloAsaas) . ' (o CRM só distingue mensal e anual).';
        $atuais = trim((string) $notasAtuais);
        if ($atuais === '') {
            return $linha;
        }
        return str_contains($atuais, $linha) ? $atuais : $atuais . "\n" . $linha;
    }
}
