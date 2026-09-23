<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Repositorios;
use App\Services\Asaas\Client as AsaasClient;

/**
 * Cobranças ligadas ao Asaas (Fase 17): emitir (criar o cliente no Asaas na primeira vez, depois a cobrança ou
 * assinatura), cancelar e sincronizar o status pelo webhook. A chamada de rede ao Asaas fica fora da transação do
 * SQLite (como o envio de mensagens da caixa de entrada, Fase 14): grava o registro local primeiro (status
 * "pendente", sem dados do Asaas), chama a API depois e só então grava o resultado numa segunda transação. Uma
 * cobrança recorrente é uma linha só (o `asaas_id` é o id da assinatura, estável entre ciclos); o status reflete o
 * ciclo mais recente, sem histórico por ciclo — o Asaas gera um pagamento novo a cada ciclo, ligado à assinatura
 * por `payment.subscription`, e é por aí que o webhook reencontra a cobrança.
 */
trait AcoesFinanceiro
{
    /** Cria a cobrança local e já tenta emitir no Asaas. Falha na emissão não desfaz a criação: fica pendente, sem asaas_id, para tentar de novo pela tela. */
    public function criarCobranca(array $dados, string $origem = 'humano'): Resultado
    {
        $r = $this->criar('cobrancas', $dados, $origem);
        if (!$r->ok) {
            return $r;
        }
        $emissao = $this->emitirCobranca((int) $r->id, $origem);
        return $emissao->ok ? $emissao : Resultado::sucesso((int) $r->id, $r->mensagem . ' Não foi possível emitir no Asaas: ' . $emissao->mensagem, $r->registro, $r->logId);
    }

    /** Cria o cliente no Asaas (se a empresa ainda não tiver um) e emite a cobrança (payment) ou assinatura (subscription). */
    public function emitirCobranca(int $id, string $origem = 'humano'): Resultado
    {
        $repo = Repositorios::cobrancas();
        $cobranca = $repo->encontrar($id);
        if ($cobranca === null) {
            return Resultado::erroGeral('Cobrança não encontrada.');
        }
        if ($cobranca['asaas_id'] !== null) {
            return Resultado::erroGeral('Esta cobrança já foi emitida no Asaas.');
        }
        if (!AsaasClient::configurado()) {
            return $this->falhaNaEmissao($id, 'Asaas não configurado: defina asaas.api_key em config.local.php.');
        }
        $empresas = Repositorios::empresas();
        $empresa = $empresas->encontrar((int) $cobranca['empresa_id']);
        if ($empresa === null) {
            return $this->falhaNaEmissao($id, 'Empresa não encontrada.');
        }

        $clienteId = $empresa['asaas_customer_id'];
        if ($clienteId === null) {
            $cliente = AsaasClient::obterOuCriarCliente($empresa);
            if (!$cliente['ok']) {
                return $this->falhaNaEmissao($id, 'Não foi possível criar o cliente no Asaas: ' . $cliente['erro']);
            }
            $clienteId = $cliente['id'];
            // Cache do id de integração: não é uma mudança de negócio da empresa, então não gera entrada própria de auditoria.
            $empresas->atualizar((int) $empresa['id'], ['asaas_customer_id' => $clienteId, 'atualizado_em' => agora()]);
        }

        $envio = $cobranca['tipo'] === 'recorrente'
            ? AsaasClient::criarAssinatura($clienteId, $cobranca)
            : AsaasClient::criarCobranca($clienteId, $cobranca);
        if (!$envio['ok']) {
            return $this->falhaNaEmissao($id, 'Não foi possível emitir a cobrança no Asaas: ' . $envio['erro']);
        }

        return $this->transacao(function () use ($repo, $id, $cobranca, $clienteId, $envio, $origem): Resultado {
            $depois = [
                'asaas_customer_id' => $clienteId, 'asaas_id' => $envio['id'], 'asaas_tipo' => $envio['tipo'],
                'url_fatura' => $envio['url_fatura'], 'status' => 'pendente',
            ];
            $antes = array_intersect_key($cobranca, $depois);
            // asaas_erro fica fora do diff de auditoria: é diagnóstico da integração, não mudança de negócio.
            $repo->atualizar($id, $depois + ['asaas_erro' => null, 'atualizado_em' => agora()]);
            $logId = Audit::registrar($origem, 'cobrancas', $id, 'emitir', $antes, $depois);
            return Resultado::sucesso($id, 'Cobrança emitida no Asaas.', $repo->encontrar($id), $logId);
        });
    }

    /**
     * Guarda na cobrança o motivo da falha de emissão e devolve o erro. O motivo fica visível na tela da cobrança
     * até a emissão dar certo — sem isso, a única pista era um toast de 5 s e parecia que o botão não fazia nada.
     */
    private function falhaNaEmissao(int $id, string $mensagem): Resultado
    {
        Repositorios::cobrancas()->atualizar($id, ['asaas_erro' => $mensagem, 'atualizado_em' => agora()]);
        return Resultado::erroGeral($mensagem);
    }

    /** Cancela a cobrança/assinatura no Asaas (se já emitida) e localmente. */
    public function cancelarCobranca(int $id, string $origem = 'humano'): Resultado
    {
        $repo = Repositorios::cobrancas();
        $cobranca = $repo->encontrar($id);
        if ($cobranca === null) {
            return Resultado::erroGeral('Cobrança não encontrada.');
        }
        if (in_array($cobranca['status'], ['cancelado', 'pago'], true)) {
            return Resultado::erroGeral('Esta cobrança já está ' . ($cobranca['status'] === 'pago' ? 'paga.' : 'cancelada.'));
        }
        if ($cobranca['asaas_id'] !== null) {
            $r = AsaasClient::cancelar((string) $cobranca['asaas_id'], (string) $cobranca['asaas_tipo']);
            if (!$r['ok']) {
                return Resultado::erroGeral('Não foi possível cancelar no Asaas: ' . $r['erro']);
            }
        }
        return $this->transacao(function () use ($repo, $id, $cobranca, $origem): Resultado {
            $repo->atualizar($id, ['status' => 'cancelado', 'atualizado_em' => agora()]);
            $logId = Audit::registrar($origem, 'cobrancas', $id, 'cancelar', ['status' => $cobranca['status']], ['status' => 'cancelado']);
            return Resultado::sucesso($id, 'Cobrança cancelada.', $repo->encontrar($id), $logId);
        });
    }

    /**
     * Aplica um evento do webhook do Asaas. Evento sem cobrança correspondente (id do pagamento nem da assinatura
     * batem com nada) ou sem mudança real de status é ignorado sem erro: o Asaas reenvia webhooks e manda eventos
     * que não alteram nada por aqui (ex.: PAYMENT_UPDATED de um campo que o CRM não acompanha).
     */
    public function processarEventoAsaas(string $evento, array $payment): Resultado
    {
        $repo = Repositorios::cobrancas();
        $cobranca = !empty($payment['id']) ? $repo->porAsaasId((string) $payment['id']) : null;
        if ($cobranca === null && !empty($payment['subscription'])) {
            $cobranca = $repo->porAsaasId((string) $payment['subscription']);
        }
        if ($cobranca === null) {
            return Resultado::sucesso(null, 'Cobrança não encontrada para este evento (ignorado).');
        }

        $novoStatus = AsaasClient::mapearStatus($evento);
        $mudaFatura = !empty($payment['invoiceUrl']) && $payment['invoiceUrl'] !== $cobranca['url_fatura'];
        $novoVencimento = self::vencimentoDoEvento($cobranca, $payment);
        if (($novoStatus === null || $novoStatus === $cobranca['status']) && !$mudaFatura && $novoVencimento === null) {
            return Resultado::sucesso((int) $cobranca['id'], 'Nada a atualizar.');
        }

        return $this->transacao(function () use ($repo, $cobranca, $novoStatus, $novoVencimento, $payment): Resultado {
            $depois = [];
            if ($novoStatus !== null && $novoStatus !== $cobranca['status']) {
                $depois['status'] = $novoStatus;
                if ($novoStatus === 'pago') {
                    $depois['data_pagamento'] = substr((string) ($payment['paymentDate'] ?? $payment['clientPaymentDate'] ?? hoje()), 0, 10);
                }
            }
            if (!empty($payment['invoiceUrl']) && $payment['invoiceUrl'] !== $cobranca['url_fatura']) {
                $depois['url_fatura'] = (string) $payment['invoiceUrl'];
            }
            if ($novoVencimento !== null) {
                $depois['vencimento'] = $novoVencimento;
            }
            $id = (int) $cobranca['id'];
            $antes = array_intersect_key($cobranca, $depois);
            $repo->atualizar($id, $depois + ['atualizado_em' => agora()]);
            $logId = Audit::registrar('sistema', 'cobrancas', $id, 'status_asaas', $antes, $depois);
            return Resultado::sucesso($id, 'Status atualizado pelo Asaas.', $repo->encontrar($id), $logId);
        });
    }

    /**
     * Vencimento a gravar a partir do evento, ou null se não muda. Numa assinatura o Asaas abre um pagamento por
     * ciclo e a linha do CRM acompanha o ciclo mais recente: sem isso o vencimento ficava congelado no primeiro
     * ciclo e o fallback do worker marcava "vencido" toda mensalidade em dia assim que o ciclo seguinte abria.
     * Evento atrasado de um ciclo anterior não puxa a data para trás; numa avulsa a linha é o próprio pagamento,
     * então espelha o que vier (inclusive vencimento adiantado no painel do Asaas).
     */
    private static function vencimentoDoEvento(array $cobranca, array $payment): ?string
    {
        $vencimento = substr(trim((string) ($payment['dueDate'] ?? '')), 0, 10);
        if ($vencimento === '' || $vencimento === (string) $cobranca['vencimento']) {
            return null;
        }
        $cicloAntigo = $cobranca['asaas_tipo'] === 'subscription' && $vencimento < (string) $cobranca['vencimento'];
        return $cicloAntigo ? null : $vencimento;
    }

    /** Fallback local (rotina do worker): cobrança pendente já vencida sem confirmação do Asaas (webhook perdido) vira "vencido". */
    public function marcarCobrancaVencida(int $id): Resultado
    {
        return $this->transacao(function () use ($id): Resultado {
            $repo = Repositorios::cobrancas();
            $cobranca = $repo->encontrar($id);
            if ($cobranca === null || $cobranca['status'] !== 'pendente') {
                return Resultado::sucesso($id, 'Nada a alterar.');
            }
            $repo->atualizar($id, ['status' => 'vencido', 'atualizado_em' => agora()]);
            $logId = Audit::registrar('sistema', 'cobrancas', $id, 'status_asaas', ['status' => 'pendente'], ['status' => 'vencido']);
            return Resultado::sucesso($id, 'Cobrança marcada como vencida.', $repo->encontrar($id), $logId);
        });
    }
}
