<?php

declare(strict_types=1);

/**
 * Campo de formulário: label + controle + ajuda + erro (Basecoat .field).
 * Opções: nome (obrigatório), rotulo, tipo (text|email|password|number|date|search|tel|url|textarea|checkbox|switch),
 * valor, placeholder, ajuda, erro, obrigatorio, desabilitado, attrs (do controle), controle_html (controle pronto, ex.: select()).
 */
function campo(array $o): string
{
    $nome = (string) $o['nome'];
    $id = $o['id'] ?? 'campo-' . preg_replace('/[^a-z0-9_-]/i', '-', $nome);
    $tipo = $o['tipo'] ?? 'text';
    $erro = $o['erro'] ?? null;
    $valor = $o['valor'] ?? '';
    $descricao = isset($o['ajuda']) ? $id . '-ajuda' : null;
    $descErro = $erro ? $id . '-erro' : null;

    $comum = [
        'id'               => $id,
        'name'             => $nome,
        'required'         => !empty($o['obrigatorio']),
        'disabled'         => !empty($o['desabilitado']),
        'placeholder'      => $o['placeholder'] ?? null,
        'aria-invalid'     => $erro ? 'true' : null,
        'aria-describedby' => trim(($descricao ?? '') . ' ' . ($descErro ?? '')) ?: null,
    ];
    $extras = $o['attrs'] ?? [];

    if (isset($o['controle_html'])) {
        $controle = $o['controle_html'];
    } elseif ($tipo === 'textarea') {
        $controle = '<textarea class="textarea"' . attrs_html(array_merge($comum, ['rows' => $o['linhas'] ?? 4], $extras)) . '>'
            . e($valor) . '</textarea>';
    } elseif ($tipo === 'checkbox' || $tipo === 'switch') {
        $attrs = array_merge($comum, [
            'type'    => 'checkbox',
            'class'   => 'input',
            'role'    => $tipo === 'switch' ? 'switch' : null,
            'value'   => $o['valor_marcado'] ?? '1',
            'checked' => !empty($valor),
        ], $extras);
        $controle = '<input' . attrs_html($attrs) . '>';
    } else {
        $attrs = array_merge($comum, ['type' => $tipo, 'class' => 'input', 'value' => $valor], $extras);
        $controle = '<input' . attrs_html($attrs) . '>';
    }

    $rotulo = isset($o['rotulo'])
        ? '<label for="' . e($id) . '">' . e($o['rotulo'])
            . (!empty($o['obrigatorio']) ? ' <span class="text-destructive" aria-hidden="true">*</span>' : '') . '</label>'
        : '';

    $ehCheck = $tipo === 'checkbox' || $tipo === 'switch';
    $html = '<div class="field"' . ($ehCheck ? ' data-orientation="horizontal"' : '') . '>';
    $html .= $ehCheck ? $controle . $rotulo : $rotulo . $controle;
    if (isset($o['ajuda'])) {
        $html .= '<p id="' . e($descricao) . '" class="text-muted-foreground text-sm">' . e($o['ajuda']) . '</p>';
    }
    if ($erro) {
        $html .= '<p id="' . e($descErro) . '" role="alert" class="text-destructive text-sm">' . e($erro) . '</p>';
    }
    return $html . '</div>';
}
