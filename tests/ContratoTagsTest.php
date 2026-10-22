<?php

declare(strict_types=1);

use App\Controllers\DocumentoDados;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Html;
use App\Services\TagsPerfex;
use App\Services\Variaveis;

teste('TagsPerfex: traduz as tags do Perfex, tira o "R$" repetido e deixa o que não conhece', function () {
    $antigo = '<p>{companyname} e {client_company} (CNPJ {client_vat_number}), {client_address}, {client_city}/{client_state}, {client_zip}. '
        . 'Valor de <span class="placeholder">R$&#160; {contract_contract_value}</span> ({contracts_valor_extenso}), dia&#160; {contracts_dia_de_pagamento}. '
        . 'Promo R$ {contracts_valor_promocional}, {contracts_percentual_deconto}. {tag_desconhecida}</p>';
    $novo = TagsPerfex::converter($antigo);
    contem('{larbous.nome} e {empresa.nome} (CNPJ {empresa.cnpj}), {empresa.endereco_linha}, {empresa.cidade}/{empresa.uf}, {empresa.cep}.', $novo);
    contem('<span class="placeholder">{contrato.valor_total}</span> ({contrato.valor_total_extenso}), dia&#160; {contrato.dia_vencimento_pagamento}.', $novo);
    contem('Promo {contrato.valor_promocional}, {contrato.percentual_desconto}.', $novo);
    igual(['{tag_desconhecida}'], TagsPerfex::restantes($novo));
    // Importado do dump, o espaço sem quebra já vem como caractere (não como entidade).
    igual('Valor {contrato.valor_total}.', TagsPerfex::converter("Valor R$\u{00A0} {contract_contract_value}."));

    // Toda variável de destino do mapa existe de verdade no motor de variáveis.
    $catalogo = array_merge(...array_values(Variaveis::catalogo()));
    foreach (array_unique(array_values(TagsPerfex::MAPA)) as $destino) {
        verdadeiro(isset($catalogo['{' . $destino . '}']), "variável inexistente: {$destino}");
    }
});

teste('Contrato: variáveis do texto são preenchidas na impressão, com campos novos de contrato e empresa', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x, 'Padaria Central', [
        'razao_social' => 'Padaria Central Ltda', 'cnpj' => '11.222.333/0001-81', 'logradouro' => 'Rua das Flores', 'numero' => '10', 'bairro' => 'Centro',
        'cidade' => 'Santos', 'uf' => 'SP', 'cep' => '11010-000', 'responsavel_nome' => 'Maria Dona', 'responsavel_cpf' => CPF_VALIDO, 'responsavel_rg' => '12.345.678-9',
    ]);
    $cid = (int) $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $emp])->id;
    $r = $x->criar('contratos', [
        'titulo' => 'Contrato teste', 'recorrencia' => 'mensal', 'empresa_id' => $emp, 'contato_id' => $cid, 'valor_total' => '1.250,00', 'dia_vencimento_pagamento' => 10,
        'valor_promocional' => '99,90', 'percentual_desconto' => '30', 'periodo_desconto' => '3 meses', 'data_inicio' => '2026-03-01',
        'representante_nome' => 'João Rep', 'representante_cpf' => CPF_VALIDO, 'representante_rg' => '99',
        'conteudo' => '<p align="center">{empresa.razao} ({empresa.cnpj}), {empresa.endereco_linha}, {empresa.cidade}/{empresa.uf}, {empresa.cep}; resp. {empresa.responsavel_nome}, CPF {empresa.responsavel_cpf}.</p>'
            . '<p>{contato.nome} {contato.sobrenome}: {contrato.valor_total} ({contrato.valor_total_extenso}), dia {contrato.dia_vencimento_pagamento}, desde {contrato.data_inicio}. '
            . 'Promo {contrato.valor_promocional} = {contrato.percentual_desconto} por {contrato.periodo_desconto}. Rep.: {contrato.representante_nome} {contrato.representante_cpf} RG {contrato.representante_rg}. {contrato.link} {nada.existe}</p>',
    ]);
    verdadeiro($r->ok, json_encode($r->erros));
    $c = Repositorios::contratos()->encontrar((int) $r->id);
    igual('99,90', substr(centavos_para_texto((int) $c['valor_promocional']), 0, 5));

    $corpo = DocumentoDados::contrato($c)['corpo'];
    contem('<p align="center">Padaria Central Ltda (11.222.333/0001-81), Rua das Flores, 10, Centro, Santos/SP, 11010-000; resp. Maria Dona, CPF 529.982.247-25.</p>', $corpo);
    contem('Ana Souza: ' . moeda(125000) . ' (', $corpo);
    contem('dia 10, desde 01/03/2026', $corpo);
    contem('Promo ' . moeda(9990) . ' = ' . percentual_br(3000) . ' por 3 meses', $corpo);
    contem('Rep.: João Rep 529.982.247-25 RG 99', $corpo);
    contem('/c/' . $c['token_publico'], $corpo);
    contem('{nada.existe}', $corpo, 'variável desconhecida fica visível');

    // O texto gravado continua com as variáveis (a edição não perde as tags).
    contem('{empresa.razao}', $c['conteudo']);

    // Ao assinar, o texto assinado fica congelado, sem variáveis.
    verdadeiro($x->enviarContrato((int) $r->id)->ok);
    verdadeiro($x->assinarContrato((int) $r->id, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO])->ok);
    $assinado = Repositorios::contratos()->encontrar((int) $r->id)['conteudo'];
    contem('Padaria Central Ltda (11.222.333/0001-81)', $assinado);
    verdadeiro(!str_contains($assinado, '{empresa.razao}'), 'variáveis resolvidas na assinatura');
    contem('{nada.existe}', $assinado);
});

teste('Html::sanitizar mantém alinhamento e tipo de lista do editor e remove o resto', function () {
    igual('<p align="center">a</p>', Html::sanitizar('<p align="center" style="color:red" class="x">a</p>'));
    igual('<h3>b</h3>', Html::sanitizar('<h3 align="evil">b</h3>'));
    igual('<ol type="a"><li>x</li></ol>', Html::sanitizar('<ol type="a" onclick="x()"><li>x</li></ol>'));
    igual('<ol><li>x</li></ol>', Html::sanitizar('<ol type="zzz"><li>x</li></ol>'));
});
