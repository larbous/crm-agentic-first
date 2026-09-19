<?php

declare(strict_types=1);

/**
 * Menu suspenso (Basecoat .dropdown-menu).
 * $gatilho_html: conteúdo do botão que abre o menu. $itens: lista de
 *   ['rotulo' => '', 'href' => '', 'icone' => '', 'atalho' => '', 'perigo' => bool, 'attrs' => []]
 *   ['grupo' => 'Título'] (cabeçalho de grupo)  |  ['separador' => true]
 * Opções: classe_gatilho, classe_popover, alinhar (start|end).
 */
function menu(string $id, string $gatilho_html, array $itens, array $o = []): string
{
    $html = '<div id="' . e($id) . '" class="dropdown-menu">';
    $html .= '<button type="button" id="' . e($id) . '-gatilho" aria-haspopup="menu" aria-controls="' . e($id) . '-menu"'
        . ' aria-expanded="false" class="' . e($o['classe_gatilho'] ?? 'btn') . '"'
        . ' data-variant="' . e($o['variante_gatilho'] ?? (isset($o['classe_gatilho']) ? 'ghost' : 'outline')) . '"' . '>' . $gatilho_html . '</button>';
    $html .= '<div id="' . e($id) . '-popover" data-popover aria-hidden="true"'
        . (isset($o['alinhar']) ? ' data-align="' . e($o['alinhar']) . '"' : '') . '>';
    $html .= '<div role="menu" id="' . e($id) . '-menu" aria-labelledby="' . e($id) . '-gatilho">';

    foreach ($itens as $item) {
        if (!empty($item['separador'])) {
            $html .= '<hr role="separator">';
            continue;
        }
        if (isset($item['grupo'])) {
            $html .= '<div role="heading">' . e($item['grupo']) . '</div>';
            continue;
        }
        $attrs = array_merge(['role' => 'menuitem', 'data-variant' => !empty($item['perigo']) ? 'destructive' : null], $item['attrs'] ?? []);
        $conteudo = (isset($item['icone']) ? icone($item['icone']) : '') . e($item['rotulo'] ?? '')
            . (isset($item['atalho']) ? '<span data-shortcut>' . e($item['atalho']) . '</span>' : '');
        if (isset($item['href'])) {
            $html .= '<a href="' . e($item['href']) . '"' . attrs_html($attrs) . '>' . $conteudo . '</a>';
        } else {
            $html .= '<div' . attrs_html($attrs) . '>' . $conteudo . '</div>';
        }
    }
    return $html . '</div></div></div>';
}
