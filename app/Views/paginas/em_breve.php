<?php
/**
 * @var string $titulo
 * @var string $icone
 * @var string $fase
 */
?>
<div class="page-cabecalho">
    <h1 class="page-titulo"><?= e($titulo) ?></h1>
</div>
<?= card([
    'corpo_html' => vazio($titulo . ' ainda está vazio', 'Esta tela será implementada na ' . $fase . ' do roadmap.', ['icone' => $icone]),
]) ?>
