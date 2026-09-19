<?php

declare(strict_types=1);

/**
 * Paginação com "Mostrando X–Y de Z". $url: fn(int $pagina): string.
 */
function paginacao(int $pagina, int $paginas, int $total, int $porPagina, callable $url): string
{
    if ($total === 0) {
        return '';
    }
    $de = ($pagina - 1) * $porPagina + 1;
    $ate = min($total, $pagina * $porPagina);
    $html = '<nav class="flex flex-wrap items-center justify-between gap-2 pt-2" aria-label="Paginação">';
    $html .= '<p class="text-muted-foreground text-sm">Mostrando ' . $de . '–' . $ate . ' de ' . $total . '</p>';

    if ($paginas > 1) {
        $html .= '<div class="flex items-center gap-1">';
        $html .= botao('Anterior', [
            'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'chevron-left',
            'href' => $pagina > 1 ? $url($pagina - 1) : null, 'attrs' => $pagina > 1 ? [] : ['disabled' => true, 'aria-disabled' => 'true'],
        ] + ($pagina > 1 ? [] : []));
        $inicio = max(1, $pagina - 2);
        $fim = min($paginas, $pagina + 2);
        if ($inicio > 1) {
            $html .= botao('1', ['variante' => 'ghost', 'tamanho' => 'icon-sm', 'href' => $url(1)]);
            $html .= $inicio > 2 ? '<span class="text-muted-foreground px-1">…</span>' : '';
        }
        for ($p = $inicio; $p <= $fim; $p++) {
            $html .= botao((string) $p, [
                'variante' => $p === $pagina ? 'primary' : 'ghost', 'tamanho' => 'icon-sm', 'href' => $url($p),
                'attrs' => $p === $pagina ? ['aria-current' => 'page'] : [],
            ]);
        }
        if ($fim < $paginas) {
            $html .= $fim < $paginas - 1 ? '<span class="text-muted-foreground px-1">…</span>' : '';
            $html .= botao((string) $paginas, ['variante' => 'ghost', 'tamanho' => 'icon-sm', 'href' => $url($paginas)]);
        }
        $html .= botao('Próxima', [
            'variante' => 'outline', 'tamanho' => 'sm', 'rotulo_html' => 'Próxima' . icone('chevron-right'),
            'href' => $pagina < $paginas ? $url($pagina + 1) : null, 'attrs' => $pagina < $paginas ? [] : ['disabled' => true, 'aria-disabled' => 'true'],
        ]);
        $html .= '</div>';
    }
    return $html . '</nav>';
}
