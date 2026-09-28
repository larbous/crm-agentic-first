<?php

declare(strict_types=1);

use App\Services\DespesasCsv;
use App\Services\Schema;

/**
 * Importação de despesas por planilha (CSV).
 * @var array|null $resultado   null (primeira visita) ou ['ok', 'erros', 'total'?, 'gravadas'?, 'categorias_novas'?]
 * @var list<string> $categorias
 * @var bool $criarCategorias
 * @var bool $somenteValidar
 */

$blocoResultado = '';
if ($resultado !== null) {
    if (!empty($resultado['ok'])) {
        $blocoResultado = alerta(
            'Planilha válida',
            $resultado['total'] . ($resultado['total'] === 1 ? ' despesa pronta' : ' despesas prontas') . ' para importar. Nada foi gravado (modo "só validar"): desmarque essa opção e envie de novo para importar.'
            . ($resultado['categorias_novas'] !== [] ? ' Categorias que seriam criadas: ' . implode(', ', $resultado['categorias_novas']) . '.' : ''),
        );
    } else {
        $erros = array_slice($resultado['erros'], 0, 50);
        $mais = count($resultado['erros']) - count($erros);
        $lista = '<ul class="list-disc space-y-1 pl-5 text-sm">';
        foreach ($erros as $erro) {
            $lista .= '<li>' . e($erro) . '</li>';
        }
        $lista .= ($mais > 0 ? '<li>… e mais ' . $mais . ' erro(s).</li>' : '') . '</ul>';
        $blocoResultado = '<div class="alert" role="alert" data-variant="destructive">' . icone('circle-alert')
            . '<h2>Nada foi importado</h2><section><p>Corrija a planilha e envie de novo — enquanto houver erro, nenhuma linha é gravada (assim você não duplica nada ao reenviar).</p>' . $lista . '</section></div>';
    }
}

$colunas = [
    ['descricao', 'obrigatória', 'O que foi pago. Ex.: Aluguel da sala.'],
    ['categoria', 'obrigatória', 'Uma das categorias cadastradas (veja abaixo). Não precisa respeitar maiúsculas/acentos.'],
    ['fornecedor', 'opcional', 'Quem recebeu. Texto livre.'],
    ['valor', 'obrigatória', 'Em reais: 1.850,00 ou 1850,00 (o "R$" é aceito).'],
    ['vencimento', 'obrigatória', 'Data no formato dd/mm/aaaa (ou aaaa-mm-dd). Para o que já foi pago à vista, a data da compra.'],
    ['situacao', 'opcional', 'A pagar, Paga ou Cancelada. Vazia: "Paga" se houver data de pagamento, senão "A pagar".'],
    ['data_pagamento', 'opcional', 'Quando foi paga (dd/mm/aaaa). Só vale para despesas pagas; vazia em uma paga = data de hoje.'],
    ['meio_pagamento', 'obrigatório se paga', implode(', ', Schema::opcoes('meio_pagamento')) . '.'],
    ['forma_pagamento', 'opcional', implode(', ', Schema::opcoes('forma_pagamento_despesa')) . '. Vazia = À vista.'],
    ['notas', 'opcional', 'Observações livres (ex.: "Parcela 3 de 10").'],
];
$tabelaColunas = '<div class="table-container"><table class="table"><thead><tr><th>Coluna</th><th>Preenchimento</th><th>Como preencher</th></tr></thead><tbody>';
foreach ($colunas as [$nome, $regra, $ajuda]) {
    $tabelaColunas .= '<tr><td><code>' . e($nome) . '</code></td><td>' . e($regra) . '</td><td>' . e($ajuda) . '</td></tr>';
}
$tabelaColunas .= '</tbody></table></div>';

$formulario = '<form method="post" action="' . e(url('/financeiro/despesas/importar')) . '" enctype="multipart/form-data" class="grid gap-4">' . csrf_field()
    . campo(['nome' => 'arquivo', 'id' => 'desp-arquivo', 'rotulo' => 'Arquivo CSV', 'tipo' => 'file', 'obrigatorio' => true, 'attrs' => ['accept' => '.csv,text/csv,.txt']])
    . campo(['nome' => 'criar_categorias', 'id' => 'desp-criar-cat', 'rotulo' => 'Criar as categorias que não existirem', 'tipo' => 'checkbox', 'valor' => $criarCategorias ? 1 : 0,
             'ajuda' => 'Desmarcado, uma categoria com nome desconhecido vira erro (útil para pegar erros de digitação).'])
    . campo(['nome' => 'somente_validar', 'id' => 'desp-validar', 'rotulo' => 'Só validar (não gravar nada)', 'tipo' => 'checkbox', 'valor' => $somenteValidar ? 1 : 0,
             'ajuda' => 'Confere a planilha inteira e mostra os erros sem importar. Recomendado na primeira vez.'])
    . '<div>' . botao('Enviar planilha', ['tipo' => 'submit', 'icone' => 'upload']) . '</div></form>';
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Importar despesas</h1>
        <p class="text-muted-foreground">Recupere o histórico a partir de uma planilha. Tudo ou nada: se houver algum erro, nenhuma linha é gravada.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Baixar modelo (CSV)', ['href' => url('/financeiro/despesas/modelo-planilha'), 'variante' => 'outline', 'icone' => 'download']) ?>
        <?= botao('Voltar', ['href' => url('/financeiro/despesas'), 'variante' => 'ghost']) ?>
    </div>
</div>

<?= $blocoResultado ?>

<div class="grid gap-4">
    <?= card(['titulo' => 'Enviar planilha', 'corpo_html' => $formulario]) ?>
    <?= card([
        'titulo' => 'Como preparar a planilha',
        'corpo_html' => '<ul class="mb-4 list-disc space-y-1 pl-5 text-sm">'
            . '<li>Baixe o modelo, abra no Google Planilhas (Arquivo → Importar → Upload) e apague as linhas de exemplo (começam com "Exemplo:").</li>'
            . '<li>Preencha uma despesa por linha, mantendo os nomes das colunas da primeira linha. Parcelas e meses de uma despesa recorrente ficam em linhas separadas.</li>'
            . '<li>Exporte: Arquivo → Baixar → Valores separados por vírgula (.csv) e envie aqui.</li>'
            . '</ul>' . $tabelaColunas
            . '<p class="mt-4 text-sm"><strong>Categorias cadastradas:</strong> ' . e(implode(', ', $categorias)) . '. Você edita essa lista em Configurações → Categorias de despesa.</p>'
            . '<p class="text-muted-foreground mt-2 text-sm">Limites: até ' . DespesasCsv::MAX_LINHAS . ' linhas e 1 MB por arquivo. Aceita separador vírgula, ponto e vírgula ou tab, em UTF-8 ou Windows-1252 (Excel).</p>',
    ]) ?>
</div>
