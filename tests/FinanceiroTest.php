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
