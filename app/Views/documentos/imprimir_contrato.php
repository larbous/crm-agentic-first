<?php
/** Impressão do contrato (A4). Recebe as variáveis de documentos/contrato. */
use App\Core\View;
?>
<div class="documento-barra no-print">
    <div class="flex items-center gap-2">
        <?= botao('Voltar', ['href' => url('/contratos/' . (int) $c['id']), 'variante' => 'outline', 'icone' => 'chevron-left']) ?>
        <span class="text-muted-foreground text-sm">Use “Imprimir” e escolha “Salvar como PDF” no navegador.</span>
    </div>
    <?= botao('Imprimir / salvar PDF', ['icone' => 'printer', 'attrs' => ['data-imprimir' => true]]) ?>
</div>
<?= View::partial('documentos/contrato', get_defined_vars()) ?>
