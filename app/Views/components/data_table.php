<?php

declare(strict_types=1);

/**
 * Componente próprio data-table: extensão da table do Basecoat com cabeçalho fixo,
 * ordenação por coluna, seleção de linhas e (na Fase 2) colunas configuráveis.
 * O comportamento vem de /assets/js/table.js.
 *
 * $colunas: [chave => ['rotulo' => '...', 'ordenavel' => bool, 'tipo' => 'texto'|'numero', 'alinhar' => 'direita']]
 * $linhas:  lista de arrays; célula = texto (escapado) ou ['html' => '...', 'valor' => chave de ordenação].
 * Opções: id, selecionavel (bool), ordenar (ordenação no servidor: ['ordem' => chave atual, 'dir' => asc|desc, 'url' => fn(chave, dir): string]), vazio_html (mensagem quando não há linhas), altura_max (classe Tailwind).
 */
function data_table(array $colunas, array $linhas, array $o = []): string
{
    $id = $o['id'] ?? 'dt-' . substr(md5(json_encode(array_keys($colunas)) ?: ''), 0, 6);
    $selecionavel = !empty($o['selecionavel']);

    $html = '<div class="data-table" data-data-table id="' . e($id) . '">';
    $html .= '<div class="data-table-scroll ' . e($o['altura_max'] ?? 'max-h-[70vh]') . '"><table class="table">';
    $html .= '<thead><tr>';
    if ($selecionavel) {
        $html .= '<th class="w-8"><input type="checkbox" class="input" data-select-all aria-label="Selecionar todas as linhas"></th>';
    }
    foreach ($colunas as $chave => $col) {
        $ordenavel = !empty($col['ordenavel']);
        $servidor = $ordenavel && isset($o['ordenar']);
        $atualOrdem = $servidor && ($o['ordenar']['ordem'] ?? '') === $chave;
        $dirAtual = $atualOrdem ? ($o['ordenar']['dir'] ?? 'asc') : null;
        $attrs = attrs_html([
            'data-sort'  => $ordenavel && !$servidor ? ($col['tipo'] ?? 'texto') : null,
            'aria-sort'  => $ordenavel ? ($dirAtual === null ? 'none' : ($dirAtual === 'desc' ? 'descending' : 'ascending')) : null,
            'class'      => ($col['alinhar'] ?? '') === 'direita' ? 'text-right' : null,
        ]);
        $rotulo = e($col['rotulo'] ?? $chave);
        $seta = icone($dirAtual === 'asc' ? 'arrow-up' : ($dirAtual === 'desc' ? 'arrow-down' : 'chevrons-up-down'), 'size-3.5 ' . ($dirAtual === null ? 'opacity-50' : ''));
        if ($servidor) {
            $proxima = $dirAtual === 'asc' ? 'desc' : 'asc';
            $conteudoTh = '<a class="data-table-sort" href="' . e(($o['ordenar']['url'])($chave, $proxima)) . '">' . $rotulo . $seta . '</a>';
        } elseif ($ordenavel) {
            $conteudoTh = '<button type="button" class="data-table-sort">' . $rotulo . $seta . '</button>';
        } else {
            $conteudoTh = $rotulo;
        }
        $html .= '<th' . $attrs . '>' . $conteudoTh . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    foreach ($linhas as $linha) {
        $html .= '<tr>';
        if ($selecionavel) {
            $html .= '<td><input type="checkbox" class="input" data-select-row aria-label="Selecionar linha"></td>';
        }
        foreach ($colunas as $chave => $col) {
            $celula = $linha[$chave] ?? '';
            $eArray = is_array($celula);
            $conteudo = $eArray ? (string) ($celula['html'] ?? '') : e($celula);
            $ordem = $eArray ? ($celula['valor'] ?? strip_tags($conteudo)) : $celula;
            $html .= '<td' . attrs_html([
                'data-value' => !empty($col['ordenavel']) ? (string) $ordem : null,
                'class'      => ($col['alinhar'] ?? '') === 'direita' ? 'text-right' : null,
            ]) . '>' . $conteudo . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    if ($linhas === []) {
        $html .= '<div class="p-8">' . ($o['vazio_html'] ?? vazio('Nada por aqui ainda')) . '</div>';
    }
    return $html . '</div></div>';
}
