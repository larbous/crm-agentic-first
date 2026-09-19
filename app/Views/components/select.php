<?php

declare(strict_types=1);

/**
 * Select nativo estilizado (Basecoat select.select). $opcoes: [valor => rótulo].
 * Opções: placeholder (primeira opção vazia), id, obrigatorio, desabilitado, attrs.
 */
function select(string $nome, array $opcoes, mixed $valor = null, array $o = []): string
{
    $attrs = array_merge([
        'class'    => 'select',
        'id'       => $o['id'] ?? 'campo-' . preg_replace('/[^a-z0-9_-]/i', '-', $nome),
        'name'     => $nome,
        'required' => !empty($o['obrigatorio']),
        'disabled' => !empty($o['desabilitado']),
    ], $o['attrs'] ?? []);

    $html = '<select' . attrs_html($attrs) . '>';
    if (isset($o['placeholder'])) {
        $html .= '<option value="">' . e($o['placeholder']) . '</option>';
    }
    foreach ($opcoes as $chave => $rotulo) {
        $sel = $valor !== null && (string) $valor === (string) $chave ? ' selected' : '';
        $html .= '<option value="' . e($chave) . '"' . $sel . '>' . e($rotulo) . '</option>';
    }
    return $html . '</select>';
}
