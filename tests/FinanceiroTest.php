<?php

declare(strict_types=1);

use App\Controllers\AsaasWebhookController;
use App\Core\Config;
use App\Repositories\AuditoriaRepository;
use App\Repositories\CobrancaRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Asaas\Client as AsaasClient;
use App\Services\Rotinas;

// Fase 17 — cobranças (Asaas), custos e DRE por cliente.

function novaEmpresaFinanceiro(array $dados = []): int
{
    $r = (new ActionExecutor())->criar('empresas', $dados + ['nome_fantasia' => 'Padaria Sol'], 'humano');
    verdadeiro($r->ok, $r->mensagem . json_encode($r->erros));
    return (int) $r->id;
}

/** Roda $fn com a API do Asaas "configurada" e o transporte HTTP trocado pelo fake. Restaura tudo ao final. */
function comAsaas(callable $transporte, callable $fn): void
{
    AsaasClient::definirTransporte($transporte);
    Config::definir(array_replace_recursive(configNeutra(), ['asaas' => ['api_key' => 'chave-teste', 'ambiente' => 'sandbox', 'webhook_token' => 'segredo-teste']]));
    try {
        $fn();
    } finally {
        AsaasClient::definirTransporte(null);
        Config::definir(configNeutra());
    }
}

// ---- Cobranças: validação e regras ---------------------------------------------------------------

teste('cobrança: sem o Asaas configurado, cria local pendente e avisa que não emitiu', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $r = (new ActionExecutor())->criarCobranca([
        'empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Website institucional',
        'valor' => '1.500,00', 'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026',
    ], 'humano');
    verdadeiro($r->ok, $r->mensagem);
    contem('Não foi possível emitir', $r->mensagem);
    $c = Repositorios::cobrancas()->encontrar((int) $r->id);
    igual('pendente', $c['status']);
    igual(null, $c['asaas_id']);
    igual(150000, $c['valor']);
});

teste('cobrança: recorrente exige ciclo; avulsa nunca leva ciclo', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $x = new ActionExecutor();
    $semCiclo = $x->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'recorrente', 'descricao' => 'Manutenção mensal', 'valor' => '300,00', 'forma_pagamento' => 'pix', 'vencimento' => '10/10/2026'], 'humano');
    igual(false, $semCiclo->ok);
    verdadeiro(isset($semCiclo->erros['ciclo']));

    $avulsa = $x->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'ciclo' => 'mensal', 'descricao' => 'Ajuste', 'valor' => '100,00', 'forma_pagamento' => 'pix', 'vencimento' => '10/10/2026'], 'humano');
    verdadeiro($avulsa->ok, $avulsa->mensagem);
    igual(null, Repositorios::cobrancas()->encontrar((int) $avulsa->id)['ciclo']);
});

teste('custo: exige empresa ou negócio; grava em centavos', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $x = new ActionExecutor();
    $semVinculo = $x->criar('custos', ['descricao' => 'Servidor', 'valor' => '80,00', 'data' => '05/10/2026'], 'humano');
    igual(false, $semVinculo->ok);

    $r = $x->criar('custos', ['empresa_id' => $empresaId, 'descricao' => 'Servidor', 'valor' => '80,00', 'data' => '05/10/2026', 'categoria' => 'Infraestrutura', 'recorrente' => 1], 'humano');
    verdadeiro($r->ok, $r->mensagem);
    igual(8000, Repositorios::custos()->encontrar((int) $r->id)['valor']);
});

// ---- Emissão no Asaas (transporte fake) ----------------------------------------------------------

teste('cobrança avulsa: emite no Asaas e cacheia o cliente na empresa (não recria em cobranças seguintes)', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $chamadasCliente = 0;
    $chamadasPagamento = 0;
    comAsaas(function (array $req) use (&$chamadasCliente, &$chamadasPagamento) {
        if ($req['caminho'] === '/customers') {
            $chamadasCliente++;
            return ['status' => 200, 'corpo' => json_encode(['id' => 'cus_000001']), 'erro' => null];
        }
        if ($req['caminho'] === '/payments') {
            $chamadasPagamento++;
            return ['status' => 200, 'corpo' => json_encode(['id' => 'pay_00000' . $chamadasPagamento, 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_00000' . $chamadasPagamento]), 'erro' => null];
        }
        if ($req['caminho'] === '/customers/cus_000001' && $req['metodo'] === 'PUT') {
            return ['status' => 200, 'corpo' => json_encode(['id' => 'cus_000001']), 'erro' => null];
        }
        throw new RuntimeException('rota inesperada: ' . $req['caminho']);
    }, function () use ($empresaId, &$chamadasCliente) {
        $x = new ActionExecutor();
        $r = $x->criarCobranca(['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Website institucional', 'valor' => '1.500,00', 'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026'], 'humano');
        verdadeiro($r->ok, $r->mensagem);
        $c = Repositorios::cobrancas()->encontrar((int) $r->id);
        igual('cus_000001', $c['asaas_customer_id']);
        igual('pay_000001', $c['asaas_id']);
        igual('payment', $c['asaas_tipo']);
        igual('https://sandbox.asaas.com/i/pay_000001', $c['url_fatura']);
        igual('pendente', $c['status']);
        igual('cus_000001', Repositorios::empresas()->encontrar($empresaId)['asaas_customer_id']);

        $r2 = $x->criarCobranca(['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Segunda cobrança', 'valor' => '200,00', 'forma_pagamento' => 'pix', 'vencimento' => '15/10/2026'], 'humano');
        verdadeiro($r2->ok, $r2->mensagem);
        igual(1, $chamadasCliente, 'o cliente do Asaas só é criado na primeira cobrança da empresa');
    });
});

teste('cliente no Asaas: name = razão social, company = nome fantasia, com endereço; cliente existente é atualizado (PUT)', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro([
        'razao_social' => 'Padaria Sol Ltda', 'cnpj' => '11.222.333/0001-81', 'email_geral' => 'financeiro@padariasol.com.br',
        'cep' => '30130-100', 'logradouro' => 'Av. Afonso Pena', 'numero' => '1500', 'complemento' => 'Sala 2', 'bairro' => 'Centro',
    ]);
    $clientes = [];
    comAsaas(function (array $req) use (&$clientes) {
        if (str_starts_with($req['caminho'], '/customers')) {
            $clientes[] = $req;
            return ['status' => 200, 'corpo' => json_encode(['id' => 'cus_1']), 'erro' => null];
        }
        return ['status' => 200, 'corpo' => json_encode(['id' => 'pay_' . count($clientes) . uniqid()]), 'erro' => null];
    }, function () use ($empresaId, &$clientes) {
        $dados = ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Site', 'valor' => '100,00', 'forma_pagamento' => 'pix', 'vencimento' => '10/10/2026'];
        $x = new ActionExecutor();
        verdadeiro($x->criarCobranca($dados, 'humano')->ok);
        $x->criarCobranca($dados, 'humano');

        igual(['POST', 'PUT'], array_column($clientes, 'metodo'));
        igual('/customers/cus_1', $clientes[1]['caminho']);
        $corpo = $clientes[0]['corpo'];
        igual('Padaria Sol Ltda', $corpo['name']);
        igual('Padaria Sol', $corpo['company']);
        igual('11222333000181', $corpo['cpfCnpj']);
        igual('30130100', $corpo['postalCode']);
        igual('Av. Afonso Pena', $corpo['address']);
        igual('1500', $corpo['addressNumber']);
        igual('Sala 2', $corpo['complement']);
        igual('Centro', $corpo['province']);
        igual('financeiro@padariasol.com.br', $corpo['email']);
        igual($corpo, $clientes[1]['corpo']);
    });
});

teste('AsaasClient::telefone: remove o código do país, aceita fixo e celular e omite o que está fora do padrão', function () {
    igual('31987654321', AsaasClient::telefone('+55 (31) 98765-4321'));
    igual('3133334444', AsaasClient::telefone('55 31 3333-4444'));
    igual('31987654321', AsaasClient::telefone('(31) 98765-4321'));
    igual('3133334444', AsaasClient::telefone('(31) 3333-4444'));
    igual('55987654321', AsaasClient::telefone('(55) 98765-4321')); // DDD 55 sem código do país: não confunde
    igual('5533334444', AsaasClient::telefone('(55) 3333-4444'));
    igual('55987654321', AsaasClient::telefone('+55 (55) 98765-4321')); // código do país + DDD 55
    igual(null, AsaasClient::telefone('3333-4444'));
    igual(null, AsaasClient::telefone(''));
    igual(null, AsaasClient::telefone('+44 20 7946 0958'));
});

teste('cobrança recorrente: emite assinatura com o ciclo e a forma de pagamento certos', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $capturado = null;
    comAsaas(function (array $req) use (&$capturado) {
        if ($req['caminho'] === '/customers') {
            return ['status' => 200, 'corpo' => json_encode(['id' => 'cus_1']), 'erro' => null];
        }
        $capturado = $req;
        return ['status' => 200, 'corpo' => json_encode(['id' => 'sub_1']), 'erro' => null];
    }, function () use ($empresaId, &$capturado) {
        $r = (new ActionExecutor())->criarCobranca(['empresa_id' => $empresaId, 'tipo' => 'recorrente', 'ciclo' => 'anual', 'descricao' => 'Plano anual', 'valor' => '1.200,00', 'forma_pagamento' => 'cartao', 'vencimento' => '01/11/2026'], 'humano');
        verdadeiro($r->ok, $r->mensagem);
        igual('/subscriptions', $capturado['caminho']);
        igual('YEARLY', $capturado['corpo']['cycle']);
        igual('CREDIT_CARD', $capturado['corpo']['billingType']);
        $c = Repositorios::cobrancas()->encontrar((int) $r->id);
        igual('sub_1', $c['asaas_id']);
        igual('subscription', $c['asaas_tipo']);
    });
});

teste('cobrança: erro do Asaas na emissão fica na mensagem, sem travar o registro local', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    comAsaas(function (array $req) {
        if ($req['caminho'] === '/customers') {
            return ['status' => 200, 'corpo' => json_encode(['id' => 'cus_1']), 'erro' => null];
        }
        return ['status' => 400, 'corpo' => json_encode(['errors' => [['description' => 'Valor inválido']]]), 'erro' => null];
    }, function () use ($empresaId) {
        $r = (new ActionExecutor())->criarCobranca(['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Falha proposital', 'valor' => '10,00', 'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026'], 'humano');
        verdadeiro($r->ok);
        contem('Valor inválido', $r->mensagem);
        igual(null, Repositorios::cobrancas()->encontrar((int) $r->id)['asaas_id']);
    });
});

teste('cobrança: o motivo da falha de emissão fica guardado na cobrança e some quando a emissão dá certo', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $executor = new ActionExecutor();

    // Sem o Asaas configurado: o motivo fica no registro (a tela mostra até emitir).
    $r = $executor->criarCobranca([
        'empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Tentativa sem chave',
        'valor' => '10,00', 'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026',
    ], 'humano');
    $id = (int) $r->id;
    contem('asaas.api_key', (string) Repositorios::cobrancas()->encontrar($id)['asaas_erro']);

    // Asaas recusa: guarda a mensagem devolvida pela API.
    comAsaas(function (array $req) {
        if ($req['caminho'] === '/customers') {
            return ['status' => 200, 'corpo' => json_encode(['id' => 'cus_1']), 'erro' => null];
        }
        return ['status' => 400, 'corpo' => json_encode(['errors' => [['description' => 'Valor inválido']]]), 'erro' => null];
    }, function () use ($executor, $id) {
        verdadeiro(!$executor->emitirCobranca($id, 'humano')->ok);
        contem('Valor inválido', (string) Repositorios::cobrancas()->encontrar($id)['asaas_erro']);
    });

    // Emissão bem-sucedida limpa o motivo.
    comAsaas(function (array $req) {
        $id = $req['caminho'] === '/customers' ? 'cus_1' : 'pay_1';
        return ['status' => 200, 'corpo' => json_encode(['id' => $id, 'invoiceUrl' => 'https://sandbox.asaas.com/i/pay_1']), 'erro' => null];
    }, function () use ($executor, $id) {
        verdadeiro($executor->emitirCobranca($id, 'humano')->ok);
        $c = Repositorios::cobrancas()->encontrar($id);
        igual(null, $c['asaas_erro']);
        igual('pay_1', $c['asaas_id']);
    });
});

teste('cobrança: cancelar chama o Asaas quando já emitida e bloqueia repetir sobre paga/cancelada', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    comAsaas(function (array $req) {
        if ($req['caminho'] === '/customers') {
            return ['status' => 200, 'corpo' => json_encode(['id' => 'cus_1']), 'erro' => null];
        }
        if ($req['metodo'] === 'DELETE') {
            return ['status' => 200, 'corpo' => '{}', 'erro' => null];
        }
        return ['status' => 200, 'corpo' => json_encode(['id' => 'pay_1']), 'erro' => null];
    }, function () use ($empresaId) {
        $x = new ActionExecutor();
        $id = (int) $x->criarCobranca(['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Vai cancelar', 'valor' => '50,00', 'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026'], 'humano')->id;

        $rc = $x->cancelarCobranca($id, 'humano');
        verdadeiro($rc->ok, $rc->mensagem);
        igual('cancelado', Repositorios::cobrancas()->encontrar($id)['status']);

        $rc2 = $x->cancelarCobranca($id, 'humano');
        igual(false, $rc2->ok, 'não pode cancelar de novo o que já está cancelado');
    });
});

teste('cobrança: pendente já emitida não pode ser arquivada direto (cancele primeiro)', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    comAsaas(function (array $req) {
        return ['status' => 200, 'corpo' => json_encode(['id' => $req['caminho'] === '/customers' ? 'cus_1' : 'pay_1']), 'erro' => null];
    }, function () use ($empresaId) {
        $x = new ActionExecutor();
        $id = (int) $x->criarCobranca(['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Teste arquivar', 'valor' => '50,00', 'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026'], 'humano')->id;
        $arq = $x->arquivar('cobrancas', $id, 'humano');
        igual(false, $arq->ok);
        contem('Cancele a cobrança', $arq->mensagem);
    });
});

teste('cobrança: depois de emitida no Asaas só as notas podem ser editadas', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    comAsaas(function (array $req) {
        return ['status' => 200, 'corpo' => json_encode(['id' => $req['caminho'] === '/customers' ? 'cus_1' : 'pay_1']), 'erro' => null];
    }, function () use ($empresaId) {
        $x = new ActionExecutor();
        $id = (int) $x->criarCobranca(['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Imutável', 'valor' => '50,00', 'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026'], 'humano')->id;

        $r = $x->atualizar('cobrancas', $id, ['valor' => '999,00'], 'humano');
        igual(false, $r->ok);

        $r2 = $x->atualizar('cobrancas', $id, ['notas' => 'Cliente pediu 2ª via'], 'humano');
        verdadeiro($r2->ok, $r2->mensagem);
    });
});

// ---- Webhook: autenticação, idempotência e sincronização de status --------------------------------

teste('webhook do Asaas: token errado recusa (403), corpo sem "event" recusa (400)', function () {
    bancoComSeed();
    Config::definir(array_replace_recursive(configNeutra(), ['asaas' => ['webhook_token' => 'segredo-teste']]));
    try {
        $controller = new AsaasWebhookController();
        igual(403, $controller->processar('{"event":"x"}', 'errado')->status);
        igual(403, $controller->processar('{"event":"x"}', '')->status);
        igual(400, $controller->processar('{"sem_event":true}', 'segredo-teste')->status);
    } finally {
        Config::definir(configNeutra());
    }
});

teste('webhook do Asaas: aplica o status e é idempotente (reenvio do mesmo corpo não repete)', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $id = (int) (new ActionExecutor())->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Webhook', 'valor' => '100,00', 'forma_pagamento' => 'pix', 'vencimento' => '10/10/2026'], 'humano')->id;
    Repositorios::cobrancas()->atualizar($id, ['asaas_id' => 'pay_x', 'asaas_tipo' => 'payment']);

    Config::definir(array_replace_recursive(configNeutra(), ['asaas' => ['webhook_token' => 'segredo-teste']]));
    try {
        $corpo = json_encode(['event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_x', 'paymentDate' => '2026-10-10', 'invoiceUrl' => 'https://x/y']]);
        $controller = new AsaasWebhookController();

        $resp1 = $controller->processar($corpo, 'segredo-teste');
        igual(200, $resp1->status);
        $c = Repositorios::cobrancas()->encontrar($id);
        igual('pago', $c['status']);
        igual('2026-10-10', $c['data_pagamento']);
        igual('https://x/y', $c['url_fatura']);

        $antes = count((new AuditoriaRepository())->listar(['entidade' => 'cobrancas', 'registro_id' => (string) $id], 1, 50)['linhas']);
        $resp2 = $controller->processar($corpo, 'segredo-teste');
        igual(200, $resp2->status);
        $depois = count((new AuditoriaRepository())->listar(['entidade' => 'cobrancas', 'registro_id' => (string) $id], 1, 50)['linhas']);
        igual($antes, $depois, 'reenvio do mesmo webhook não gera nova entrada de auditoria');
    } finally {
        Config::definir(configNeutra());
    }
});

teste('assinatura: virada de ciclo traz o vencimento novo e a mensalidade em dia não vira "vencido"', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $x = new ActionExecutor();
    comAsaas(function (array $req) {
        return ['status' => 200, 'corpo' => json_encode([
            'id' => $req['caminho'] === '/customers' ? 'cus_r' : 'sub_r',
            'invoiceUrl' => 'https://sandbox.asaas.com/i/ciclo1',
        ]), 'erro' => null];
    }, function () use ($x, $empresaId, &$id) {
        // Mensalidade cujo primeiro ciclo já venceu (e foi pago); o Asaas abre o ciclo seguinte adiante.
        $id = (int) $x->criarCobranca([
            'empresa_id' => $empresaId, 'tipo' => 'recorrente', 'ciclo' => 'mensal', 'descricao' => 'Mensalidade',
            'valor' => '350,00', 'forma_pagamento' => 'pix', 'vencimento' => '05/01/2020',
        ], 'humano')->id;
    });
    $ver = static fn (): array => Repositorios::cobrancas()->encontrar($id);
    igual('sub_r', $ver()['asaas_id']);

    // Ciclo 1 pago: o pagamento do ciclo é reencontrado pela assinatura (payment.subscription).
    $x->processarEventoAsaas('PAYMENT_RECEIVED', ['id' => 'pay_c1', 'subscription' => 'sub_r', 'dueDate' => '2020-01-05', 'paymentDate' => '2020-01-05']);
    igual('pago', $ver()['status']);

    // Ciclo 2 aberto pelo Asaas, vencendo no futuro: o vencimento da linha precisa acompanhar.
    $futuro = date('Y-m-d', strtotime(hoje() . ' +20 days'));
    $x->processarEventoAsaas('PAYMENT_CREATED', ['id' => 'pay_c2', 'subscription' => 'sub_r', 'dueDate' => $futuro, 'invoiceUrl' => 'https://sandbox.asaas.com/i/ciclo2']);
    $c = $ver();
    igual('pendente', $c['status']);
    igual($futuro, $c['vencimento']);
    igual('https://sandbox.asaas.com/i/ciclo2', $c['url_fatura']);

    // O fallback do worker não pode marcar vencida uma mensalidade em dia.
    igual([], (new CobrancaRepository())->pendentesVencidas(hoje()));

    // Evento atrasado de um ciclo anterior não puxa a data de volta (puxaria a linha para "vencido" de novo).
    $x->processarEventoAsaas('PAYMENT_UPDATED', ['id' => 'pay_c1', 'subscription' => 'sub_r', 'dueDate' => '2020-01-05']);
    igual($futuro, $ver()['vencimento']);
});

teste('cobrança avulsa: vencimento adiado no painel do Asaas chega pelo webhook', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $x = new ActionExecutor();
    comAsaas(function (array $req) {
        return ['status' => 200, 'corpo' => json_encode(['id' => $req['caminho'] === '/customers' ? 'cus_1' : 'pay_1']), 'erro' => null];
    }, function () use ($x, $empresaId, &$id) {
        $id = (int) $x->criarCobranca([
            'empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Projeto', 'valor' => '100,00',
            'forma_pagamento' => 'boleto', 'vencimento' => '10/10/2026',
        ], 'humano')->id;
    });
    // Numa avulsa a linha é o próprio pagamento: espelha a data que vier, inclusive para trás.
    $x->processarEventoAsaas('PAYMENT_UPDATED', ['id' => 'pay_1', 'dueDate' => '2026-09-30']);
    igual('2026-09-30', Repositorios::cobrancas()->encontrar($id)['vencimento']);
});

teste('webhook do Asaas: evento sem cobrança correspondente é ignorado sem erro', function () {
    bancoComSeed();
    $r = (new ActionExecutor())->processarEventoAsaas('PAYMENT_RECEIVED', ['id' => 'pay_desconhecido']);
    verdadeiro($r->ok);
    contem('não encontrada', $r->mensagem);
});

teste('AsaasClient::mapearStatus: eventos conhecidos mapeiam para pendente/pago/vencido/cancelado; desconhecido é null', function () {
    igual('pendente', AsaasClient::mapearStatus('PAYMENT_CREATED'));
    igual('pago', AsaasClient::mapearStatus('PAYMENT_RECEIVED'));
    igual('pago', AsaasClient::mapearStatus('PAYMENT_CONFIRMED'));
    igual('vencido', AsaasClient::mapearStatus('PAYMENT_OVERDUE'));
    igual('cancelado', AsaasClient::mapearStatus('PAYMENT_DELETED'));
    igual(null, AsaasClient::mapearStatus('PAYMENT_UPDATED'));
});

// ---- Nota fiscal (NF-e/NFS-e) e taxa do Asaas como despesa -----------------------------------------

teste('AsaasClient: status da nota, taxa do pagamento e meio de pagamento da despesa', function () {
    igual('Agendada', AsaasClient::statusNota('SCHEDULED'));
    igual('Emitida', AsaasClient::statusNota('AUTHORIZED'));
    igual('Cancelada', AsaasClient::statusNota('CANCELED'));
    contem('Erro: Município fora', AsaasClient::statusNota('ERROR', 'Município fora do sistema'));
    verdadeiro(mb_strlen(AsaasClient::statusNota('ERROR', str_repeat('x', 100))) <= 40, 'nunca passa de 40 caracteres (limite de nfe_status)');

    igual(150, AsaasClient::taxaDoPagamento(['value' => 100.00, 'netValue' => 98.50]));
    igual(0, AsaasClient::taxaDoPagamento(['value' => 100.00, 'netValue' => 100.00]));
    igual(0, AsaasClient::taxaDoPagamento(['value' => 100.00])); // sem netValue no payload: não inventa taxa

    igual('boleto', AsaasClient::meioPagamentoDespesa('BOLETO'));
    igual('pix', AsaasClient::meioPagamentoDespesa('PIX'));
    igual('cartao_credito', AsaasClient::meioPagamentoDespesa('CREDIT_CARD'));
    igual('cartao_debito', AsaasClient::meioPagamentoDespesa('DEBIT_CARD'));
    igual('outro', AsaasClient::meioPagamentoDespesa(null));
});

teste('webhook de nota fiscal: encontra a cobrança pelo pagamento do ciclo e grava status/link', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $id = (int) (new ActionExecutor())->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Nota', 'valor' => '500,00', 'forma_pagamento' => 'pix', 'vencimento' => '10/10/2026'], 'humano')->id;
    Repositorios::cobrancas()->atualizar($id, ['asaas_id' => 'pay_nf', 'asaas_payment_id' => 'pay_nf', 'asaas_tipo' => 'payment']);

    $x = new ActionExecutor();
    $r = $x->processarEventoNotaAsaas('INVOICE_AUTHORIZED', ['payment' => 'pay_nf', 'status' => 'AUTHORIZED', 'pdfUrl' => 'https://asaas.com/nf/1.pdf']);
    verdadeiro($r->ok, $r->mensagem);
    $c = Repositorios::cobrancas()->encontrar($id);
    igual('Emitida', $c['nfe_status']);
    igual('https://asaas.com/nf/1.pdf', $c['nfe_url']);

    // reenvio do mesmo estado não gera nova auditoria
    $antes = count((new AuditoriaRepository())->listar(['entidade' => 'cobrancas', 'registro_id' => (string) $id], 1, 50)['linhas']);
    $x->processarEventoNotaAsaas('INVOICE_AUTHORIZED', ['payment' => 'pay_nf', 'status' => 'AUTHORIZED', 'pdfUrl' => 'https://asaas.com/nf/1.pdf']);
    igual($antes, count((new AuditoriaRepository())->listar(['entidade' => 'cobrancas', 'registro_id' => (string) $id], 1, 50)['linhas']));

    $x->processarEventoNotaAsaas('INVOICE_ERROR', ['payment' => 'pay_nf', 'status' => 'ERROR', 'errorMessage' => 'CNPJ inválido']);
    contem('Erro', Repositorios::cobrancas()->encontrar($id)['nfe_status']);
});

teste('webhook de nota fiscal: numa assinatura, acha a cobrança pelo pagamento do ciclo (não pela assinatura)', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $id = (int) (new ActionExecutor())->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'recorrente', 'ciclo' => 'mensal', 'descricao' => 'Mensalidade', 'valor' => '300,00', 'forma_pagamento' => 'pix', 'vencimento' => '10/10/2026'], 'humano')->id;
    Repositorios::cobrancas()->atualizar($id, ['asaas_id' => 'sub_1', 'asaas_tipo' => 'subscription']);

    $x = new ActionExecutor();
    // o pagamento do ciclo chega pelo webhook de pagamento e fica registrado em asaas_payment_id
    $x->processarEventoAsaas('PAYMENT_CREATED', ['id' => 'pay_ciclo1', 'subscription' => 'sub_1', 'dueDate' => '2026-10-10']);
    igual('pay_ciclo1', Repositorios::cobrancas()->encontrar($id)['asaas_payment_id']);

    // a nota referencia o pagamento do ciclo, não a assinatura — e ainda assim acha a cobrança certa
    $r = $x->processarEventoNotaAsaas('INVOICE_AUTHORIZED', ['payment' => 'pay_ciclo1', 'status' => 'AUTHORIZED', 'pdfUrl' => 'https://asaas.com/nf/2.pdf']);
    igual($id, $r->id);
});

teste('webhook de nota fiscal: evento sem cobrança correspondente é ignorado sem erro', function () {
    bancoComSeed();
    $r = (new ActionExecutor())->processarEventoNotaAsaas('INVOICE_AUTHORIZED', ['payment' => 'pay_desconhecido', 'status' => 'AUTHORIZED']);
    verdadeiro($r->ok);
    contem('não encontrada', $r->mensagem);
});

teste('pagamento confirmado com taxa do Asaas: lança despesa "Tarifas Asaas" automaticamente, sem duplicar em reconfirmação', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $id = (int) (new ActionExecutor())->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Projeto site', 'valor' => '1.000,00', 'forma_pagamento' => 'cartao', 'vencimento' => '10/10/2026'], 'humano')->id;
    Repositorios::cobrancas()->atualizar($id, ['asaas_id' => 'pay_taxa', 'asaas_tipo' => 'payment']);

    $x = new ActionExecutor();
    $x->processarEventoAsaas('PAYMENT_RECEIVED', [
        'id' => 'pay_taxa', 'paymentDate' => '2026-10-09', 'value' => 1000.00, 'netValue' => 954.90, 'billingType' => 'CREDIT_CARD',
    ]);

    $despesas = Repositorios::despesas()->listar(['busca' => 'Taxa Asaas'])['linhas'];
    igual(1, count($despesas));
    $d = $despesas[0];
    igual(4510, (int) $d['valor']); // 1000,00 - 954,90 = 45,10
    igual('pago', $d['status']);
    igual('2026-10-09', $d['data_pagamento']);
    igual('cartao_credito', $d['meio_pagamento']);
    igual('a_vista', $d['forma_pagamento']);
    igual('Asaas', $d['fornecedor']);
    contem('Projeto site', $d['descricao']);
    verdadeiro(in_array('Tarifas Asaas', array_column(Repositorios::para('categorias_despesa')->todas(), 'nome'), true));

    // Reconfirmação (ex.: CONFIRMED depois de RECEIVED) não deve lançar a taxa de novo: status já era "pago".
    $x->processarEventoAsaas('PAYMENT_CONFIRMED', ['id' => 'pay_taxa', 'value' => 1000.00, 'netValue' => 954.90, 'billingType' => 'CREDIT_CARD']);
    igual(1, count(Repositorios::despesas()->listar(['busca' => 'Taxa Asaas'])['linhas']));
});

teste('pagamento sem diferença entre valor bruto e líquido (ex.: Pix sem taxa) não lança despesa', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    $id = (int) (new ActionExecutor())->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Consultoria', 'valor' => '200,00', 'forma_pagamento' => 'pix', 'vencimento' => '10/10/2026'], 'humano')->id;
    Repositorios::cobrancas()->atualizar($id, ['asaas_id' => 'pay_semtaxa', 'asaas_tipo' => 'payment']);

    (new ActionExecutor())->processarEventoAsaas('PAYMENT_RECEIVED', ['id' => 'pay_semtaxa', 'value' => 200.00, 'netValue' => 200.00, 'billingType' => 'PIX']);
    igual(0, count(Repositorios::despesas()->listar(['busca' => 'Taxa Asaas'])['linhas']));
});

// ---- Fallback local do worker ---------------------------------------------------------------------

teste('worker: cobrança pendente já vencida sem confirmação do Asaas vira "vencido" (fallback local)', function () {
    bancoComSeed();
    $empresaId = novaEmpresaFinanceiro();
    (new ActionExecutor())->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Vencida', 'valor' => '100,00', 'forma_pagamento' => 'boleto', 'vencimento' => '01/01/2020'], 'humano');
    $outraId = (int) (new ActionExecutor())->criar('cobrancas', ['empresa_id' => $empresaId, 'tipo' => 'avulsa', 'descricao' => 'Futura', 'valor' => '100,00', 'forma_pagamento' => 'boleto', 'vencimento' => '01/01/2099'], 'humano')->id;

    $resultado = (new Rotinas())->executar();
    igual(1, $resultado['cobrancas_vencidas']);
    igual('pendente', Repositorios::cobrancas()->encontrar($outraId)['status'], 'cobrança futura não é afetada');
});

// ---- DRE por cliente -------------------------------------------------------------------------------

teste('DRE: soma cobranças pagas e custos por empresa, só dentro do período', function () {
    bancoComSeed();
    $e1 = novaEmpresaFinanceiro(['nome_fantasia' => 'Empresa A']);
    $e2 = novaEmpresaFinanceiro(['nome_fantasia' => 'Empresa B']);
    $x = new ActionExecutor();

    $c1 = (int) $x->criar('cobrancas', ['empresa_id' => $e1, 'tipo' => 'avulsa', 'descricao' => 'Pago dentro', 'valor' => '1.000,00', 'forma_pagamento' => 'pix', 'vencimento' => '01/10/2026'], 'humano')->id;
    Repositorios::cobrancas()->atualizar($c1, ['status' => 'pago', 'data_pagamento' => '2026-10-05']);
    $c2 = (int) $x->criar('cobrancas', ['empresa_id' => $e1, 'tipo' => 'avulsa', 'descricao' => 'Pago fora do período', 'valor' => '500,00', 'forma_pagamento' => 'pix', 'vencimento' => '01/09/2026'], 'humano')->id;
    Repositorios::cobrancas()->atualizar($c2, ['status' => 'pago', 'data_pagamento' => '2026-09-05']);
    $c3 = (int) $x->criar('cobrancas', ['empresa_id' => $e1, 'tipo' => 'avulsa', 'descricao' => 'Ainda não paga', 'valor' => '700,00', 'forma_pagamento' => 'pix', 'vencimento' => '02/10/2026'], 'humano')->id;

    $x->criar('custos', ['empresa_id' => $e1, 'descricao' => 'Custo A', 'valor' => '300,00', 'data' => '10/10/2026'], 'humano');
    $x->criar('custos', ['empresa_id' => $e2, 'descricao' => 'Custo B', 'valor' => '100,00', 'data' => '10/10/2026'], 'humano');

    $receitas = (new CobrancaRepository())->pagoPorEmpresaNoPeriodo('2026-10-01', '2026-10-31');
    igual(100000, $receitas[$e1]);
    verdadeiro(!isset($receitas[$e2]), 'empresa sem cobrança paga não entra no mapa');

    $custos = Repositorios::custos()->porEmpresaNoPeriodo('2026-10-01', '2026-10-31');
    igual(30000, $custos[$e1]);
    igual(10000, $custos[$e2]);
});
