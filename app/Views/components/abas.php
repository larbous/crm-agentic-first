<?php

declare(strict_types=1);

/**
 * Abas (Basecoat .tabs). $abas: lista de ['rotulo' => '', 'html' => '', 'contagem' => int|null].
 * O JS do Basecoat cuida da troca de painéis e do teclado.
 */
function abas(string $id, array $abas, int $ativa = 0, array $o = []): string
{
    $html = '<div class="tabs' . (isset($o['classe']) ? ' ' . e($o['classe']) : '') . '" id="' . e($id) . '"><nav role="tablist" aria-orientation="horizontal"'
        . (isset($o['linha']) ? ' data-variant="line"' : '') . '>';
    foreach ($abas as $i => $aba) {
        $sel = $i === $ativa;
        $html .= '<button type="button" role="tab" id="' . e($id) . '-tab-' . ($i + 1) . '" aria-controls="' . e($id) . '-panel-' . ($i + 1)
            . '" aria-selected="' . ($sel ? 'true' : 'false') . '" tabindex="' . ($sel ? '0' : '-1') . '">' . e($aba['rotulo'])
            . (isset($aba['contagem']) ? ' <span class="text-muted-foreground text-xs">(' . (int) $aba['contagem'] . ')</span>' : '')
            . '</button>';
    }
    $html .= '</nav>';
    foreach ($abas as $i => $aba) {
        $sel = $i === $ativa;
        $html .= '<div role="tabpanel" id="' . e($id) . '-panel-' . ($i + 1) . '" aria-labelledby="' . e($id) . '-tab-' . ($i + 1)
            . '" tabindex="-1" class="pt-4"' . ($sel ? '' : ' hidden') . '>' . $aba['html'] . '</div>';
    }
    return $html . '</div>';
}
