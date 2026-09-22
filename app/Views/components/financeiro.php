<?php

declare(strict_types=1);

use App\Services\Schema;

/** Componentes do financeiro (Fase 17): linha de cobrança/custo e a aba "Financeiro" da empresa. */

/** Linha de uma cobrança na aba Financeiro da empresa. */
function cobranca_linha(array $c): string
{
    $html = '<li class="flex items-start justify-between gap-3 border-b py-2.5 last:border-b-0">';
    $html .= '<div class="min-w-0"><div class="flex flex-wrap items-center gap-2">';
    $html .= '<a href="' . e(url('/financeiro/cobrancas/' . (int) $c['id'])) . '" class="font-medium underline-offset-4 hover:underline">' . e((string) $c['descricao']) . '</a>';
    $html .= badge_cobranca((string) $c['status']);
    if ($c['tipo'] === 'recorrente') {
        $html .= badge('Recorrente', 'outline');
    }
    $html .= '</div><div class="text-muted-foreground text-xs">Vencimento: ' . e(data_br((string) $c['vencimento'])) . '</div></div>';
    $html .= '<div class="shrink-0 font-medium">' . e(moeda((int) $c['valor'])) . '</div></li>';
    return $html;
}

/** Linha de um custo na aba Financeiro da empresa. */
function custo_linha(array $c): string
{
    $html = '<li class="flex items-start justify-between gap-3 border-b py-2.5 last:border-b-0">';
    $html .= '<div class="min-w-0"><a href="' . e(url('/financeiro/custos/' . (int) $c['id'] . '/editar')) . '" class="font-medium underline-offset-4 hover:underline">' . e((string) $c['descricao']) . '</a>';
    $html .= '<div class="text-muted-foreground text-xs">' . e(data_br((string) $c['data'])) . (trim((string) $c['categoria']) !== '' ? ' · ' . e((string) $c['categoria']) : '') . '</div></div>';
    $html .= '<div class="text-destructive shrink-0 font-medium">' . e(moeda((int) $c['valor'])) . '</div></li>';
    return $html;
}

/** Aba "Financeiro" de uma empresa: cobranças e custos, com atalhos para lançar novos. */
function financeiro_mini(array $cobrancas, array $custos, int $empresaId, string $voltar): string
{
    $novaCobranca = botao('Nova cobrança', ['href' => url('/financeiro/cobrancas/nova?' . http_build_query(['empresa_id' => $empresaId, 'voltar' => $voltar])), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus']);
    $novoCusto = botao('Novo custo', ['href' => url('/financeiro/custos/nova?' . http_build_query(['empresa_id' => $empresaId, 'voltar' => $voltar])), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus']);

    $html = '<div class="grid gap-4 md:grid-cols-2">';
    $html .= '<div><div class="mb-2 flex items-center justify-between"><h3 class="text-sm font-medium">Cobranças</h3>' . $novaCobranca . '</div>';
    $html .= $cobrancas === [] ? vazio('Sem cobranças', 'Nenhuma cobrança lançada para esta empresa.', ['icone' => 'receipt']) : ('<ul>' . implode('', array_map('cobranca_linha', $cobrancas)) . '</ul>');
    $html .= '</div>';
    $html .= '<div><div class="mb-2 flex items-center justify-between"><h3 class="text-sm font-medium">Custos</h3>' . $novoCusto . '</div>';
    $html .= $custos === [] ? vazio('Sem custos', 'Nenhum custo lançado para esta empresa.', ['icone' => 'wallet']) : ('<ul>' . implode('', array_map('custo_linha', $custos)) . '</ul>');
    $html .= '</div></div>';
    return $html;
}
