<?php

declare(strict_types=1);

/**
 * Card (Basecoat .card). Opções: titulo, descricao, corpo_html, rodape_html, acao_html (canto do cabeçalho),
 * tamanho (sm), classe.
 */
function card(array $o): string
{
    $html = '<div' . attrs_html(['class' => classes('card', $o['classe'] ?? ''), 'data-size' => $o['tamanho'] ?? null]) . '>';
    if (isset($o['titulo']) || isset($o['descricao'])) {
        $html .= '<header>';
        if (isset($o['titulo'])) {
            $html .= '<h2>' . e($o['titulo']) . '</h2>';
        }
        if (isset($o['descricao'])) {
            $html .= '<p>' . e($o['descricao']) . '</p>';
        }
        if (isset($o['acao_html'])) {
            $html .= $o['acao_html'];
        }
        $html .= '</header>';
    }
    if (isset($o['corpo_html'])) {
        $html .= '<section>' . $o['corpo_html'] . '</section>';
    }
    if (isset($o['rodape_html'])) {
        $html .= '<footer>' . $o['rodape_html'] . '</footer>';
    }
    return $html . '</div>';
}
