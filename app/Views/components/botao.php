<?php

declare(strict_types=1);

/**
 * Botão (Basecoat .btn). Opções: variante (primary|secondary|outline|ghost|link|destructive),
 * tamanho (xs|sm|default|lg|icon|icon-sm|icon-xs|icon-lg), href (vira <a>), icone,
 * tipo (button|submit), classe, attrs, rotulo_html (rótulo já em HTML, sem escape).
 */
function botao(string $rotulo, array $o = []): string
{
    $variante = $o['variante'] ?? 'primary';
    $tamanho = $o['tamanho'] ?? 'default';
    $soIcone = str_starts_with($tamanho, 'icon') && isset($o['icone']);
    $conteudo = (isset($o['icone']) ? icone($o['icone']) : '')
        . ($soIcone ? '' : ($o['rotulo_html'] ?? e($rotulo)));

    $attrs = array_merge([
        'class'        => classes('btn', $o['classe'] ?? ''),
        'data-variant' => $variante,
        'data-size'    => $tamanho === 'default' ? null : $tamanho,
    ], $o['attrs'] ?? []);

    if (str_starts_with($tamanho, 'icon') && $rotulo !== '' && !isset($attrs['aria-label'])) {
        $attrs['aria-label'] = $rotulo;
    }

    if (isset($o['href'])) {
        $attrs['href'] = $o['href'];
        return '<a' . attrs_html($attrs) . '>' . $conteudo . '</a>';
    }
    $attrs['type'] = $o['tipo'] ?? 'button';
    return '<button' . attrs_html($attrs) . '>' . $conteudo . '</button>';
}
