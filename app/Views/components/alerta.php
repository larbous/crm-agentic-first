<?php

declare(strict_types=1);

/** Alerta (Basecoat .alert). Variantes: default | destructive. */
function alerta(string $titulo, string $descricao = '', string $variante = 'default', ?string $icone = null): string
{
    $icone ??= $variante === 'destructive' ? 'circle-alert' : 'info';
    $html = '<div class="alert" role="' . ($variante === 'destructive' ? 'alert' : 'status') . '"'
        . ($variante === 'default' ? '' : ' data-variant="' . e($variante) . '"') . '>' . icone($icone);
    $html .= '<h2>' . e($titulo) . '</h2>';
    if ($descricao !== '') {
        $html .= '<section><p>' . e($descricao) . '</p></section>';
    }
    return $html . '</div>';
}
