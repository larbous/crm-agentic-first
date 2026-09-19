<?php

declare(strict_types=1);

/**
 * Modal (Basecoat .dialog sobre <dialog> nativo). Abrir: document.getElementById(id).showModal()
 * ou um botão com data-abrir-modal="id" (tratado em ui.js).
 * Opções: titulo, descricao, corpo_html, rodape_html, fechar (bool, padrão true), classe.
 */
function modal(string $id, array $o = []): string
{
    $html = '<dialog id="' . e($id) . '" class="' . e(classes('dialog', $o['classe'] ?? '')) . '"'
        . (isset($o['titulo']) ? ' aria-labelledby="' . e($id) . '-titulo"' : '')
        . (isset($o['descricao']) ? ' aria-describedby="' . e($id) . '-descricao"' : '')
        . ' onclick="if (event.target === this) this.close()"><div>';
    if (isset($o['titulo']) || isset($o['descricao'])) {
        $html .= '<header>';
        if (isset($o['titulo'])) {
            $html .= '<h2 id="' . e($id) . '-titulo">' . e($o['titulo']) . '</h2>';
        }
        if (isset($o['descricao'])) {
            $html .= '<p id="' . e($id) . '-descricao">' . e($o['descricao']) . '</p>';
        }
        $html .= '</header>';
    }
    if (isset($o['corpo_html'])) {
        $html .= '<section>' . $o['corpo_html'] . '</section>';
    }
    if (isset($o['rodape_html'])) {
        $html .= '<footer>' . $o['rodape_html'] . '</footer>';
    }
    if (($o['fechar'] ?? true) === true) {
        $html .= '<button type="button" class="btn" data-variant="ghost" data-size="icon-sm" aria-label="Fechar"'
            . ' onclick="this.closest(\'dialog\').close()">' . icone('x') . '</button>';
    }
    return $html . '</div></dialog>';
}
