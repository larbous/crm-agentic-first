<?php

declare(strict_types=1);

/**
 * Ficha de dados (rótulo/valor em grade). $pares: rótulo => texto | ['html' => '...'].
 * Valores vazios são omitidos.
 */
function ficha(array $pares, int $colunas = 2): string
{
    $html = '';
    foreach ($pares as $rotulo => $valor) {
        $conteudo = is_array($valor) ? (string) ($valor['html'] ?? '') : e((string) $valor);
        if (trim(strip_tags($conteudo)) === '' && !str_contains($conteudo, '<svg')) {
            continue;
        }
        $html .= '<div class="min-w-0"><dt class="text-muted-foreground text-xs">' . e($rotulo) . '</dt>'
            . '<dd class="text-sm break-words">' . $conteudo . '</dd></div>';
    }
    if ($html === '') {
        return '<p class="text-muted-foreground text-sm">Nada preenchido.</p>';
    }
    return '<dl class="grid gap-x-6 gap-y-3 ' . ($colunas === 1 ? '' : 'sm:grid-cols-2') . '">' . $html . '</dl>';
}

/** Link para um registro (texto escapado). */
function link_para(string $caminho, string $texto, string $classe = 'underline-offset-4 hover:underline'): string
{
    return '<a class="' . e($classe) . '" href="' . e(url($caminho)) . '">' . e($texto) . '</a>';
}
