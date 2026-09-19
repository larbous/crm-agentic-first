<?php

declare(strict_types=1);

/**
 * Badge (Basecoat .badge). Variantes do Basecoat: primary|secondary|outline|destructive|ghost.
 * Variantes do domínio (success|warning|info) usam as variáveis do tema via classes próprias.
 */
function badge(string $texto, string $variante = 'secondary', array $o = []): string
{
    $dominio = ['success', 'warning', 'info'];
    $ehDominio = in_array($variante, $dominio, true);
    $attrs = array_merge([
        'class'        => classes('badge', $ehDominio ? 'badge-' . $variante : '', $o['classe'] ?? ''),
        'data-variant' => $ehDominio ? 'outline' : $variante,
    ], $o['attrs'] ?? []);
    return '<span' . attrs_html($attrs) . '>' . (isset($o['icone']) ? icone($o['icone']) : '') . e($texto) . '</span>';
}
