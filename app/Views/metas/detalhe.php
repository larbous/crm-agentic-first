<?php
/**
 * Detalhe da meta: progresso calculado na hora.
 * @var array $registro
 * @var array $progresso Metas::progresso()
 */
use App\Services\Schema;

$r = $registro;
$id = (int) $r['id'];
$p = $progresso;
$explicacao = match ($r['tipo']) {
    'faturamento'        => 'Soma do valor fechado dos negócios ganhos com data de fechamento no período.',
    'negocios_ganhos'    => 'Negócios ganhos com data de fechamento no período.',
    'novos_clientes'     => 'Empresas que viraram cliente no período (campo "cliente desde").',
    'propostas_enviadas' => 'Propostas enviadas no período (cada número conta uma vez, mesmo com várias versões).',
    'mrr'                => 'Soma do valor mensal dos contratos vigentes na data de referência (hoje, ou o fim do período se já passou).',
    default              => '',
};
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e(Schema::opcoes('tipo_meta')[$r['tipo']] ?? $r['tipo']) ?></h1>
            <?= meta_situacao($p) ?>
        </div>
        <p class="text-muted-foreground"><?= e(Schema::opcoes('periodo_meta')[$r['periodo']] ?? $r['periodo']) ?> · <?= e(data_br($r['data_inicio'])) ?> a <?= e(data_br($r['data_fim'])) ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Editar', ['href' => url('/metas/' . $id . '/editar'), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?= botao_arquivar(url('/metas/' . $id . '/arquivar'), 'Arquivar esta meta? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<div class="grid max-w-2xl gap-4">
    <?= card([
        'titulo'     => 'Progresso',
        'corpo_html' => '<div class="grid gap-3"><p class="text-3xl font-semibold">' . e($p['realizado_txt']) . ' <span class="text-muted-foreground text-base font-normal">de ' . e($p['alvo_txt']) . '</span></p>'
            . meta_barra($p)
            . '<div class="text-muted-foreground flex flex-wrap justify-between gap-2 text-sm"><span>' . $p['percentual'] . '% da meta'
            . ($p['esperado'] !== null ? ' · ritmo esperado hoje: ' . $p['esperado'] . '%' : '') . '</span>'
            . '<span>' . ($p['situacao'] === 'futura' ? 'ainda não começou' : ($p['dias_restantes'] === 0 ? 'termina hoje' : $p['dias_restantes'] . ' dia(s) restante(s)')) . '</span></div>'
            . '<p class="text-muted-foreground text-sm">' . e($explicacao) . '</p></div>',
    ]) ?>
</div>
