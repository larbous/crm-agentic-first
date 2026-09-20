<?php

declare(strict_types=1);

use App\Services\Metas;

/**
 * Barra de progresso de uma meta (Basecoat .progress). $p = Metas::progresso(). A barra enche até 100%; o traço marca o
 * ritmo esperado para hoje (só em metas acumuladas).
 */
function meta_barra(array $p): string
{
    $largura = max(0, min(100, $p['percentual']));
    $cor = match ($p['situacao']) {
        'atingida' => 'bg-emerald-600',
        'abaixo_ritmo', 'nao_atingida' => 'bg-amber-500',
        default => 'bg-primary',
    };
    $marca = $p['esperado'] !== null && $p['situacao'] !== 'atingida' && $p['situacao'] !== 'nao_atingida'
        ? '<span class="bg-foreground/60 absolute top-0 h-full w-0.5" style="left:' . max(0, min(100, $p['esperado'])) . '%" title="Ritmo esperado hoje: ' . $p['esperado'] . '%"></span>'
        : '';
    return '<div class="relative">'
        . '<div class="progress bg-muted h-2 rounded-full" role="progressbar" aria-label="Progresso da meta" aria-valuenow="' . $largura . '" aria-valuemin="0" aria-valuemax="100">'
        . '<span class="' . $cor . '" style="width:' . $largura . '%"></span></div>' . $marca . '</div>';
}

/** Selo com a situação da meta ("No ritmo", "Atingida"…). */
function meta_situacao(array $p): string
{
    [$texto, $variante] = Metas::SITUACOES[$p['situacao']];
    return badge($texto, $variante);
}

/** Texto curto sob a barra: "R$ 4.000,00 de R$ 10.000,00 (40%)". */
function meta_resumo(array $p): string
{
    return $p['realizado_txt'] . ' de ' . $p['alvo_txt'] . ' (' . $p['percentual'] . '%)';
}

/** Card "Metas" do Início: metas vigentes com barra, realizado × alvo e situação. */
function metas_card(array $metas): string
{
    $acao = botao('Metas', ['href' => url('/metas'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'target']);
    if ($metas === []) {
        return card([
            'titulo' => 'Metas', 'acao_html' => $acao,
            'corpo_html' => vazio('Nenhuma meta vigente', 'Cadastre uma meta para acompanhar o realizado aqui.', [
                'icone' => 'target', 'acao_html' => botao('Nova meta', ['href' => url('/metas/nova'), 'icone' => 'plus', 'variante' => 'outline']),
            ]),
        ]);
    }
    $html = '<ul class="grid gap-4">';
    foreach ($metas as $p) {
        $html .= '<li class="grid gap-1.5"><div class="flex items-start justify-between gap-3">'
            . '<a class="min-w-0 text-sm font-medium underline-offset-4 hover:underline" href="' . e(url('/metas/' . (int) $p['id'])) . '">' . e($p['rotulo']) . '</a>'
            . meta_situacao($p) . '</div>' . meta_barra($p)
            . '<div class="text-muted-foreground flex justify-between gap-3 text-xs"><span>' . e(meta_resumo($p)) . '</span>'
            . '<span class="whitespace-nowrap">' . ($p['dias_restantes'] === 0 ? 'termina hoje' : $p['dias_restantes'] . ' dia(s) restante(s)') . '</span></div></li>';
    }
    return card(['titulo' => 'Metas', 'descricao' => 'Realizado × meta dos períodos em andamento.', 'acao_html' => $acao, 'corpo_html' => $html . '</ul>']);
}
