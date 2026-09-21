<?php
/**
 * Detalhe do chamado: andamento (status), checklist, vínculos, anexos e histórico.
 * @var array $registro
 * @var list<array{texto:string,feito:int}> $itens
 * @var list<array> $anexos
 * @var list<array> $historico
 */
use App\Services\Schema;

$r = $registro;
$id = (int) $r['id'];
$voltar = '/chamados/' . $id;
$aberto = chamado_aberto($r);
$op = static fn (string $grupo, ?string $v) => $v !== null ? (Schema::opcoes($grupo)[$v] ?? $v) : '';
$total = count($itens);
$feitos = count(array_filter($itens, static fn (array $i): bool => $i['feito'] === 1));
$percentual = $total > 0 ? (int) round($feitos * 100 / $total) : 0;

$statusForm = static fn (string $status, string $rotulo, string $icone, string $variante = 'outline', ?string $confirmar = null): string
    => '<form method="post" action="' . e(url("/chamados/{$id}/status")) . '"' . ($confirmar !== null ? ' data-confirmar="' . e($confirmar) . '"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="voltar" value="' . e($voltar) . '"><input type="hidden" name="status" value="' . $status . '">'
        . botao($rotulo, ['tipo' => 'submit', 'variante' => $variante, 'icone' => $icone, 'tamanho' => 'sm']) . '</form>';

// ---- Andamento
$andamento = '<div class="flex flex-wrap items-center gap-2">';
if ($aberto) {
    if ($r['status'] !== 'andamento') {
        $andamento .= $statusForm('andamento', 'Iniciar', 'circle-play');
    }
    if ($r['status'] !== 'aguardando') {
        $andamento .= $statusForm('aguardando', 'Aguardando', 'clock');
    }
    $andamento .= $statusForm('cancelado', 'Cancelar chamado', 'x', 'ghost', 'Cancelar este chamado?');
} else {
    $andamento .= $statusForm('aberto', 'Reabrir', 'rotate-ccw');
}
$andamento .= '</div>';
if ($aberto) {
    $andamento .= '<form method="post" action="' . e(url("/chamados/{$id}/status")) . '" class="mt-4 grid gap-2">' . csrf_field()
        . '<input type="hidden" name="voltar" value="' . e($voltar) . '"><input type="hidden" name="status" value="concluido">'
        . campo(['nome' => 'resolucao', 'id' => 'ch-resolucao', 'rotulo' => 'Resolução (o que foi entregue)', 'tipo' => 'textarea', 'ia' => true, 'linhas' => 3, 'valor' => (string) $r['resolucao']])
        . '<div>' . botao('Concluir chamado', ['tipo' => 'submit', 'icone' => 'circle-check']) . '</div></form>';
} elseif (trim((string) $r['resolucao']) !== '') {
    $andamento .= '<div class="mt-4"><div class="text-muted-foreground text-xs font-medium uppercase">Resolução</div><p class="text-sm whitespace-pre-line">' . e((string) $r['resolucao']) . '</p></div>';
}

// ---- Checklist
$lista = '';
foreach ($itens as $i => $item) {
    $feito = $item['feito'] === 1;
    $lista .= '<li class="flex items-center gap-2 border-b py-2 last:border-b-0">'
        . '<form method="post" action="' . e(url("/chamados/{$id}/checklist/{$i}/alternar")) . '">' . csrf_field()
        . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
        . botao($feito ? 'Desmarcar item' : 'Marcar item como feito', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'icon-sm', 'icone' => $feito ? 'circle-check' : 'circle', 'classe' => $feito ? 'text-success' : 'text-muted-foreground']) . '</form>'
        . '<span class="min-w-0 flex-1 text-sm' . ($feito ? ' text-muted-foreground line-through' : '') . '">' . e($item['texto']) . '</span>'
        . '<form method="post" action="' . e(url("/chamados/{$id}/checklist/{$i}/remover")) . '">' . csrf_field() . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
        . botao('Remover item', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'icon-sm', 'icone' => 'x', 'classe' => 'text-muted-foreground']) . '</form></li>';
}
$checklist = ($total > 0
    ? '<div class="mb-3"><div class="text-muted-foreground mb-1 text-xs">' . $feitos . ' de ' . $total . ' feito(s) · ' . $percentual . '%</div>'
        . '<div class="bg-muted h-2 w-full overflow-hidden rounded-full" role="progressbar" aria-valuenow="' . $percentual . '" aria-valuemin="0" aria-valuemax="100"><div class="bg-success h-full" style="width:' . $percentual . '%"></div></div></div>'
        . '<ul>' . $lista . '</ul>'
    : '<p class="text-muted-foreground mb-3 text-sm">Sem itens ainda. Liste as etapas de execução deste chamado.</p>')
    . '<form method="post" action="' . e(url("/chamados/{$id}/checklist")) . '" class="mt-3 flex flex-wrap items-end gap-2">' . csrf_field()
    . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
    . '<div class="min-w-56 flex-1">' . campo(['nome' => 'texto', 'id' => 'ch-item', 'rotulo' => 'Novo item', 'obrigatorio' => true, 'attrs' => ['maxlength' => 200]]) . '</div>'
    . botao('Adicionar', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'plus']) . '</form>';

// ---- Histórico
$hist = '';
$rotulos = ['criar' => 'Aberto', 'atualizar' => 'Editado', 'mudar_status' => 'Status alterado', 'checklist_alternar' => 'Item do checklist marcado/desmarcado',
    'checklist_adicionar' => 'Item adicionado ao checklist', 'checklist_remover' => 'Item removido do checklist', 'arquivar' => 'Arquivado', 'desfazer' => 'Ação desfeita'];
foreach ($historico as $h) {
    $hist .= '<li class="flex justify-between gap-2 border-b py-1.5 text-sm last:border-b-0"><span>' . e($rotulos[$h['acao']] ?? $h['acao'])
        . ' <span class="text-muted-foreground text-xs">· ' . e($h['origem']) . '</span></span><span class="text-muted-foreground text-xs">' . e(datahora_br($h['data'])) . '</span></li>';
}
$atrasado = chamado_atrasado($r);
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e((string) $r['titulo']) ?></h1>
            <?= badge_chamado_status((string) $r['status']) ?>
            <?= badge_prioridade((string) $r['prioridade']) ?>
            <?= badge((string) $r['area_nome'], 'outline') ?>
        </div>
        <p class="text-muted-foreground"><?= e((string) $r['codigo']) ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Editar', ['href' => url("/chamados/{$id}/editar"), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?= botao('Tarefas e chamados', ['href' => url('/tarefas'), 'variante' => 'outline', 'icone' => 'list-checks']) ?>
        <?= botao_arquivar(url("/chamados/{$id}/arquivar"), 'Arquivar este chamado? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-4">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Dados', 'corpo_html' => ficha([
            'Área' => (string) $r['area_nome'], 'Prioridade' => $op('prioridade_tar', $r['prioridade']), 'Status' => $op('status_chamado', $r['status']),
            'Prazo' => ['html' => e(vencimento_br($r['vencimento'])) . ($atrasado ? ' ' . badge('atrasado', 'destructive') : '')],
            'Concluído em' => $r['concluido_em'] ? datahora_br((string) $r['concluido_em']) : '',
            'Aberto em' => datahora_br((string) $r['criado_em']),
        ], 1)]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Vínculos', 'corpo_html' => ficha([
            'Empresa' => $r['empresa_id'] ? ['html' => link_para('/empresas/' . (int) $r['empresa_id'], (string) $r['empresa_nome'])] : '',
            'Contato' => $r['contato_id'] ? ['html' => link_para('/contatos/' . (int) $r['contato_id'], (string) $r['contato_nome'])] : '',
            'Negócio' => $r['negocio_id'] ? ['html' => link_para('/negocios/' . (int) $r['negocio_id'], (string) $r['negocio_titulo'])] : '',
            'Contrato' => $r['contrato_id'] ? ['html' => link_para('/contratos/' . (int) $r['contrato_id'], (string) $r['contrato_numero'])] : '',
        ], 1)]) ?>
        <?php if (trim((string) $r['descricao']) !== ''): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Descrição', 'corpo_html' => '<p class="text-sm whitespace-pre-line">' . e((string) $r['descricao']) . '</p>']) ?>
        <?php endif; ?>
    </div>
    <div class="grid content-start gap-4 lg:col-span-2">
        <?= card(['titulo' => 'Andamento', 'corpo_html' => $andamento]) ?>
        <?= card(['titulo' => 'Checklist de execução', 'corpo_html' => $checklist]) ?>
        <?= card(['titulo' => 'Anexos', 'descricao' => 'Briefing, materiais e entregas.', 'corpo_html' => anexos_painel($anexos, 'chamados', $id, $voltar)]) ?>
        <?= card(['titulo' => 'Histórico', 'corpo_html' => $hist !== '' ? '<ul>' . $hist . '</ul>' : '<p class="text-muted-foreground text-sm">Sem registros.</p>']) ?>
    </div>
</div>
