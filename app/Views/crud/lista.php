<?php
/**
 * Lista genérica (empresas, contatos, negócios).
 * @var array $schema
 * @var string $rota
 * @var string $q
 * @var array $filtros
 * @var int $tagId
 * @var array $tagsOpcoes
 * @var array $colunasTabela
 * @var list<array> $linhas
 * @var array $todasColunas
 * @var list<string> $visiveis
 * @var string $ordem
 * @var string $dir
 * @var array $resultado
 * @var callable $urlPagina
 * @var callable $urlOrdem
 * @var string $acoesExtras
 */
$feminino = $schema['genero'] === 'f';
$temFiltro = $q !== '' || $tagId > 0 || array_filter(array_column($filtros, 'valor')) !== [];

if ($tagsOpcoes !== []) {
    $filtros['tag_id'] = ['rotulo' => 'Tag', 'opcoes' => $tagsOpcoes, 'valor' => $tagId ?: ''];
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo"><?= e($schema['plural']) ?></h1>
        <p class="text-muted-foreground"><?= (int) $resultado['total'] ?> <?= $resultado['total'] === 1 ? 'registro' : 'registros' ?><?= $temFiltro ? ' (filtrado)' : '' ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= $acoesExtras ?>
        <?= botao('Colunas', ['variante' => 'outline', 'icone' => 'settings', 'attrs' => ['data-abrir-modal' => 'modal-colunas']]) ?>
        <?= botao('Nov' . ($feminino ? 'a ' : 'o ') . mb_strtolower($schema['singular']), ['href' => url($rota . '/nova'), 'icone' => 'plus']) ?>
    </div>
</div>

<?= barra_filtros([
    'acao' => url($rota), 'q' => $q, 'filtros' => $filtros, 'limpar' => url($rota),
    'placeholder' => 'Buscar em ' . mb_strtolower($schema['plural']) . '…',
    'ocultos' => ['ordem' => $ordem, 'dir' => $ordem !== '' ? $dir : null],
]) ?>

<?= data_table($colunasTabela, $linhas, [
    'id'        => 'lista-' . $rota,
    'ordenar'   => ['ordem' => $ordem, 'dir' => $dir, 'url' => $urlOrdem],
    'rodape'    => $rodape ?? [],
    'vazio_html' => vazio(
        $temFiltro ? 'Nada encontrado' : 'Nenhum' . ($feminino ? 'a ' : ' ') . mb_strtolower($schema['singular']) . ' ainda',
        $temFiltro ? 'Ajuste a busca ou os filtros.' : 'Cadastre ' . ($feminino ? 'a primeira ' : 'o primeiro ') . mb_strtolower($schema['singular']) . ' para começar.',
        ['icone' => 'inbox', 'acao_html' => $temFiltro
            ? botao('Limpar filtros', ['href' => url($rota), 'variante' => 'outline'])
            : botao('Nov' . ($feminino ? 'a ' : 'o ') . mb_strtolower($schema['singular']), ['href' => url($rota . '/nova'), 'icone' => 'plus'])],
    ),
]) ?>

<?= paginacao($resultado['pagina'], $resultado['paginas'], $resultado['total'], $resultado['por_pagina'], $urlPagina) ?>

<?php
$caixas = '';
foreach ($todasColunas as $chave => $rotulo) {
    $caixas .= '<label class="flex items-center gap-2 py-1"><input type="checkbox" class="input" name="colunas[]" value="' . e($chave) . '"'
        . (in_array($chave, $visiveis, true) ? ' checked' : '') . '> ' . e($rotulo) . '</label>';
}
echo modal('modal-colunas', [
    'titulo' => 'Colunas da lista',
    'descricao' => 'Escolha quais colunas aparecem em ' . mb_strtolower($schema['plural']) . '.',
    'corpo_html' => '<form id="form-colunas" method="post" action="' . e(url($rota . '/colunas')) . '" class="grid gap-1 sm:grid-cols-2">'
        . csrf_field() . '<input type="hidden" name="voltar" value="' . e($_SERVER['REQUEST_URI'] ?? $rota) . '">' . $caixas . '</form>',
    'rodape_html' => botao('Cancelar', ['variante' => 'outline', 'attrs' => ['onclick' => "this.closest('dialog').close()"]])
        . botao('Salvar', ['tipo' => 'submit', 'attrs' => ['form' => 'form-colunas']]),
]);
?>
