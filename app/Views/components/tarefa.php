<?php

declare(strict_types=1);

use App\Services\Schema;

/** Tarefa em aberto? */
function tarefa_aberta(array $t): bool
{
    return in_array($t['status'], ['pendente', 'andamento'], true);
}

/** Vencimento formatado ("19/09/2026" ou "19/09/2026 14:30"). */
function vencimento_br(?string $v): string
{
    return $v === null || $v === '' ? '' : (strlen($v) > 10 ? datahora_br($v) : data_br($v));
}

/**
 * Linha de tarefa: botão de conclusão rápida, título, vencimento, prioridade e vínculos.
 * $voltar: caminho interno para onde voltar após concluir/reabrir. $vinculos: mostrar empresa/contato/negócio.
 */
function tarefa_linha(array $t, string $voltar, bool $vinculos = true): string
{
    $aberta = tarefa_aberta($t);
    $atrasada = $aberta && !empty($t['vencimento']) && substr($t['vencimento'], 0, 10) < hoje();
    $acao = $aberta ? 'concluir' : 'reabrir';

    $html = '<li class="flex items-start gap-3 border-b py-2.5 last:border-b-0">';
    $html .= '<form method="post" action="' . e(url('/tarefas/' . (int) $t['id'] . '/' . $acao)) . '" class="pt-0.5">' . csrf_field()
        . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
        . botao($aberta ? 'Concluir tarefa' : 'Reabrir tarefa', [
            'tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'icon-sm',
            'icone' => $aberta ? 'circle' : 'circle-check', 'classe' => $aberta ? 'text-muted-foreground' : 'text-success',
        ]) . '</form>';

    $html .= '<div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-x-2 gap-y-1">';
    $html .= '<a href="' . e(url('/tarefas/' . (int) $t['id'] . '/editar')) . '" class="font-medium underline-offset-4 hover:underline'
        . ($aberta ? '' : ' text-muted-foreground line-through') . '">' . e($t['titulo']) . '</a>';
    if ($t['prioridade'] === 'urgente' || $t['prioridade'] === 'alta') {
        $html .= badge(Schema::opcoes('prioridade_tar')[$t['prioridade']], $t['prioridade'] === 'urgente' ? 'destructive' : 'warning');
    }
    if (!$aberta) {
        $html .= badge(Schema::opcoes('status_tarefa')[$t['status']] ?? $t['status'], 'secondary');
    }
    $html .= '</div><div class="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">';
    if (!empty($t['vencimento'])) {
        $html .= '<span class="' . ($atrasada ? 'text-destructive font-medium' : '') . '">' . icone('calendar', 'inline size-3 align-[-1px]') . ' '
            . e(vencimento_br($t['vencimento'])) . ($atrasada ? ' · atrasada' : '') . '</span>';
    }
    $html .= '<span>' . e(Schema::opcoes('tipo_tarefa')[$t['tipo']] ?? $t['tipo']) . '</span>';
    if ($t['recorrencia'] !== 'nenhuma') {
        $html .= '<span>' . icone('rotate-ccw', 'inline size-3 align-[-1px]') . ' ' . e(Schema::opcoes('recorrencia')[$t['recorrencia']]) . '</span>';
    }
    if ($vinculos) {
        foreach ([['empresa_id', 'empresa_nome', '/empresas/'], ['contato_id', 'contato_nome', '/contatos/'], ['negocio_id', 'negocio_titulo', '/negocios/']] as [$id, $nome, $base]) {
            if (!empty($t[$id]) && !empty($t[$nome])) {
                $html .= '<a class="underline-offset-4 hover:underline" href="' . e(url($base . (int) $t[$id])) . '">' . e($t[$nome]) . '</a>';
            }
        }
    }
    return $html . '</div></div></li>';
}

/** Lista compacta de tarefas de um registro, com atalho para criar nova. */
function tarefas_mini(array $tarefas, string $campoVinculo, int $registroId, string $voltar): string
{
    $novo = botao('Nova tarefa', [
        'href' => url('/tarefas/nova?' . http_build_query([$campoVinculo => $registroId, 'voltar' => $voltar])),
        'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus',
    ]);
    if ($tarefas === []) {
        return vazio('Sem tarefas', 'Nenhuma tarefa vinculada.', ['icone' => 'list-checks', 'acao_html' => $novo]);
    }
    $html = '<div class="mb-2 flex justify-end">' . $novo . '</div><ul>';
    foreach ($tarefas as $t) {
        $html .= tarefa_linha($t, $voltar, false);
    }
    return $html . '</ul>';
}
