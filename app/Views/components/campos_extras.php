<?php

declare(strict_types=1);

use App\Services\CamposExtras;

/**
 * Células de formulário dos campos extras ativos da entidade (dentro da grade da aba "Extras").
 * $valores: JSON gravado no banco (string) ou o array vindo do formulário (campos_extras[chave] => texto).
 * $erros: "campos_extras.<chave>" => mensagem.
 */
function campos_extras_celulas(string $entidade, mixed $valores, array $erros = []): string
{
    $defs = CamposExtras::definicoes($entidade);
    if ($defs === []) {
        return '<p class="text-muted-foreground text-sm md:col-span-2">Nenhum campo extra definido. '
            . link_para('/configuracoes?aba=extras', 'Crie campos em Configurações', 'underline underline-offset-4') . '.</p>';
    }
    $doFormulario = is_array($valores);
    $guardados = CamposExtras::valores($valores);

    $html = '';
    foreach ($defs as $def) {
        $chave = $def['chave'];
        $nome = 'campos_extras[' . $chave . ']';
        $id = 'extra-' . $chave;
        $bruto = $guardados[$chave] ?? null;
        // Do banco, o valor sai formatado (número em pt-BR); do formulário, volta como foi digitado.
        $valor = $doFormulario || in_array($def['tipo'], ['data', 'select', 'texto', 'textarea', 'url'], true)
            ? (string) ($bruto ?? '')
            : CamposExtras::texto($def, $bruto);
        $base = [
            'nome' => $nome, 'id' => $id, 'rotulo' => $def['rotulo'], 'valor' => $valor,
            'erro' => $erros['campos_extras.' . $chave] ?? null, 'obrigatorio' => (int) $def['obrigatorio'] === 1,
        ];

        switch ($def['tipo']) {
            case 'textarea':
                $campo = $base + ['tipo' => 'textarea'];
                break;
            case 'checkbox':
                $campo = ['tipo' => 'switch', 'valor' => $valor !== '' && $valor !== '0'] + $base;
                break;
            case 'select':
                $campo = $base + ['controle_html' => select($nome, array_combine($def['opcoes'], $def['opcoes']), $valor !== '' ? $valor : null, [
                    'id' => $id, 'placeholder' => '—', 'obrigatorio' => $base['obrigatorio'],
                    'attrs' => ['class' => 'select w-full'] + ($base['erro'] ? ['aria-invalid' => 'true'] : []),
                ])];
                break;
            case 'data':
                $campo = $base + ['tipo' => 'date'];
                break;
            case 'numero':
                $campo = $base + ['tipo' => 'text', 'attrs' => ['inputmode' => 'decimal']];
                break;
            case 'url':
                $campo = $base + ['tipo' => 'text', 'attrs' => ['inputmode' => 'url', 'placeholder' => 'https://']];
                break;
            default:
                $campo = $base + ['tipo' => 'text', 'attrs' => ['maxlength' => 255]];
        }
        $html .= '<div class="' . ($def['tipo'] === 'textarea' ? 'md:col-span-2' : '') . '">' . campo($campo) . '</div>';
    }
    return $html;
}

/** Cartão do detalhe com os campos extras preenchidos (vazio quando a entidade não tem campos definidos). */
function card_campos_extras(string $entidade, array $registro): string
{
    if (CamposExtras::definicoes($entidade) === []) {
        return '';
    }
    return card(['tamanho' => 'sm', 'titulo' => 'Campos extras', 'corpo_html' => ficha(CamposExtras::paraFicha($entidade, $registro))]);
}
