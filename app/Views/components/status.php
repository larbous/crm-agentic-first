<?php

declare(strict_types=1);

use App\Services\Schema;

/** Badge de status por grupo de opções (status_empresa, status_negocio, status_contato, status_tarefa). */
function badge_status(string $grupo, ?string $valor): string
{
    if ($valor === null || $valor === '') {
        return '';
    }
    $variantes = [
        'status_empresa' => ['lead' => 'secondary', 'prospect' => 'info', 'cliente' => 'success', 'ex_cliente' => 'outline', 'inativo' => 'ghost'],
        'status_negocio' => ['aberto' => 'info', 'ganho' => 'success', 'perdido' => 'destructive', 'pausado' => 'warning'],
        'status_contato' => ['ativo' => 'success', 'inativo' => 'secondary'],
        'status_tarefa'  => ['pendente' => 'secondary', 'andamento' => 'info', 'concluida' => 'success', 'cancelada' => 'ghost'],
    ];
    return badge(Schema::opcoes($grupo)[$valor] ?? $valor, $variantes[$grupo][$valor] ?? 'secondary');
}

/** Chips de tags de uma linha da lista. */
function chips_tags(array $tags): string
{
    $html = '';
    foreach ($tags as $t) {
        $html .= pill_etapa($t['nome'], $t['cor'] ?? null) . ' ';
    }
    return $html;
}

/** Contrato já passou do fim da vigência mas ainda consta como vigente (o worker da Fase 6 marca como "vencido"). */
function contrato_vencido_efetivo(array $c): bool
{
    return in_array($c['status'] ?? '', ['assinado', 'ativo'], true) && !empty($c['data_fim']) && $c['data_fim'] < hoje();
}

/** Badge de status da proposta (considera a validade vencida). */
function badge_proposta(array $p): string
{
    if (proposta_expirada($p)) {
        return badge('Expirada', 'warning');
    }
    $variantes = ['rascunho' => 'secondary', 'enviada' => 'info', 'visualizada' => 'info', 'aceita' => 'success', 'recusada' => 'destructive', 'expirada' => 'warning'];
    return badge(Schema::opcoes('status_proposta')[$p['status']] ?? $p['status'], $variantes[$p['status']] ?? 'secondary');
}

/** Badge de status do contrato. */
function badge_contrato(array $c): string
{
    if (contrato_vencido_efetivo($c)) {
        return badge('Vencido', 'warning');
    }
    $variantes = ['rascunho' => 'secondary', 'enviado' => 'info', 'assinado' => 'success', 'ativo' => 'success', 'vencido' => 'warning', 'cancelado' => 'ghost', 'renovado' => 'outline'];
    return badge(Schema::opcoes('status_contrato')[$c['status']] ?? $c['status'], $variantes[$c['status']] ?? 'secondary');
}

/** Badge de status da cobrança (Fase 17). */
function badge_cobranca(string $status): string
{
    $variantes = ['pendente' => 'secondary', 'pago' => 'success', 'vencido' => 'warning', 'cancelado' => 'ghost'];
    return badge(Schema::opcoes('status_cobranca')[$status] ?? $status, $variantes[$status] ?? 'secondary');
}

/** Fim da vigência com aviso de proximidade ("vence em 12 dias"). */
function fim_vigencia_html(array $c): string
{
    if (empty($c['data_fim'])) {
        return '';
    }
    $html = e(data_br($c['data_fim']));
    if (in_array($c['status'], ['assinado', 'ativo', 'vencido'], true)) {
        $dias = dias_entre(hoje(), $c['data_fim']);
        if ($dias !== null && $dias < 0) {
            $html .= ' <span class="text-destructive text-xs">venceu há ' . abs($dias) . ' dia(s)</span>';
        } elseif ($dias !== null && $dias <= 60) {
            $html .= ' <span class="text-warning text-xs font-medium">vence em ' . $dias . ' dia(s)</span>';
        }
    }
    return $html;
}
