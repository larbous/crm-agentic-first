<?php

declare(strict_types=1);

/**
 * Estado vazio (Basecoat .empty, estilizado por components/empty.css).
 * Opções: descricao, icone, acao_html (botões).
 */
function vazio(string $titulo, string $descricao = '', array $o = []): string
{
    $html = '<div class="empty"><header>';
    if (isset($o['icone'])) {
        $html .= '<figure>' . icone($o['icone'], 'size-6') . '</figure>';
    }
    $html .= '<h3>' . e($titulo) . '</h3>';
    if ($descricao !== '') {
        $html .= '<p>' . e($descricao) . '</p>';
    }
    $html .= '</header>';
    if (isset($o['acao_html'])) {
        $html .= '<section>' . $o['acao_html'] . '</section>';
    }
    return $html . '</div>';
}
