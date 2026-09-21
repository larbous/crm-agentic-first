<?php
/**
 * Ações pendentes de aprovação.
 * @var list<array> $grupos por execução: execucao_id, agente, criado_em, alvo {entidade,id,nome}|null, acoes[]
 * @var list<array> $decididas
 */
$rotaEntidade = ['empresas' => '/empresas/', 'contatos' => '/contatos/', 'negocios' => '/negocios/', 'propostas' => '/propostas/', 'contratos' => '/contratos/'];
$total = array_sum(array_map(static fn (array $g): int => count($g['acoes']), $grupos));

$blocos = '';
foreach ($grupos as $g) {
    $alvo = '';
    if ($g['alvo'] !== null) {
        $alvo = ' em <a class="underline underline-offset-4" href="' . e(url(($rotaEntidade[$g['alvo']['entidade']] ?? '/') . $g['alvo']['id'])) . '">' . e($g['alvo']['nome']) . '</a>';
    }
    $linhas = '';
    foreach ($g['acoes'] as $a) {
        $aid = (int) $a['id'];
        $erro = $a['erro'] ? '<div class="alert mt-2" data-variant="destructive">' . icone('circle-alert') . '<h2>Não foi possível aplicar</h2><section><p>' . e($a['erro']) . '</p></section></div>' : '';
        $linhas .= '<li class="grid gap-2 border-b py-3 last:border-b-0">'
            . '<div class="flex flex-wrap items-start gap-3">'
            . '<input type="checkbox" class="input mt-1" name="ids[]" value="' . $aid . '" form="form-lote" aria-label="Selecionar: ' . e($a['resumo']) . '">'
            . '<div class="min-w-0 flex-1"><div class="text-sm font-medium">' . e($a['resumo']) . '</div>'
            . (!empty($a['motivo']) ? '<div class="mt-1">' . badge('Revisão exigida', 'warning') . ' <span class="text-muted-foreground text-xs">' . e($a['motivo']) . '</span></div>' : '')
            . acao_pendente_diff($a) . $erro . '</div>'
            . '<div class="flex items-center gap-1">'
            . '<form method="post" action="' . e(url("/acoes-pendentes/{$aid}/aprovar")) . '">' . csrf_field() . botao('Aprovar', ['tipo' => 'submit', 'tamanho' => 'sm', 'icone' => 'check']) . '</form>'
            . '<form method="post" action="' . e(url("/acoes-pendentes/{$aid}/rejeitar")) . '">' . csrf_field() . botao('Rejeitar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'ghost', 'icone' => 'x']) . '</form>'
            . '</div></div></li>';
    }
    $titulo = e($g['agente']) . $alvo;
    $blocos .= card([
        'titulo' => $g['agente'],
        'descricao' => datahora_br($g['criado_em']) . ' · execução #' . $g['execucao_id'],
        'corpo_html' => ($alvo !== '' ? '<p class="text-muted-foreground mb-2 text-sm">' . $titulo . '</p>' : '') . '<ul>' . $linhas . '</ul>',
        'classe' => 'mb-4',
    ]);
}

$histLinhas = [];
foreach ($decididas as $d) {
    $histLinhas[] = [
        'quando' => datahora_br($d['decidido_em']),
        'agente' => (string) ($d['agente_nome'] ?? ''),
        'acao' => (string) $d['resumo'],
        'resultado' => ['html' => $d['status'] === 'aprovada' ? badge('Aprovada', 'success') : badge('Rejeitada', 'secondary')],
    ];
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Ações pendentes</h1>
        <p class="text-muted-foreground"><?= $total ?> ação(ões) aguardando decisão. Aprovar executa a ação (ela aparece na Auditoria e pode ser desfeita); rejeitar descarta.</p>
    </div>
    <?php if ($total > 0): ?>
        <form id="form-lote" method="post" action="<?= e(url('/acoes-pendentes/lote')) ?>" class="flex flex-wrap items-center gap-2" data-confirmar-lote>
            <?= csrf_field() ?>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="input" data-selecionar-todas> Selecionar todas</label>
            <?= botao('Aprovar selecionadas', ['tipo' => 'submit', 'icone' => 'check', 'attrs' => ['name' => 'decisao', 'value' => 'aprovar']]) ?>
            <?= botao('Rejeitar selecionadas', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'x', 'attrs' => ['name' => 'decisao', 'value' => 'rejeitar']]) ?>
        </form>
    <?php endif; ?>
</div>

<?php if ($grupos === []): ?>
    <?= vazio('Nada aguardando aprovação', 'Quando um agente propuser alterações, elas aparecem aqui com o antes e o depois.', ['icone' => 'inbox']) ?>
<?php else: ?>
    <?= $blocos ?>
<?php endif; ?>

<?php if ($histLinhas !== []): ?>
    <h2 class="mt-6 mb-2 text-base font-semibold">Decididas recentemente</h2>
    <?= tabela(['quando' => 'Quando', 'agente' => 'Agente', 'acao' => 'Ação', 'resultado' => 'Resultado'], $histLinhas) ?>
<?php endif; ?>
