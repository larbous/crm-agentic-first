<?php

declare(strict_types=1);

/**
 * Tabela simples (Basecoat .table). $colunas: [chave => rótulo]; $linhas: lista de arrays associativos.
 * Uma célula pode ser texto (escapado) ou ['html' => '...'] para marcação já pronta.
 * Opções: legenda, classe.
 */
function tabela(array $colunas, array $linhas, array $o = []): string
{
    $html = '<div class="table-container"><table class="' . e(classes('table', $o['classe'] ?? '')) . '">';
    if (isset($o['legenda'])) {
        $html .= '<caption class="text-muted-foreground mt-4 text-sm">' . e($o['legenda']) . '</caption>';
    }
    $html .= '<thead><tr>';
    foreach ($colunas as $rotulo) {
        $html .= '<th>' . e($rotulo) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($linhas as $linha) {
        $html .= '<tr>';
        foreach (array_keys($colunas) as $chave) {
            $celula = $linha[$chave] ?? '';
            $html .= '<td>' . (is_array($celula) ? (string) ($celula['html'] ?? '') : e($celula)) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}
