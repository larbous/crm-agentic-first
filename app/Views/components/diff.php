<?php

declare(strict_types=1);

use App\Services\Schema;

/** Formata o valor de um campo de acordo com o tipo no Schema (moeda, data, sim/não, opção). */
function formatar_valor_campo(?string $entidade, string $campo, mixed $valor): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    if (is_array($valor)) {
        return implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $valor)) ?: '—';
    }
    if ($campo === 'arquivado_em') {
        return datahora_br((string) $valor) ?: (string) $valor;
    }
    $def = $entidade !== null ? (Schema::entidade($entidade)['campos'][$campo] ?? null) : null;
    return match ($def['t'] ?? null) {
        'money'      => moeda((int) $valor),
        'data'       => data_br((string) $valor) ?: (string) $valor,
        'datahora', 'vencimento' => datahora_br((string) $valor) ?: (string) $valor,
        'bool'       => (int) $valor === 1 ? 'Sim' : 'Não',
        'enum'       => Schema::opcoes($def['op'])[$valor] ?? (string) $valor,
        'cnpj'       => cnpj_formatado((string) $valor),
        'cep'        => cep_formatado((string) $valor),
        'fk'         => '#' . $valor,
        default      => mb_strimwidth((string) $valor, 0, 160, '…'),
    };
}

/**
 * Componente diff: antes/depois por campo (auditoria e ações pendentes).
 * $antes/$depois: arrays campo => valor. Só mostra os campos presentes em algum deles.
 */
function diff_html(?array $antes, ?array $depois, ?string $entidade = null): string
{
    $campos = array_values(array_unique(array_merge(array_keys($antes ?? []), array_keys($depois ?? []))));
    $campos = array_filter($campos, static fn (string $c) => !str_starts_with($c, '_') && !in_array($c, ['atualizado_em', 'criado_em', 'criado_por'], true));
    if ($campos === []) {
        return '<span class="text-muted-foreground text-xs">Sem detalhes.</span>';
    }
    $html = '<dl class="diff">';
    foreach ($campos as $campo) {
        $rotulo = Schema::entidade((string) $entidade)['campos'][$campo]['r']
            ?? ['arquivado_em' => 'Arquivado em', 'tags' => 'Tags (ids)', 'contato_id' => 'Contato', 'papel' => 'Papel'][$campo] ?? $campo;
        $html .= '<div class="diff-linha"><dt>' . e($rotulo) . '</dt><dd>';
        if ($antes !== null && array_key_exists($campo, $antes)) {
            $html .= '<span class="diff-antes">' . e(formatar_valor_campo($entidade, $campo, $antes[$campo])) . '</span>';
        }
        if ($antes !== null && $depois !== null && array_key_exists($campo, $antes) && array_key_exists($campo, $depois)) {
            $html .= '<span class="diff-seta" aria-hidden="true">→</span>';
        }
        if ($depois !== null && array_key_exists($campo, $depois)) {
            $html .= '<span class="diff-depois">' . e(formatar_valor_campo($entidade, $campo, $depois[$campo])) . '</span>';
        }
        $html .= '</dd></div>';
    }
    return $html . '</dl>';
}
