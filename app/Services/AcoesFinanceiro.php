<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Repositorios;
use App\Services\Asaas\Client as AsaasClient;

/**
 * Cobranças ligadas ao Asaas (Fase 17): emitir (criar o cliente no Asaas na primeira vez, depois a cobrança ou
 * assinatura), cancelar e sincronizar o status pelo webhook. A chamada de rede ao Asaas fica fora da transação do
 * SQLite (como o envio de mensagens da caixa de entrada, Fase 14): grava o registro local primeiro (status
 * "pendente", sem dados do Asaas), chama a API depois e só então grava o resultado numa segunda transação.
 *
 * Assinatura: uma linha por pagamento (ciclo). Todas as linhas guardam o id da assinatura em `asaas_id`, e cada uma o
 * seu pagamento em `asaas_payment_id` (único). O Asaas gera cada ciclo 40 dias antes do vencimento, então dois ciclos
 * ficam abertos ao mesmo tempo: o webhook sempre acha a linha pelo pagamento; a linha emitida pelo CRM (ainda sem
 * pagamento) recebe o primeiro, e cada pagamento novo da assinatura vira uma linha nova.
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
            // O Asaas exige cpfCnpj para criar cliente, e é por ele que achamos um cadastro feito pelo painel.
            // Recusar aqui dá ao operador o campo que falta; sem isso a emissão só morreria com a mensagem do Asaas.
            if (so_digitos((string) ($empresa['cnpj'] ?? '')) === '') {
                return $this->falhaNaEmissao($id, 'Preencha o CNPJ/CPF de ' . $empresa['nome_fantasia'] . ': o Asaas exige o documento para cadastrar o cliente.');
            }
            $cliente = AsaasClient::obterOuCriarCliente($empresa);
            if (!$cliente['ok']) {
                return $this->falhaNaEmissao($id, 'Não foi possível criar o cliente no Asaas: ' . $cliente['erro']);
            }
            $clienteId = $cliente['id'];
            // Cache do id de integração: não é uma mudança de negócio da empresa, então não gera entrada própria de auditoria.
            $empresas->atualizar((int) $empresa['id'], ['asaas_customer_id' => $clienteId, 'atualizado_em' => agora()]);
        } else {
            // Cliente já existe no Asaas: mantém o cadastro em dia (razão social, endereço...). Falha aqui não impede a emissão.
            AsaasClient::atualizarCliente($clienteId, $empresa);
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
                'asaas_assinatura_status' => $envio['tipo'] === 'subscription' ? 'ativa' : null,
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
        if ($cobranca['asaas_id'] !== null && $cobranca['asaas_tipo'] === 'subscription') {
            return $this->cancelarAssinatura($cobranca, $origem);
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
     * Cancela a assinatura no Asaas e as parcelas dela ainda em aberto. Excluir a assinatura só impede ciclos novos: os
     * boletos já gerados continuam pagáveis, então cada um é removido também. Parcela que o Asaas não removeu fica
     * como está (e entra na mensagem), para o CRM não mostrar cancelado um boleto que o cliente ainda pode pagar.
     */
    private function cancelarAssinatura(array $cobranca, string $origem): Resultado
    {
        $repo = Repositorios::cobrancas();
        $assinaturaId = (string) $cobranca['asaas_id'];
        $r = AsaasClient::cancelar($assinaturaId, 'subscription');
        if (!$r['ok'] && $r['http'] !== 404) {
            return Resultado::erroGeral('Não foi possível cancelar a assinatura no Asaas: ' . $r['erro']);
        }

        $canceladas = [];
        $falhas = [];
        foreach ($repo->daAssinatura($assinaturaId) as $c) {
            if ($c['arquivado_em'] !== null || !in_array($c['status'], ['pendente', 'vencido'], true)) {
                continue;
            }
            if ($c['asaas_payment_id'] !== null) {
                $rp = AsaasClient::cancelar((string) $c['asaas_payment_id'], 'payment');
                if (!$rp['ok'] && $rp['http'] !== 404) {
                    $falhas[] = data_br((string) $c['vencimento']) . ' (' . $rp['erro'] . ')';
                    continue;
                }
            }
            $canceladas[] = $c;
        }

        return $this->transacao(function () use ($repo, $cobranca, $canceladas, $falhas, $origem): Resultado {
            $agora = agora();
            $logId = null;
            foreach ($canceladas as $c) {
                $repo->atualizar((int) $c['id'], ['status' => 'cancelado', 'asaas_assinatura_status' => 'excluida', 'atualizado_em' => $agora]);
                $logId = Audit::registrar($origem, 'cobrancas', (int) $c['id'], 'cancelar', ['status' => $c['status']], ['status' => 'cancelado']);
            }
            $id = (int) $cobranca['id'];
            $msg = 'Assinatura cancelada no Asaas'
                . (count($canceladas) > 0 ? ', com ' . count($canceladas) . (count($canceladas) === 1 ? ' parcela em aberto.' : ' parcelas em aberto.') : '.');
            if ($falhas !== []) {
                $msg .= ' Não foi possível remover no Asaas a parcela de ' . implode(', ', $falhas) . ': cancele pelo painel.';
            }
            return Resultado::sucesso($id, $msg, $repo->encontrar($id), $logId);
        });
    }

    /**
     * Aplica um evento do webhook do Asaas na cobrança do pagamento. Numa assinatura, a linha é sempre a do próprio
     * pagamento; sem ela, a linha da assinatura que ainda espera o primeiro pagamento o recebe, e senão o pagamento é
     * um ciclo novo e ganha linha própria. Sem nenhuma linha da assinatura (ou avulsa desconhecida), importa.
     * Evento sem mudança real é ignorado sem erro: o Asaas reenvia webhooks e manda eventos que não alteram nada
     * por aqui (ex.: PAYMENT_UPDATED de um campo que o CRM não acompanha).
     */
    public function processarEventoAsaas(string $evento, array $payment): Resultado
    {
        $repo = Repositorios::cobrancas();
        $pagamentoId = (string) ($payment['id'] ?? '');
        $assinaturaId = (string) ($payment['subscription'] ?? '');
        $cobranca = $pagamentoId !== '' ? $repo->porAsaasPaymentId($pagamentoId) : null;
        if ($cobranca === null && $assinaturaId === '' && $pagamentoId !== '') {
            $cobranca = $repo->porAsaasId($pagamentoId);
        }
        if ($cobranca === null && $assinaturaId !== '') {
            $ciclos = $repo->daAssinatura($assinaturaId);
            if ($ciclos !== []) {
                $cobranca = self::cicloAguardandoPagamento($ciclos);
                if ($cobranca === null) {
                    return $this->novoCicloAssinaturaAsaas($evento, $payment, $ciclos);
                }
            }
        }
        if ($cobranca === null) {
            return $this->importarPagamentoAsaas($evento, $payment);
        }

        $novoStatus = AsaasClient::mapearStatus($evento);
        $viraPaga = $novoStatus === 'pago' && $novoStatus !== $cobranca['status'];
        $mudaFatura = !empty($payment['invoiceUrl']) && $payment['invoiceUrl'] !== $cobranca['url_fatura'];
        $novoVencimento = self::vencimentoDoEvento($cobranca, $payment);
        $espelho = self::espelhoDoPagamento($cobranca, $payment);
        // asaas_payment_id guarda o pagamento da linha (numa avulsa, igual a asaas_id): é por ele que este webhook e o
        // de NF-e reencontram a cobrança (invoice.payment nunca é o id da assinatura).
        $novoPaymentId = !empty($payment['id']) && (string) $payment['id'] !== (string) ($cobranca['asaas_payment_id'] ?? '') ? (string) $payment['id'] : null;
        if (($novoStatus === null || $novoStatus === $cobranca['status']) && !$mudaFatura && $novoVencimento === null && $novoPaymentId === null && $espelho === []) {
            return Resultado::sucesso((int) $cobranca['id'], 'Nada a atualizar.');
        }

        return $this->transacao(function () use ($repo, $cobranca, $novoStatus, $viraPaga, $novoVencimento, $novoPaymentId, $espelho, $payment): Resultado {
            $depois = $espelho;
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
            if ($novoPaymentId !== null) {
                $depois['asaas_payment_id'] = $novoPaymentId;
            }
            $id = (int) $cobranca['id'];
            $antes = array_intersect_key($cobranca, $depois);
            $repo->atualizar($id, $depois + ['atualizado_em' => agora()]);
            $logId = Audit::registrar('sistema', 'cobrancas', $id, 'status_asaas', $antes, $depois);
            if ($viraPaga) {
                $this->lancarTaxaAsaas($cobranca, $payment, (string) ($depois['data_pagamento'] ?? hoje()));
            }
            return Resultado::sucesso($id, 'Status atualizado pelo Asaas.', $repo->encontrar($id), $logId);
        });
    }

    /**
     * Lança como despesa a taxa que o Asaas descontou da cobrança recebida (payment.value − payment.netValue),
     * na categoria "Tarifas Asaas" (criada na primeira vez). Silencioso: falha em registrar a despesa não pode
     * derrubar a confirmação do pagamento, que é o que importa para o cliente e para o webhook responder 200.
     */
    private function lancarTaxaAsaas(array $cobranca, array $payment, string $dataPagamento): void
    {
        $taxa = AsaasClient::taxaDoPagamento($payment);
        if ($taxa <= 0) {
            return;
        }
        // Uma taxa por pagamento: o mesmo pagamento pode virar "pago" de novo numa linha recriada (reconciliação).
        if (!empty($payment['id']) && Repositorios::despesas()->existeTaxaAsaas((string) $payment['id'])) {
            return;
        }
        $categoriaId = $this->categoriaDespesaAsaas();
        if ($categoriaId === null) {
            return;
        }
        $descricao = mb_strimwidth('Taxa Asaas — ' . $cobranca['descricao'] . ' (' . $cobranca['empresa_nome'] . ')', 0, 200, '…');
        $this->criar('despesas', [
            'descricao' => $descricao, 'categoria_id' => $categoriaId, 'fornecedor' => 'Asaas',
            'valor' => centavos_para_reais($taxa), 'vencimento' => $dataPagamento, 'status' => 'pago', 'data_pagamento' => $dataPagamento,
            'meio_pagamento' => AsaasClient::meioPagamentoDespesa($payment['billingType'] ?? null), 'forma_pagamento' => 'a_vista',
            'notas' => 'Lançada automaticamente pelo webhook do Asaas — cobrança #' . $cobranca['id'] . ', pagamento ' . ($payment['id'] ?? '?') . '.',
        ], 'sistema');
    }

    /** Id de "Tarifas Asaas" em categorias_despesa, criando na primeira vez. Categoria própria (não a genérica "Tarifas bancárias" semeada por padrão) para deixar claro o que foi lançado automaticamente. */
    private function categoriaDespesaAsaas(): ?int
    {
        $repo = Repositorios::para('categorias_despesa');
        foreach ($repo->todas() as $c) {
            if (normalizar_busca((string) $c['nome']) === normalizar_busca('Tarifas Asaas')) {
                return (int) $c['id'];
            }
        }
        $r = $this->criar('categorias_despesa', ['nome' => 'Tarifas Asaas'], 'sistema');
        return $r->ok ? (int) $r->id : null;
    }

    /**
     * Aplica um evento de nota fiscal do Asaas (INVOICE_*) na cobrança correspondente. `invoice.payment` é o
     * pagamento do ciclo (não a assinatura), então a busca é por `asaas_payment_id` primeiro; `asaas_id` cobre
     * cobrança avulsa cujo webhook de pagamento ainda não chegou (ali os dois já nascem iguais).
     */
    public function processarEventoNotaAsaas(string $evento, array $invoice): Resultado
    {
        $repo = Repositorios::cobrancas();
        $cobranca = !empty($invoice['payment']) ? $repo->porAsaasPaymentId((string) $invoice['payment']) : null;
        if ($cobranca === null && !empty($invoice['payment'])) {
            $cobranca = $repo->porAsaasId((string) $invoice['payment']);
        }
        if ($cobranca === null) {
            return Resultado::sucesso(null, 'Cobrança não encontrada para esta nota fiscal (ignorado).');
        }

        $novoStatus = isset($invoice['status']) ? AsaasClient::statusNota((string) $invoice['status'], $invoice['errorMessage'] ?? null) : null;
        $novaUrl = !empty($invoice['pdfUrl']) ? (string) $invoice['pdfUrl'] : null;
        $depois = [];
        if ($novoStatus !== null && $novoStatus !== $cobranca['nfe_status']) {
            $depois['nfe_status'] = $novoStatus;
        }
        if ($novaUrl !== null && $novaUrl !== $cobranca['nfe_url']) {
            $depois['nfe_url'] = $novaUrl;
        }
        if ($depois === []) {
            return Resultado::sucesso((int) $cobranca['id'], 'Nada a atualizar.');
        }

        return $this->transacao(function () use ($repo, $cobranca, $depois): Resultado {
            $id = (int) $cobranca['id'];
            $antes = array_intersect_key($cobranca, $depois);
            $repo->atualizar($id, $depois + ['atualizado_em' => agora()]);
            $logId = Audit::registrar('sistema', 'cobrancas', $id, 'nfe_asaas', $antes, $depois);
            return Resultado::sucesso($id, 'Situação da nota fiscal atualizada.', $repo->encontrar($id), $logId);
        });
    }

    /**
     * Valor, descrição e forma de pagamento que o painel do Asaas mudou: com o Asaas como fonte da verdade, a linha
     * do CRM acompanha o pagamento (é ele que o cliente paga e que entra na receita).
     *
     * @return array<string,mixed>
     */
    private static function espelhoDoPagamento(array $cobranca, array $payment): array
    {
        $depois = [];
        $valor = reais_para_centavos($payment['value'] ?? null);
        if ($valor !== null && $valor > 0 && $valor !== (int) $cobranca['valor']) {
            $depois['valor'] = $valor;
        }
        $descricao = mb_strimwidth(trim((string) ($payment['description'] ?? '')), 0, 200, '…');
        if ($descricao !== '' && $descricao !== (string) $cobranca['descricao']) {
            $depois['descricao'] = $descricao;
        }
        $forma = AsaasClient::formaPagamentoLocal((string) ($payment['billingType'] ?? ''));
        if ($forma !== null && $forma !== $cobranca['forma_pagamento']) {
            $depois['forma_pagamento'] = $forma;
        }
        return $depois;
    }

    /** Vencimento a gravar a partir do evento, ou null se não muda: a linha é o próprio pagamento, então espelha o que vier (inclusive data adiada no painel). */
    private static function vencimentoDoEvento(array $cobranca, array $payment): ?string
    {
        $vencimento = substr(trim((string) ($payment['dueDate'] ?? '')), 0, 10);
        return $vencimento === '' || $vencimento === (string) $cobranca['vencimento'] ? null : $vencimento;
    }

    /**
     * Linha da assinatura que ainda não tem pagamento (a emitida pelo CRM ou importada do SUBSCRIPTION_CREATED, à
     * espera do primeiro ciclo). Arquivada ou cancelada não recebe pagamento: aí o pagamento vira linha nova.
     *
     * @param list<array> $ciclos
     */
    private static function cicloAguardandoPagamento(array $ciclos): ?array
    {
        foreach ($ciclos as $c) {
            if ($c['asaas_payment_id'] === null && $c['arquivado_em'] === null && $c['status'] !== 'cancelado') {
                return $c;
            }
        }
        return null;
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
