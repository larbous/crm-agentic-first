<?php

declare(strict_types=1);

/**
 * Barra de filtros (busca + selects + chips de filtros ativos). Formulário GET que se auto-envia.
 * $o: acao (URL), q (texto), filtros [nome => ['rotulo', 'opcoes' => [valor => rótulo], 'valor']], ocultos [nome => valor],
 *     limpar (URL para limpar), placeholder.
 */
function barra_filtros(array $o): string
{
    $ativos = [];
    $html = '<form method="get" action="' . e($o['acao']) . '" class="filter-bar" role="search">';
    foreach ($o['ocultos'] ?? [] as $nome => $valor) {
        if ($valor !== null && $valor !== '') {
            $html .= '<input type="hidden" name="' . e($nome) . '" value="' . e($valor) . '">';
        }
    }
    $html .= '<div class="input-group max-w-xs min-w-48 flex-1"><div data-align="start">' . icone('search') . '</div>'
        . '<input type="search" name="q" value="' . e($o['q'] ?? '') . '" placeholder="' . e($o['placeholder'] ?? 'Buscar…') . '" aria-label="Buscar" autocomplete="off"></div>';

    foreach ($o['filtros'] ?? [] as $nome => $f) {
        $valor = (string) ($f['valor'] ?? '');
        $html .= select($nome, $f['opcoes'], $valor !== '' ? $valor : null, [
            'placeholder' => $f['rotulo'] . ': todos', 'id' => 'filtro-' . $nome, 'classe' => 'w-auto',
            'attrs' => ['class' => 'select w-auto', 'aria-label' => $f['rotulo'], 'onchange' => 'this.form.requestSubmit()'],
        ]);
        if ($valor !== '') {
            $ativos[] = $f['rotulo'] . ': ' . ($f['opcoes'][$valor] ?? $valor);
        }
    }
    $html .= botao('Filtrar', ['tipo' => 'submit', 'variante' => 'secondary', 'tamanho' => 'sm']);
    if (($o['q'] ?? '') !== '' || $ativos !== []) {
        $html .= botao('Limpar', ['href' => $o['limpar'] ?? $o['acao'], 'variante' => 'ghost', 'tamanho' => 'sm', 'icone' => 'x']);
    }
    return $html . '</form>';
}
