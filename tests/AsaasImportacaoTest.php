<?php

declare(strict_types=1);

/**
 * Asaas como fonte da verdade: pagamentos e assinaturas criados no painel chegam ao CRM pelo webhook.
 * Reaproveita os auxiliares de FinanceiroTest.php (comAsaas, novaEmpresaFinanceiro, cnpjTeste), que o runner já
 * carregou: os casos só rodam depois que todos os arquivos foram registrados.
 */

use App\Controllers\AsaasWebhookController;
use App\Core\Config;
use App\Repositories\AsaasPendenciaRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;

/** Pagamento como o Asaas manda no webhook, com o que importa para o CRM. */
function pagamentoAsaas(array $extra = []): array
{
    return $extra + [
        'object' => 'payment', 'id' => 'pay_i1', 'customer' => 'cus_i1', 'value' => 150.0, 'netValue' => 148.01,
        'dueDate' => '2026-11-10', 'billingType' => 'BOLETO', 'status' => 'PENDING', 'description' => 'Hospedagem do site',
        'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_i1', 'externalReference' => null, 'subscription' => null,
    ];
}

function assinaturaAsaas(array $extra = []): array
{
    return $extra + [
        'object' => 'subscription', 'id' => 'sub_i1', 'customer' => 'cus_i1', 'value' => 350.0, 'nextDueDate' => '2026-11-05',
        'cycle' => 'MONTHLY', 'description' => 'Mensalidade de manutenção', 'billingType' => 'BOLETO', 'status' => 'ACTIVE',
        'deleted' => false, 'externalReference' => null,
    ];
}

/** Cliente como o GET /customers/{id} devolve. */
function clienteAsaas(array $extra = []): array
{
    return $extra + ['object' => 'customer', 'id' => 'cus_i1', 'name' => 'Padaria Sol LTDA', 'cpfCnpj' => '', 'externalReference' => null, 'deleted' => false];
}

/** Resposta de transporte (200 com JSON). */
function respostaAsaas(array $corpo, int $status = 200): array
{
    return ['status' => $status, 'corpo' => json_encode($corpo), 'erro' => null];
}

/** Empresa já ligada ao cliente cus_i1 do Asaas, para os casos que não dependem de achar a empresa. */
function empresaLigada(string $customerId = 'cus_i1'): int
{
    $id = novaEmpresaFinanceiro();
    Repositorios::empresas()->atualizar($id, ['asaas_customer_id' => $customerId]);
    return $id;
}

function cobrancasDoTeste(): array
{
    return Repositorios::cobrancas()->listar(['por_pagina' => 100])['linhas'];
}

teste('importação: pagamento avulso criado no painel vira cobrança da empresa ligada ao cliente, sem consultar o Asaas', function () {
    bancoComSeed();
    $empresaId = empresaLigada();
    $chamadas = 0;
    comAsaas(function () use (&$chamadas) {
        $chamadas++;
        return respostaAsaas([]);
    }, function () {
        $r = (new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
        verdadeiro($r->ok, $r->mensagem);
    });
    igual(0, $chamadas, 'cliente já ligado: nenhuma chamada ao Asaas');

    $linhas = cobrancasDoTeste();
    igual(1, count($linhas));
    $c = $linhas[0];
    igual($empresaId, (int) $c['empresa_id']);
    igual('avulsa', $c['tipo']);
    igual(15000, (int) $c['valor']);
    igual('pendente', $c['status']);
    igual('Hospedagem do site', $c['descricao']);
    igual('boleto', $c['forma_pagamento']);
    igual('2026-11-10', $c['vencimento']);
    igual('pay_i1', $c['asaas_id']);
    igual('pay_i1', $c['asaas_payment_id']);
    igual('payment', $c['asaas_tipo']);
    igual('cus_i1', $c['asaas_customer_id']);
    igual('https://sandbox.asaas.com/i/pay_i1', $c['url_fatura']);
    igual('sistema', $c['criado_por']);

    $acoes = array_column((new App\Repositories\AuditoriaRepository())->listar(['entidade' => 'cobrancas', 'registro_id' => (string) $c['id']], 1, 20)['linhas'], 'acao');
    verdadeiro(in_array('importar_asaas', $acoes, true), 'a importação fica na auditoria: ' . json_encode($acoes));
});

teste('importação: evento repetido do mesmo pagamento não duplica a cobrança', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $x = new ActionExecutor();
        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
        $x->processarEventoAsaas('PAYMENT_UPDATED', pagamentoAsaas());
        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
    });
    igual(1, count(cobrancasDoTeste()));
});

teste('importação: pagamento que já chega pago é importado como pago e lança a taxa do Asaas', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        (new ActionExecutor())->processarEventoAsaas('PAYMENT_RECEIVED', pagamentoAsaas(['status' => 'RECEIVED', 'paymentDate' => '2026-11-09']));
    });
    $c = cobrancasDoTeste()[0];
    igual('pago', $c['status']);
    igual('2026-11-09', $c['data_pagamento']);
    $despesas = Repositorios::para('despesas')->listar(['por_pagina' => 50])['linhas'];
    igual(1, count($despesas), 'taxa de R$ 1,99 lançada uma vez');
    igual(199, (int) $despesas[0]['valor']);
});

teste('importação: empresa achada pelo documento do cliente no Asaas, e o id do cliente fica guardado na empresa', function () {
    bancoComSeed();
    $cnpj = cnpjTeste();
    $empresaId = novaEmpresaFinanceiro(['cnpj' => $cnpj]);
    $consultas = 0;
    comAsaas(function (array $req) use ($cnpj, &$consultas) {
        if ($req['caminho'] === '/customers/cus_i1') {
            $consultas++;
            return respostaAsaas(clienteAsaas(['cpfCnpj' => $cnpj]));
        }
        return respostaAsaas([]);
    }, function () {
        verdadeiro((new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas())->ok);
        // O segundo pagamento do mesmo cliente já não precisa consultar o Asaas.
        (new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['id' => 'pay_i2']));
    });
    igual(1, $consultas);
    igual('cus_i1', Repositorios::empresas()->encontrar($empresaId)['asaas_customer_id']);
    igual(2, count(cobrancasDoTeste()));
    igual($empresaId, (int) cobrancasDoTeste()[0]['empresa_id']);
    igual(0, (new AsaasPendenciaRepository())->contarAbertas());
});

teste('importação: cliente criado por este CRM é achado pela referência externa (id da empresa)', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    comAsaas(function (array $req) use ($empresaId) {
        return $req['caminho'] === '/customers/cus_i1'
            ? respostaAsaas(clienteAsaas(['externalReference' => (string) $empresaId]))
            : respostaAsaas([]);
    }, function () {
        verdadeiro((new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas())->ok);
    });
    igual($empresaId, (int) cobrancasDoTeste()[0]['empresa_id']);
});

teste('importação: referência externa de empresa já ligada a OUTRO cliente do Asaas não é aceita', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    Repositorios::empresas()->atualizar($empresaId, ['asaas_customer_id' => 'cus_outro']);
    comAsaas(function (array $req) use ($empresaId) {
        return $req['caminho'] === '/customers/cus_i1'
            ? respostaAsaas(clienteAsaas(['externalReference' => (string) $empresaId]))
            : respostaAsaas([]);
    }, function () {
        (new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
    });
    igual(0, count(cobrancasDoTeste()), 'não liga o pagamento a uma empresa que pertence a outro cliente');
    igual(1, (new AsaasPendenciaRepository())->contarAbertas());
});

teste('pendência: cliente do Asaas sem empresa no CRM fica guardado e vira cobrança quando a empresa passa a existir', function () {
    bancoComSeed();
    $cnpj = cnpjTeste();
    comAsaas(function (array $req) use ($cnpj) {
        return $req['caminho'] === '/customers/cus_i1'
            ? respostaAsaas(clienteAsaas(['name' => 'Gráfica Boa Vista', 'cpfCnpj' => $cnpj]))
            : respostaAsaas([]);
    }, function () use ($cnpj) {
        $x = new ActionExecutor();
        $r = $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
        verdadeiro($r->ok, 'o webhook responde 200 mesmo sem empresa: erro faria o Asaas pausar a fila');
        igual(0, count(cobrancasDoTeste()));

        $pend = (new AsaasPendenciaRepository())->abertas();
        igual(1, count($pend));
        igual('pay:pay_i1', $pend[0]['chave']);
        igual('Gráfica Boa Vista', $pend[0]['cliente_nome']);
        igual($cnpj, $pend[0]['documento']);
        igual(15000, (int) $pend[0]['valor']);
        contem('Nenhuma empresa do CRM com o documento', $pend[0]['motivo']);

        // O operador cadastra a empresa; na rodada seguinte do worker a cobrança nasce e a pendência se resolve.
        $empresaId = novaEmpresaFinanceiro(['nome_fantasia' => 'Gráfica Boa Vista', 'cnpj' => $cnpj]);
        igual(1, $x->reprocessarPendenciasAsaas(20, 0));

        igual(1, count(cobrancasDoTeste()));
        igual($empresaId, (int) cobrancasDoTeste()[0]['empresa_id']);
        igual(0, (new AsaasPendenciaRepository())->contarAbertas());
    });
});

teste('pendência: retentativa respeita o intervalo mínimo e não resolve enquanto a empresa não existe', function () {
    bancoComSeed();
    comAsaas(function (array $req) {
        return $req['caminho'] === '/customers/cus_i1' ? respostaAsaas(clienteAsaas()) : respostaAsaas([]);
    }, function () {
        $x = new ActionExecutor();
        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
        contem('não tem CNPJ/CPF', (new AsaasPendenciaRepository())->abertas()[0]['motivo']);
        igual(0, $x->reprocessarPendenciasAsaas(20, 300), 'acabou de tentar: espera os 5 minutos');
        igual(0, $x->reprocessarPendenciasAsaas(20, 0), 'tenta, mas continua sem empresa');
        $p = (new AsaasPendenciaRepository())->abertas()[0];
        igual(2, (int) $p['tentativas']);
    });
});

teste('pendência: Asaas fora do ar na hora de consultar o cliente guarda para depois, sem perder o pagamento', function () {
    bancoComSeed();
    comAsaas(function (array $req) {
        return $req['caminho'] === '/customers/cus_i1' ? ['status' => 0, 'corpo' => '', 'erro' => 'timeout'] : respostaAsaas([]);
    }, function () {
        $r = (new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
        verdadeiro($r->ok);
    });
    igual(0, count(cobrancasDoTeste()));
    $p = (new AsaasPendenciaRepository())->abertas();
    igual(1, count($p));
    contem('Falha de conexão', $p[0]['motivo']);
});

teste('importação: pagamento removido ou estornado que o CRM nunca viu não é importado', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $x = new ActionExecutor();
        $x->processarEventoAsaas('PAYMENT_DELETED', pagamentoAsaas(['deleted' => true]));
        $x->processarEventoAsaas('PAYMENT_REFUNDED', pagamentoAsaas(['id' => 'pay_i2', 'status' => 'REFUNDED']));
    });
    igual(0, count(cobrancasDoTeste()));
    igual(0, (new AsaasPendenciaRepository())->contarAbertas());
});

teste('importação: pagamento de assinatura vira UMA cobrança recorrente e os ciclos seguintes atualizam a mesma linha', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(function (array $req) {
        return $req['caminho'] === '/subscriptions/sub_i1' ? respostaAsaas(assinaturaAsaas()) : respostaAsaas([]);
    }, function () {
        $x = new ActionExecutor();
        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['id' => 'pay_c1', 'subscription' => 'sub_i1', 'value' => 350.0, 'dueDate' => '2026-11-05', 'description' => null]));
        $x->processarEventoAsaas('PAYMENT_RECEIVED', pagamentoAsaas(['id' => 'pay_c1', 'subscription' => 'sub_i1', 'value' => 350.0, 'dueDate' => '2026-11-05', 'description' => null, 'status' => 'RECEIVED', 'paymentDate' => '2026-11-04']));
        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['id' => 'pay_c2', 'subscription' => 'sub_i1', 'value' => 350.0, 'dueDate' => '2026-12-05', 'description' => null, 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_c2']));
    });
    $linhas = cobrancasDoTeste();
    igual(1, count($linhas), 'uma linha por assinatura');
    $c = $linhas[0];
    igual('recorrente', $c['tipo']);
    igual('mensal', $c['ciclo']);
    igual(35000, (int) $c['valor']);
    igual('sub_i1', $c['asaas_id']);
    igual('subscription', $c['asaas_tipo']);
    igual('pay_c2', $c['asaas_payment_id'], 'acompanha o pagamento do ciclo mais recente');
    igual('2026-12-05', $c['vencimento']);
    igual('pendente', $c['status'], 'o ciclo novo reabre a cobrança');
    igual('ativa', $c['asaas_assinatura_status']);
    igual('Mensalidade de manutenção', $c['descricao'], 'pagamento sem descrição usa a da assinatura');
});

teste('importação: ciclo do Asaas que o CRM não tem (trimestral) fica sem ciclo e avisa nas notas, sem travar a edição', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(function (array $req) {
        return $req['caminho'] === '/subscriptions/sub_i1' ? respostaAsaas(assinaturaAsaas(['cycle' => 'QUARTERLY'])) : respostaAsaas([]);
    }, function () {
        (new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['subscription' => 'sub_i1']));
    });
    $c = cobrancasDoTeste()[0];
    igual('recorrente', $c['tipo']);
    igual(null, $c['ciclo']);
    contem('trimestral', (string) $c['notas']);

    $r = (new ActionExecutor())->atualizar('cobrancas', (int) $c['id'], ['notas' => 'Conferir com o financeiro do cliente'], 'humano');
    verdadeiro($r->ok, json_encode($r->erros));
});

teste('importação: assinatura que o Asaas já removeu (404) é importada só com os dados do pagamento', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(function (array $req) {
        return $req['caminho'] === '/subscriptions/sub_i1'
            ? ['status' => 404, 'corpo' => json_encode(['errors' => [['description' => 'Assinatura não encontrada']]]), 'erro' => null]
            : respostaAsaas([]);
    }, function () {
        verdadeiro((new ActionExecutor())->processarEventoAsaas('PAYMENT_RECEIVED', pagamentoAsaas(['subscription' => 'sub_i1', 'status' => 'RECEIVED']))->ok);
    });
    $c = cobrancasDoTeste()[0];
    igual('recorrente', $c['tipo']);
    igual('sub_i1', $c['asaas_id']);
    igual(null, $c['ciclo']);
    igual('pago', $c['status']);
});

teste('corrida da emissão: webhook que chega antes de o CRM gravar o id completa a cobrança do CRM em vez de duplicar', function () {
    bancoComSeed();
    $empresaId = empresaLigada();
    // Cobrança criada pelo CRM, ainda sem id do Asaas (a resposta da emissão não voltou).
    $id = (int) (new ActionExecutor())->criar('cobrancas', [
        'empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Projeto', 'valor' => '150,00',
        'forma_pagamento' => 'boleto', 'vencimento' => '10/11/2026',
    ], 'humano')->id;
    igual(null, Repositorios::cobrancas()->encontrar($id)['asaas_id']);

    comAsaas(fn () => respostaAsaas([]), function () use ($id) {
        $r = (new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['externalReference' => (string) $id]));
        verdadeiro($r->ok, $r->mensagem);
    });
    igual(1, count(cobrancasDoTeste()), 'nenhuma linha duplicada');
    $c = Repositorios::cobrancas()->encontrar($id);
    igual('pay_i1', $c['asaas_id']);
    igual('Projeto', $c['descricao'], 'a cobrança do CRM mantém o que o operador escreveu');
});

teste('corrida da emissão: referência externa de valor diferente não é tratada como cobrança do CRM', function () {
    bancoComSeed();
    $empresaId = empresaLigada();
    $id = (int) (new ActionExecutor())->criar('cobrancas', [
        'empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Projeto', 'valor' => '999,00',
        'forma_pagamento' => 'boleto', 'vencimento' => '10/11/2026',
    ], 'humano')->id;
    comAsaas(fn () => respostaAsaas([]), function () use ($id) {
        (new ActionExecutor())->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['externalReference' => (string) $id]));
    });
    igual(2, count(cobrancasDoTeste()), 'o número era coincidência: importa como cobrança nova');
    igual(null, Repositorios::cobrancas()->encontrar($id)['asaas_id']);
});

teste('assinatura: SUBSCRIPTION_CREATED desconhecida vira cobrança recorrente pendente e o primeiro pagamento atualiza a mesma linha', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $x = new ActionExecutor();
        $r = $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_CREATED', assinaturaAsaas());
        verdadeiro($r->ok, $r->mensagem);
        $c = cobrancasDoTeste()[0];
        igual('recorrente', $c['tipo']);
        igual('mensal', $c['ciclo']);
        igual('pendente', $c['status']);
        igual('2026-11-05', $c['vencimento']);
        igual(35000, (int) $c['valor']);
        igual('ativa', $c['asaas_assinatura_status']);
        igual(null, $c['asaas_payment_id']);

        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['id' => 'pay_c1', 'subscription' => 'sub_i1', 'value' => 350.0, 'dueDate' => '2026-11-05']));
    });
    $linhas = cobrancasDoTeste();
    igual(1, count($linhas));
    igual('pay_c1', $linhas[0]['asaas_payment_id']);
    igual('https://sandbox.asaas.com/i/pay_i1', $linhas[0]['url_fatura']);
});

teste('assinatura: alteração, inativação e exclusão chegam à cobrança recorrente', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $x = new ActionExecutor();
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_CREATED', assinaturaAsaas());
        $id = (int) cobrancasDoTeste()[0]['id'];
        $ver = static fn (): array => Repositorios::cobrancas()->encontrar($id);

        // O painel passa a assinatura para anual.
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_UPDATED', assinaturaAsaas(['cycle' => 'YEARLY']));
        igual('anual', $ver()['ciclo']);

        // Inativar pausa as próximas cobranças e não mexe no que já foi gerado.
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_INACTIVATED', assinaturaAsaas(['status' => 'INACTIVE']));
        igual('inativa', $ver()['asaas_assinatura_status']);
        igual('pendente', $ver()['status'], 'a cobrança em aberto continua pagável');

        // Reativar volta a ativa.
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_UPDATED', assinaturaAsaas(['cycle' => 'YEARLY']));
        igual('ativa', $ver()['asaas_assinatura_status']);

        // Excluir cancela o que está em aberto; o corpo do evento nem precisa trazer deleted:true.
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_DELETED', assinaturaAsaas(['cycle' => 'YEARLY']));
        igual('excluida', $ver()['asaas_assinatura_status']);
        igual('cancelado', $ver()['status']);
    });
});

teste('assinatura: excluir não desfaz um ciclo já pago, só registra que a recorrência acabou', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $x = new ActionExecutor();
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_CREATED', assinaturaAsaas());
        $x->processarEventoAsaas('PAYMENT_RECEIVED', pagamentoAsaas(['id' => 'pay_c1', 'subscription' => 'sub_i1', 'value' => 350.0, 'dueDate' => '2026-11-05', 'status' => 'RECEIVED', 'paymentDate' => '2026-11-05']));
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_DELETED', assinaturaAsaas());
    });
    $c = cobrancasDoTeste()[0];
    igual('pago', $c['status']);
    igual('excluida', $c['asaas_assinatura_status']);
});

teste('assinatura: alteração em assinatura desconhecida é ignorada (quem traz as antigas é a conciliação)', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $r = (new ActionExecutor())->processarEventoAssinaturaAsaas('SUBSCRIPTION_UPDATED', assinaturaAsaas());
        verdadeiro($r->ok);
        contem('ignorada', $r->mensagem);
    });
    igual(0, count(cobrancasDoTeste()));
});

teste('assinatura criada pelo CRM: a situação nasce "ativa" e SUBSCRIPTION_CREATED do próprio webhook não duplica', function () {
    bancoComSeed();
    $empresaId = empresaLigada();
    $id = 0;
    comAsaas(function (array $req) {
        return respostaAsaas(['id' => $req['caminho'] === '/subscriptions' ? 'sub_i1' : 'cus_i1', 'invoiceUrl' => null]);
    }, function () use ($empresaId, &$id) {
        $x = new ActionExecutor();
        $id = (int) $x->criarCobranca([
            'empresa_id' => $empresaId, 'tipo' => 'recorrente', 'ciclo' => 'mensal', 'descricao' => 'Mensalidade',
            'valor' => '350,00', 'forma_pagamento' => 'pix', 'vencimento' => '05/11/2026',
        ], 'humano')->id;
        igual('ativa', Repositorios::cobrancas()->encontrar($id)['asaas_assinatura_status']);
        // O webhook de criação chega depois que o id já foi gravado: reconhece a linha existente.
        $x->processarEventoAssinaturaAsaas('SUBSCRIPTION_CREATED', assinaturaAsaas(['externalReference' => (string) $id]));
    });
    igual(1, count(cobrancasDoTeste()));
});

teste('espelho: valor, descrição e forma de pagamento alterados no painel chegam à cobrança avulsa', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $x = new ActionExecutor();
        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas());
        $x->processarEventoAsaas('PAYMENT_UPDATED', pagamentoAsaas(['value' => 180.0, 'description' => 'Hospedagem + domínio', 'billingType' => 'PIX', 'dueDate' => '2026-11-20']));
    });
    $c = cobrancasDoTeste()[0];
    igual(18000, (int) $c['valor']);
    igual('Hospedagem + domínio', $c['descricao']);
    igual('pix', $c['forma_pagamento']);
    igual('2026-11-20', $c['vencimento']);
});

teste('espelho: evento atrasado de um ciclo anterior não sobrescreve valor, descrição nem forma do ciclo atual', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(function (array $req) {
        return $req['caminho'] === '/subscriptions/sub_i1' ? respostaAsaas(assinaturaAsaas()) : respostaAsaas([]);
    }, function () {
        $x = new ActionExecutor();
        $x->processarEventoAsaas('PAYMENT_CREATED', pagamentoAsaas(['id' => 'pay_c2', 'subscription' => 'sub_i1', 'value' => 400.0, 'dueDate' => '2026-12-05', 'billingType' => 'PIX']));
        // Chega o PAYMENT_UPDATED do ciclo 1 (vencimento menor), com os dados antigos.
        $x->processarEventoAsaas('PAYMENT_UPDATED', pagamentoAsaas(['id' => 'pay_c1', 'subscription' => 'sub_i1', 'value' => 350.0, 'dueDate' => '2026-11-05', 'billingType' => 'BOLETO']));
    });
    $c = cobrancasDoTeste()[0];
    igual(40000, (int) $c['valor']);
    igual('pix', $c['forma_pagamento']);
    igual('2026-12-05', $c['vencimento']);
});

teste('webhook: evento de assinatura chega ao tratamento pelo controller, autenticado pelo token', function () {
    bancoComSeed();
    empresaLigada();
    comAsaas(fn () => respostaAsaas([]), function () {
        $corpo = json_encode(['id' => 'evt_1', 'event' => 'SUBSCRIPTION_CREATED', 'subscription' => assinaturaAsaas()]);
        igual(403, (new AsaasWebhookController())->processar($corpo, 'errado')->status);
        igual(0, count(cobrancasDoTeste()));
        igual(200, (new AsaasWebhookController())->processar($corpo, 'segredo-teste')->status);
    });
    $c = cobrancasDoTeste();
    igual(1, count($c));
    igual('sub_i1', $c[0]['asaas_id']);
});

teste('worker: a rotina de pendências do Asaas roda junto das demais e não faz nada sem o Asaas configurado', function () {
    bancoComSeed();
    Config::definir(configNeutra());
    $resumo = (new App\Services\Rotinas())->executar();
    verdadeiro(array_key_exists('pendencias_asaas', $resumo), json_encode(array_keys($resumo)));
    igual(0, $resumo['pendencias_asaas']);
});
