<?php
/**
 * DRE por cliente (Fase 17): relatório calculado — Receita (cobranças pagas) − Custo (lançamentos) = Margem, por
 * empresa, no período escolhido.
 * @var string $de
 * @var string $ate
 * @var int $empresaFiltro
 * @var array<int,string> $empresasOpcoes
 * @var list<array{empresa_id:int,empresa_nome:string,receita:int,custo:int,margem:int}> $linhas
 * @var array{receita:int,custo:int,margem:int} $totais
 */
$linhasTabela = [];
foreach ($linhas as $l) {
    $linhasTabela[] = [
        'empresa' => ['html' => link_para('/empresas/' . $l['empresa_id'], $l['empresa_nome'])],
        'receita' => ['html' => e(moeda($l['receita']))],
        'custo'   => ['html' => e(moeda($l['custo']))],
        'margem'  => ['html' => '<span class="' . ($l['margem'] < 0 ? 'text-destructive' : 'text-success') . ' font-medium">' . e(moeda($l['margem'])) . '</span>'],
    ];
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">DRE por cliente</h1>
        <p class="text-muted-foreground">Receita (cobranças pagas) menos custo lançado, por empresa, no período.</p>
    </div>
</div>

<?= card(['corpo_html' =>
    '<form method="get" class="flex flex-wrap items-end gap-3">'
    . '<div>' . campo(['nome' => 'de', 'rotulo' => 'De', 'tipo' => 'date', 'valor' => $de]) . '</div>'
    . '<div>' . campo(['nome' => 'ate', 'rotulo' => 'Até', 'tipo' => 'date', 'valor' => $ate]) . '</div>'
    . '<div class="min-w-56">' . campo(['nome' => 'empresa_id', 'rotulo' => 'Empresa', 'controle_html' => select('empresa_id', $empresasOpcoes, $empresaFiltro ?: null, ['placeholder' => 'Todas as empresas'])]) . '</div>'
    . botao('Filtrar', ['tipo' => 'submit', 'icone' => 'list-filter'])
    . '</form>'
]) ?>

<div class="mt-4 grid gap-4 md:grid-cols-3">
    <?= card(['tamanho' => 'sm', 'titulo' => 'Receita no período', 'corpo_html' => '<p class="text-2xl font-semibold">' . e(moeda($totais['receita'])) . '</p>']) ?>
    <?= card(['tamanho' => 'sm', 'titulo' => 'Custo no período', 'corpo_html' => '<p class="text-2xl font-semibold">' . e(moeda($totais['custo'])) . '</p>']) ?>
    <?= card(['tamanho' => 'sm', 'titulo' => 'Margem', 'corpo_html' => '<p class="text-2xl font-semibold ' . ($totais['margem'] < 0 ? 'text-destructive' : 'text-success') . '">' . e(moeda($totais['margem'])) . '</p>']) ?>
</div>

<div class="mt-4">
    <?php if ($linhasTabela === []): ?>
        <?= vazio('Sem movimento no período', 'Nenhuma cobrança paga ou custo lançado no período e filtro escolhidos.', ['icone' => 'chart-bar']) ?>
    <?php else: ?>
        <?= card(['corpo_html' => tabela(['empresa' => 'Empresa', 'receita' => 'Receita', 'custo' => 'Custo', 'margem' => 'Margem'], $linhasTabela)]) ?>
    <?php endif; ?>
</div>
