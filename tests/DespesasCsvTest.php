<?php

declare(strict_types=1);

use App\Repositories\Repositorios;
use App\Services\DespesasCsv;

// Importação de despesas por planilha CSV (todo-ou-nada, via ActionExecutor).

const CSV_CABECALHO = "descricao,categoria,fornecedor,valor,vencimento,situacao,data_pagamento,meio_pagamento,forma_pagamento,notas\n";

function importarCsv(string $csv, bool $criarCategorias = false, bool $gravar = true): array
{
    $lido = DespesasCsv::ler($csv);
    igual([], $lido['erros'], 'leitura');
    return (new DespesasCsv())->importar($lido['linhas'], $criarCategorias, $gravar);
}

teste('csv despesas: o modelo do repositório é válido e importa sem erro (só validar)', function () {
    bancoComSeed();
    $r = importarCsv(DespesasCsv::modelo(), false, false);
    igual([], $r['erros']);
    igual(4, $r['total']);
    igual(0, Repositorios::despesas()->listar([])['total'], 'só validar não grava');
});

teste('csv despesas: importa linhas (vírgula), com valor em pt-BR, situação, meio e forma', function () {
    bancoComSeed();
    $csv = CSV_CABECALHO
        . "Aluguel,Aluguel e condomínio,Imobiliária,\"1.850,00\",05/01/2025,Paga,05/01/2025,Boleto,Recorrente,\n"
        . "Notebook,Equipamentos e manutenção,Loja,\"420,00\",15/02/2025,,,,Parcelado,Parcela 1 de 10\n";
    $r = importarCsv($csv);
    verdadeiro($r['gravadas'], json_encode($r['erros']));

    $lista = Repositorios::despesas()->listar(['ordem' => 'vencimento', 'dir' => 'asc'])['linhas'];
    igual(2, count($lista));
    igual(185000, (int) $lista[0]['valor']);
    igual('pago', $lista[0]['status']);
    igual('2025-01-05', $lista[0]['data_pagamento']);
    igual('boleto', $lista[0]['meio_pagamento']);
    igual('recorrente', $lista[0]['forma_pagamento']);
    igual('pendente', $lista[1]['status']); // sem situação nem data de pagamento
    igual('parcelado', $lista[1]['forma_pagamento']);
    igual('2025-02-15', $lista[1]['vencimento']);
});

teste('csv despesas: aceita ponto e vírgula, Windows-1252 e cabeçalho com acento/outra ordem', function () {
    bancoComSeed();
    $utf8 = "Descrição;Vencimento;Valor;Categoria;Situação;Meio de pagamento\nInternet;10/03/2025;R\$ 120,00;Água, luz, internet e telefone;Paga;Pix\n";
    $r = importarCsv(mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'));
    verdadeiro($r['gravadas'], json_encode($r['erros']));
    $d = Repositorios::despesas()->listar([])['linhas'][0];
    igual(12000, (int) $d['valor']);
    igual('pix', $d['meio_pagamento']);
    igual('Água, luz, internet e telefone', $d['categoria_nome']);
});

teste('csv despesas: todo-ou-nada — uma linha com erro impede todas e aponta o número da linha', function () {
    bancoComSeed();
    $csv = CSV_CABECALHO
        . "Boa,Contabilidade,,\"300,00\",01/04/2025,A pagar,,,,\n"
        . "Ruim,Categoria inexistente,,\"abc\",31/02/2025,Talvez,,Cheque,,\n";
    $r = importarCsv($csv);
    igual(false, $r['ok']);
    igual(false, $r['gravadas']);
    igual(0, Repositorios::despesas()->listar([])['total'], 'nada gravado');
    $texto = implode(' | ', $r['erros']);
    contem('Linha 3', $texto);
    contem('Categoria inexistente', $texto);
    contem('Talvez', $texto);
    contem('Cheque', $texto);
    verdadeiro(!str_contains($texto, 'Linha 2'), 'a linha 2 estava correta');
});

teste('csv despesas: paga sem meio de pagamento é erro; categoria nova só com a opção de criar', function () {
    bancoComSeed();
    $csv = CSV_CABECALHO . "Luz,Categoria Nova,,\"80,00\",01/05/2025,Paga,01/05/2025,,,\n";
    $r = importarCsv($csv);
    igual(false, $r['ok']);
    contem('não existe', implode(' ', $r['erros']));

    $comCriar = importarCsv($csv, true);
    igual(false, $comCriar['ok'], 'ainda falta o meio de pagamento');
    contem('meio de pagamento', mb_strtolower(implode(' ', $comCriar['erros'])));
    $nomes = array_column(Repositorios::para('categorias_despesa')->todas(), 'nome');
    verdadeiro(!in_array('Categoria Nova', $nomes, true), 'a categoria criada durante a validação foi desfeita');

    $ok = importarCsv(CSV_CABECALHO . "Luz,Categoria Nova,,\"80,00\",01/05/2025,Paga,01/05/2025,Pix,,\n", true);
    verdadeiro($ok['gravadas'], json_encode($ok['erros']));
    igual(['Categoria Nova'], $ok['categorias_novas']);
    verdadeiro(in_array('Categoria Nova', array_column(Repositorios::para('categorias_despesa')->todas(), 'nome'), true));
});

teste('csv despesas: cabeçalho sem coluna obrigatória e planilha vazia são recusados na leitura', function () {
    $semValor = DespesasCsv::ler("descricao,categoria,vencimento\nX,Y,01/01/2025\n");
    igual([], $semValor['linhas']);
    contem('valor', implode(' ', $semValor['erros']));

    $vazia = DespesasCsv::ler(CSV_CABECALHO . ",,,,,,,,,\n");
    contem('nenhuma despesa', implode(' ', $vazia['erros']));
});
