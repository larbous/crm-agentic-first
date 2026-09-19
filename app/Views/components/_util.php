<?php

declare(strict_types=1);

/**
 * Utilitários dos helpers de componente (SPEC §12.5).
 * Convenção: todo helper devolve uma string HTML; textos recebidos são escapados,
 * e trechos já em HTML entram apenas por parâmetros nomeados "*_html".
 */

/** Monta atributos HTML: true → atributo booleano; false/null → omitido; demais → escapados. */
function attrs_html(array $attrs): string
{
    $saida = '';
    foreach ($attrs as $nome => $valor) {
        if ($valor === false || $valor === null) {
            continue;
        }
        $saida .= $valor === true ? ' ' . e($nome) : ' ' . e($nome) . '="' . e($valor) . '"';
    }
    return $saida;
}

/** Junta classes ignorando vazios. */
function classes(string ...$classes): string
{
    return trim(implode(' ', array_filter($classes, static fn (string $c) => $c !== '')));
}

/** Ícone Lucide via sprite SVG (/assets/vendor/lucide/sprite.svg). */
function icone(string $nome, string $classe = 'size-4'): string
{
    return '<svg class="' . e($classe) . '" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">'
        . '<use href="' . e(asset('vendor/lucide/sprite.svg') . '#' . $nome) . '"/></svg>';
}
