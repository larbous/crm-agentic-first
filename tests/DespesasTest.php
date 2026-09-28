<?php

declare(strict_types=1);

use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Opcoes;

// Despesas da estrutura (contas a pagar sem cliente) e categorias de despesa.

function novaCategoriaDespesa(string $nome = 'Aluguel de teste'): int
{
    $r = (new ActionExecutor())->criar('categorias_despesa', ['nome' => $nome], 'humano');
    verdadeiro($r->ok, $r->mensagem . json_encode($r->erros));
    Opcoes::limpar();
    return (int) $r->id;
}

teste('despesa: a migração semeia as categorias padrão para pequenas empresas', function () {
    bancoComSeed();
    $nomes = array_column(Repositorios::para('categorias_despesa')->todas(), 'nome');
    verdadeiro(in_array('Aluguel e condomínio', $nomes, true), 'falta Aluguel e condomínio');
    verdadeiro(in_array('Impostos e taxas', $nomes, true), 'falta Impostos e taxas');
    verdadeiro(count($nomes) >= 10, 'poucas categorias padrão');
});

teste('despesa: categoria de despesa tem nome único', function () {
    bancoComSeed();
    novaCategoriaDespesa('Vale-transporte');
    $repetida = (new ActionExecutor())->criar('categorias_despesa', ['nome' => 'vale-transporte'], 'humano');
    igual(false, $repetida->ok);
});

teste('despesa: cria a pagar (à vista por padrão), em centavos, sem data de pagamento', function () {
    bancoComSeed();
    $categoria = novaCategoriaDespesa();
    $r = (new ActionExecutor())->criar('despesas', ['descricao' => 'Aluguel da sala', 'categoria_id' => $categoria, 'valor' => '1.850,00', 'vencimento' => '2026-10-05'], 'humano');
    verdadeiro($r->ok, $r->mensagem . json_encode($r->erros));
    $d = Repositorios::despesas()->encontrar((int) $r->id);
    igual(185000, $d['valor']);
    igual('pendente', $d['status']);
    igual('a_vista', $d['forma_pagamento']);
    igual(null, $d['data_pagamento']);
    igual('Aluguel de teste', $d['categoria_nome']);
});

teste('despesa: exige descrição, categoria, valor e vencimento', function () {
    bancoComSeed();
    $r = (new ActionExecutor())->criar('despesas', ['descricao' => 'Sem nada'], 'humano');
    igual(false, $r->ok);
    foreach (['categoria_id', 'valor', 'vencimento'] as $campo) {
        verdadeiro(isset($r->erros[$campo]), "esperava erro em {$campo}");
    }
});

teste('despesa: criada já como paga exige o meio de pagamento e carimba a data de hoje', function () {
    bancoComSeed();
    $categoria = novaCategoriaDespesa();
    $x = new ActionExecutor();
    $base = ['descricao' => 'Internet', 'categoria_id' => $categoria, 'valor' => '120,00', 'vencimento' => '2026-10-10', 'status' => 'pago'];

    $semMeio = $x->criar('despesas', $base, 'humano');
    igual(false, $semMeio->ok);
    verdadeiro(isset($semMeio->erros['meio_pagamento']));

    $r = $x->criar('despesas', $base + ['meio_pagamento' => 'pix'], 'humano');
    verdadeiro($r->ok, $r->mensagem . json_encode($r->erros));
    $d = Repositorios::despesas()->encontrar((int) $r->id);
    igual('pix', $d['meio_pagamento']);
    igual(hoje(), $d['data_pagamento']);
});

teste('despesa: marcar como paga exige meio, carimba a data e reabrir limpa a data', function () {
    bancoComSeed();
    $categoria = novaCategoriaDespesa();
    $x = new ActionExecutor();
    $id = (int) $x->criar('despesas', ['descricao' => 'Software', 'categoria_id' => $categoria, 'valor' => '59,90', 'vencimento' => '2026-10-15', 'forma_pagamento' => 'recorrente'], 'humano')->id;

    $semMeio = $x->atualizar('despesas', $id, ['status' => 'pago'], 'humano');
    igual(false, $semMeio->ok);

    $paga = $x->atualizar('despesas', $id, ['status' => 'pago', 'meio_pagamento' => 'cartao_credito', 'forma_pagamento' => 'recorrente'], 'humano');
    verdadeiro($paga->ok, $paga->mensagem . json_encode($paga->erros));
    $d = Repositorios::despesas()->encontrar($id);
    igual(hoje(), $d['data_pagamento']);
    igual('cartao_credito', $d['meio_pagamento']);

    $reaberta = $x->atualizar('despesas', $id, ['status' => 'pendente'], 'humano');
    verdadeiro($reaberta->ok, $reaberta->mensagem . json_encode($reaberta->erros));
    igual(null, Repositorios::despesas()->encontrar($id)['data_pagamento']);
});

teste('despesa: resumo separa a pagar, vencidas e pagas no mês; filtro "vencidas" lista só as atrasadas', function () {
    bancoComSeed();
    $categoria = novaCategoriaDespesa();
    $x = new ActionExecutor();
    $nova = static fn (array $d) => $x->criar('despesas', $d + ['categoria_id' => $categoria, 'descricao' => 'Conta'], 'humano');

    $nova(['valor' => '100,00', 'vencimento' => date('Y-m-d', strtotime('-3 days'))]);           // vencida
    $nova(['valor' => '200,00', 'vencimento' => date('Y-m-d', strtotime('+5 days'))]);           // a vencer
    $nova(['valor' => '50,00', 'vencimento' => hoje(), 'status' => 'pago', 'meio_pagamento' => 'dinheiro']); // paga hoje
    $nova(['valor' => '999,00', 'vencimento' => date('Y-m-d', strtotime('-1 day')), 'status' => 'cancelado']); // cancelada: fora dos totais

    $r = Repositorios::despesas()->resumo(hoje(), date('Y-m-01'), date('Y-m-t'));
    igual(30000, $r['a_pagar']);
    igual(10000, $r['vencidas']);
    igual(5000, $r['pago_no_mes']);

    $vencidas = Repositorios::despesas()->listar(['filtros' => ['situacao' => 'vencidas']]);
    igual(1, $vencidas['total']);
    igual(10000, (int) $vencidas['linhas'][0]['valor']);
});
