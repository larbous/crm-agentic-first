<?php

declare(strict_types=1);

/**
 * Ações rápidas de IA (SPEC §8): botões que pedem uma sugestão à IA e mostram o resultado para aceitar ou descartar.
 * O comportamento vem de /assets/js/ia-rapida.js; o servidor (AcoesRapidas) monta o contexto e não grava nada.
 */

/** Painel de resultado (escondido até o clique): sugestão, "Usar este texto" e "Descartar". */
function ia_rapida_painel(): string
{
    return '<div class="ia-rapida-resultado bg-muted/40 mt-2 grid gap-2 rounded-md border p-3" data-ia-resultado role="status" aria-live="polite" hidden>'
        . '<div class="text-muted-foreground text-xs font-medium uppercase">Sugestão da IA</div>'
        . '<div class="text-sm whitespace-pre-line" data-ia-texto></div>'
        . '<div class="flex flex-wrap gap-2" data-ia-decisao>'
        . botao('Usar este texto', ['tamanho' => 'sm', 'icone' => 'check', 'attrs' => ['data-ia-aceitar' => true]])
        . botao('Descartar', ['tamanho' => 'sm', 'variante' => 'outline', 'attrs' => ['data-ia-descartar' => true]])
        . '</div></div>';
}

/** Botões sobre o texto de um campo longo: melhorar, tom formal, tom amigável. O resultado substitui o texto do campo. */
function ia_rapida_texto(string $campoId): string
{
    $botoes = '';
    foreach (['melhorar' => ['Melhorar texto', 'wand-sparkles'], 'formal' => ['Tom formal', 'briefcase'], 'amigavel' => ['Tom amigável', 'heart']] as $acao => [$rotulo, $icone]) {
        $botoes .= botao($rotulo, ['variante' => 'ghost', 'tamanho' => 'xs', 'icone' => $icone, 'attrs' => ['data-ia-acao' => $acao]]);
    }
    return '<div class="ia-rapida mt-1" data-ia-barra data-ia-destino="' . e($campoId) . '" data-ia-modo="substituir">'
        . '<div class="flex flex-wrap items-center gap-1" role="group" aria-label="Ações rápidas de IA">' . $botoes . '</div>'
        . ia_rapida_painel() . '</div>';
}

/**
 * Botões da timeline de um registro (empresa, contato ou negócio): resumir o histórico e sugerir resposta. O resultado vai
 * para o campo "Detalhes" do formulário de nova atividade (não grava nada até o operador registrar).
 */
function timeline_ia(string $entidade, int $registroId): string
{
    $botoes = botao('Resumir histórico', ['variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'sparkles', 'attrs' => [
        'data-ia-acao' => 'resumir', 'data-ia-assunto' => 'Resumo do histórico',
    ]]) . botao('Sugerir resposta', ['variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'message-square', 'attrs' => [
        'data-ia-acao' => 'resposta',
    ]]);
    return '<div class="ia-rapida mb-4" data-ia-barra data-ia-destino="atv-desc" data-ia-modo="acrescentar" data-ia-entidade="' . e($entidade) . '" data-ia-id="' . $registroId . '">'
        . '<div class="flex flex-wrap items-center gap-2" role="group" aria-label="Ações rápidas de IA na timeline">' . $botoes
        . '<span class="text-muted-foreground text-xs">O resultado vai para o campo Detalhes de "Nova atividade".</span></div>'
        . ia_rapida_painel() . '</div>';
}
