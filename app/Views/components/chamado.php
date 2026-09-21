<?php

declare(strict_types=1);

use App\Services\Checklist;
use App\Services\Schema;

/** Componentes dos chamados (Fase 13): selos, resumo do checklist e a linha usada na tela de Tarefas. */

function chamado_aberto(array $c): bool
{
    return in_array($c['status'], ['aberto', 'andamento', 'aguardando'], true);
}

function chamado_atrasado(array $c): bool
{
    return chamado_aberto($c) && !empty($c['vencimento']) && substr((string) $c['vencimento'], 0, 10) < hoje();
}

function badge_prioridade(string $prioridade): string
{
    $variantes = ['baixa' => 'secondary', 'media' => 'outline', 'alta' => 'warning', 'urgente' => 'destructive'];
    return badge(Schema::opcoes('prioridade_tar')[$prioridade] ?? $prioridade, $variantes[$prioridade] ?? 'secondary');
}

function badge_chamado_status(string $status): string
{
    $variantes = ['aberto' => 'info', 'andamento' => 'info', 'aguardando' => 'warning', 'concluido' => 'success', 'cancelado' => 'secondary'];
    return badge(Schema::opcoes('status_chamado')[$status] ?? $status, $variantes[$status] ?? 'secondary');
}

/** "3/5" do checklist (vazio se não há itens). $checklist: JSON gravado no banco. */
function checklist_resumo(?string $checklist): string
{
    $p = Checklist::progresso($checklist);
    return $p['total'] > 0 ? $p['feitos'] . '/' . $p['total'] : '';
}

/**
 * Linha de chamado na tela de Tarefas: botão de conclusão rápida, código e título, área, prazo, prioridade, checklist e vínculos.
 * $voltar: caminho interno para onde voltar depois de concluir/reabrir.
 */
function chamado_linha(array $c, string $voltar, bool $vinculos = true): string
{
    $aberto = chamado_aberto($c);
    $atrasado = chamado_atrasado($c);
    $novoStatus = $aberto ? 'concluido' : 'aberto';

    $html = '<li class="flex items-start gap-3 border-b py-2.5 last:border-b-0">';
    $html .= '<form method="post" action="' . e(url('/chamados/' . (int) $c['id'] . '/status')) . '" class="pt-0.5">' . csrf_field()
        . '<input type="hidden" name="voltar" value="' . e($voltar) . '"><input type="hidden" name="status" value="' . $novoStatus . '">'
        . botao($aberto ? 'Concluir chamado' : 'Reabrir chamado', [
            'tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'icon-sm',
            'icone' => $aberto ? 'circle' : 'circle-check', 'classe' => $aberto ? 'text-muted-foreground' : 'text-success',
        ]) . '</form>';

    $html .= '<div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-x-2 gap-y-1">';
    $html .= badge('Chamado', 'info') . ' ' . badge((string) $c['area_nome'], 'outline');
    $html .= '<a href="' . e(url('/chamados/' . (int) $c['id'])) . '" class="font-medium underline-offset-4 hover:underline'
        . ($aberto ? '' : ' text-muted-foreground line-through') . '">' . e((string) $c['titulo']) . '</a>';
    if (in_array($c['prioridade'], ['alta', 'urgente'], true)) {
        $html .= badge_prioridade((string) $c['prioridade']);
    }
    if ($c['status'] !== 'aberto') {
        $html .= badge_chamado_status((string) $c['status']);
    }
    $html .= '</div><div class="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">';
    $html .= '<span>' . e((string) $c['codigo']) . '</span>';
    if (!empty($c['vencimento'])) {
        $html .= '<span class="' . ($atrasado ? 'text-destructive font-medium' : '') . '">' . icone('calendar', 'inline size-3 align-[-1px]') . ' '
            . e(vencimento_br($c['vencimento'])) . ($atrasado ? ' · atrasado' : '') . '</span>';
    }
    $progresso = checklist_resumo($c['checklist']);
    if ($progresso !== '') {
        $html .= '<span>' . icone('list-checks', 'inline size-3 align-[-1px]') . ' ' . e($progresso) . '</span>';
    }
    if ($vinculos) {
        foreach ([['empresa_id', 'empresa_nome', '/empresas/'], ['contato_id', 'contato_nome', '/contatos/'], ['negocio_id', 'negocio_titulo', '/negocios/'], ['contrato_id', 'contrato_numero', '/contratos/']] as [$id, $nome, $base]) {
            if (!empty($c[$id]) && !empty($c[$nome])) {
                $html .= '<a class="underline-offset-4 hover:underline" href="' . e(url($base . (int) $c[$id])) . '">' . e((string) $c[$nome]) . '</a>';
            }
        }
    }
    return $html . '</div></div></li>';
}

/** Aba "Chamados" de uma empresa: lista compacta e atalho para abrir um chamado já vinculado. */
function chamados_mini(array $chamados, int $empresaId, string $voltar): string
{
    $novo = botao('Novo chamado', [
        'href' => url('/chamados/nova?' . http_build_query(['empresa_id' => $empresaId, 'voltar' => $voltar])),
        'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus',
    ]);
    if ($chamados === []) {
        return vazio('Sem chamados', 'Nenhuma demanda de execução para esta empresa.', ['icone' => 'list-checks', 'acao_html' => $novo]);
    }
    $html = '<div class="mb-2 flex justify-end">' . $novo . '</div><ul>';
    foreach ($chamados as $c) {
        $html .= chamado_linha($c, $voltar, false);
    }
    return $html . '</ul>';
}
