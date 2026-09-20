<?php
/** Impressão da proposta (A4). Recebe as variáveis de documentos/proposta. */
use App\Core\View;
?>
<div class="documento-barra no-print">
    <div class="flex items-center gap-2">
        <?= botao('Voltar', ['href' => url('/propostas/' . (int) $p['id']), 'variante' => 'outline', 'icone' => 'chevron-left']) ?>
        <span class="text-muted-foreground text-sm">Use “Imprimir” e escolha “Salvar como PDF” no navegador.</span>
    </div>
    <?= botao('Imprimir / salvar PDF', ['icone' => 'printer', 'attrs' => ['data-imprimir' => true]]) ?>
</div>
<?= View::partial('documentos/proposta', get_defined_vars()) ?>
