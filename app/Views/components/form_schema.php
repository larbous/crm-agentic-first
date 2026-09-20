<?php

declare(strict_types=1);

use App\Services\Opcoes;
use App\Services\Schema;

/** Valor de um campo do banco → texto exibido no controle de formulário. */
function valor_para_formulario(array $def, mixed $valor): string
{
    if ($valor === null) {
        return '';
    }
    return match ($def['t']) {
        'money'    => centavos_para_texto((int) $valor),
        'cnpj'     => cnpj_formatado((string) $valor),
        'cep'      => cep_formatado((string) $valor),
        'datahora' => str_replace(' ', 'T', substr((string) $valor, 0, 16)),
        default    => (string) $valor,
    };
}

/**
 * Campos de um grupo (aba) do Schema como grade de formulário.
 * $valores: dados atuais; $erros: campo => mensagem.
 * Opções: ocultar (lista de campos), somente_leitura (lista), opcoes (campo => [valor => rótulo] para sobrescrever selects).
 */
function campos_do_schema(string $entidade, string $grupo, array $valores, array $erros = [], array $o = []): string
{
    $html = '';
    foreach (Schema::gravaveis($entidade) as $nome => $def) {
        if ($def['g'] !== $grupo || in_array($nome, $o['ocultar'] ?? [], true)) {
            continue;
        }
        $base = [
            'nome'        => $nome,
            'rotulo'      => $def['r'],
            'valor'       => valor_para_formulario($def, $valores[$nome] ?? null),
            'erro'        => $erros[$nome] ?? null,
            'obrigatorio' => !empty($def['req']),
            'desabilitado' => in_array($nome, $o['somente_leitura'] ?? [], true),
        ];
        $attrs = [];

        switch ($def['t']) {
            case 'textarea':
                $campo = $base + ['tipo' => 'textarea'];
                break;
            case 'html':
                $campo = $base + ['tipo' => 'textarea', 'linhas' => 22];
                $attrs = ['class' => 'textarea font-mono text-[13px]', 'spellcheck' => 'false'];
                break;
            case 'bool':
                $campo = ['valor' => (int) ($valores[$nome] ?? 0) === 1, 'tipo' => 'switch'] + $base;
                break;
            case 'enum':
            case 'fk':
                $opcoes = $o['opcoes'][$nome] ?? ($def['t'] === 'enum' ? Schema::opcoes($def['op']) : Opcoes::para($def['fk']));
                $campo = $base + ['controle_html' => select($nome, $opcoes, $valores[$nome] ?? null, [
                    'obrigatorio' => !empty($def['req']),
                    'desabilitado' => $base['desabilitado'],
                    'placeholder' => !empty($def['req']) && $def['t'] === 'enum' ? null : '—',
                    'attrs' => ['class' => 'select w-full'] + (isset($erros[$nome]) ? ['aria-invalid' => 'true'] : []),
                ])];
                break;
            case 'int':
                $campo = $base + ['tipo' => 'number'];
                $attrs = array_filter(['min' => $def['min'] ?? null, 'max' => $def['max'] ?? null, 'step' => '1']);
                break;
            case 'money':
                $campo = $base + ['tipo' => 'text'];
                $attrs = ['inputmode' => 'decimal', 'data-dinheiro' => true, 'placeholder' => '0,00'];
                break;
            case 'data':
                $campo = $base + ['tipo' => 'date'];
                break;
            case 'datahora':
                $campo = $base + ['tipo' => 'datetime-local'];
                break;
            case 'email':
                $campo = $base + ['tipo' => 'email'];
                break;
            case 'tel':
                $campo = $base + ['tipo' => 'tel'];
                break;
            case 'cor':
                $campo = $base + ['tipo' => 'color', 'valor' => $base['valor'] ?: '#64748b'];
                break;
            case 'cnpj':
                $campo = $base + ['tipo' => 'text'];
                $attrs = ['inputmode' => 'numeric', 'data-mascara' => 'cnpj', 'maxlength' => 18, 'placeholder' => '00.000.000/0000-00'];
                break;
            case 'cpf':
                $campo = $base + ['tipo' => 'text'];
                $attrs = ['inputmode' => 'numeric', 'data-mascara' => 'cpf', 'maxlength' => 14, 'placeholder' => '000.000.000-00'];
                break;
            case 'cep':
                $campo = $base + ['tipo' => 'text'];
                $attrs = ['inputmode' => 'numeric', 'data-mascara' => 'cep', 'maxlength' => 9, 'placeholder' => '00000-000'];
                break;
            case 'uf':
                $campo = $base + ['tipo' => 'text'];
                $attrs = ['maxlength' => 2, 'class' => 'input uppercase'];
                break;
            default:
                $campo = $base + ['tipo' => 'text'];
                if (isset($def['max'])) {
                    $attrs = ['maxlength' => $def['max']];
                }
        }
        $campo['attrs'] = $attrs + ($campo['attrs'] ?? []);

        $largura = ($def['l'] ?? 1) === 2 || $def['t'] === 'textarea' ? ' md:col-span-2' : '';
        $html .= '<div class="' . trim($largura) . '">' . campo($campo) . '</div>';
    }
    return '<div class="grid gap-4 md:grid-cols-2">' . $html . '</div>';
}
