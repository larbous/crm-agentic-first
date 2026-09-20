<?php
/**
 * Detalhe do contrato.
 * @var array $registro
 * @var list<array> $tarefas
 * @var list<array> $renovacoes
 * @var list<array> $historico
 * @var string $linkPublico
 * @var list<array> $anexos
 */
use App\Services\Html;
use App\Services\Schema;

$r = $registro;
$id = (int) $r['id'];
$voltar = '/contratos/' . $id;
$op = static fn (string $grupo, ?string $v) => $v !== null ? (Schema::opcoes($grupo)[$v] ?? $v) : '';
$rascunho = $r['status'] === 'rascunho';
$assinavel = in_array($r['status'], ['assinado', 'ativo', 'vencido'], true);
$acoesHistorico = ['criar' => 'Criado', 'atualizar' => 'Editado', 'enviar_contrato' => 'Enviado para assinatura', 'visualizar_contrato' => 'Visualizado pelo cliente',
    'assinar_contrato' => 'Assinado pelo cliente', 'cancelar_contrato' => 'Cancelado', 'renovar_contrato' => 'Renovado', 'arquivar' => 'Arquivado', 'desfazer' => 'Ação desfeita'];

$hist = '';
foreach ($historico as $h) {
    $hist .= '<li class="flex justify-between gap-2 border-b py-1.5 text-sm last:border-b-0"><span>' . e($acoesHistorico[$h['acao']] ?? $h['acao'])
        . ' <span class="text-muted-foreground text-xs">· ' . e($h['origem']) . '</span></span><span class="text-muted-foreground text-xs">' . e(datahora_br($h['data'])) . '</span></li>';
}

$abas = [
    ['rotulo' => 'Documento', 'html' => trim((string) $r['conteudo']) !== ''
        ? '<div class="documento" style="max-width:none;box-shadow:none"><div class="documento-corpo">' . Html::sanitizar((string) $r['conteudo']) . '</div></div>'
        : vazio('Contrato sem conteúdo', 'Edite o contrato para gerar o texto a partir de um modelo.', ['icone' => 'file-text', 'acao_html' => $rascunho ? botao('Editar', ['href' => url('/contratos/' . $id . '/editar'), 'icone' => 'pencil']) : ''])],
    ['rotulo' => 'Tarefas', 'contagem' => count(array_filter($tarefas, 'tarefa_aberta')), 'html' => tarefas_mini($tarefas, 'contrato_id', $id, $voltar)],
    ['rotulo' => 'Histórico', 'contagem' => count($historico), 'html' => $hist !== '' ? '<ul>' . $hist . '</ul>' : vazio('Sem histórico', '', ['icone' => 'scroll-text'])],
    ['rotulo' => 'Anexos', 'contagem' => count($anexos), 'html' => anexos_painel($anexos, 'contratos', $id, $voltar)],
];
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="text-muted-foreground text-sm"><?= e($r['numero']) ?><?= $r['origem_numero'] ? ' · renova ' . link_para('/contratos/' . (int) $r['contrato_origem_id'], $r['origem_numero']) : '' ?></div>
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e($r['titulo']) ?></h1>
            <?= badge_contrato($r) ?>
            <?php if ($r['tipo_nome']): ?><?= badge($r['tipo_nome'], 'outline') ?><?php endif; ?>
        </div>
        <p class="text-muted-foreground">
            <?php if ($r['empresa_id']): ?><?= link_para('/empresas/' . (int) $r['empresa_id'], (string) $r['empresa_nome']) ?><?php endif; ?>
            <?php if ($r['proposta_id']): ?> · proposta <?= link_para('/propostas/' . (int) $r['proposta_id'], (string) $r['proposta_numero']) ?><?php endif; ?>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($rascunho): ?>
            <form method="post" action="<?= e(url('/contratos/' . $id . '/enviar')) ?>" data-confirmar="Marcar como enviado? O link de assinatura passa a funcionar.">
                <?= csrf_field() ?><?= botao('Marcar como enviado', ['tipo' => 'submit', 'icone' => 'send']) ?>
            </form>
            <?= botao('Editar', ['href' => url('/contratos/' . $id . '/editar'), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?php endif; ?>
        <?php if ($assinavel): ?>
            <form method="post" action="<?= e(url('/contratos/' . $id . '/renovar')) ?>" data-confirmar="Renovar este contrato? Um novo contrato em rascunho será criado e este ficará como renovado.">
                <?= csrf_field() ?><?= botao('Renovar', ['tipo' => 'submit', 'variante' => 'secondary', 'icone' => 'refresh-cw']) ?>
            </form>
        <?php endif; ?>
        <?php if (in_array($r['status'], ['rascunho', 'enviado', 'assinado', 'ativo'], true)): ?>
            <form method="post" action="<?= e(url('/contratos/' . $id . '/cancelar')) ?>" data-confirmar="Cancelar este contrato?">
                <?= csrf_field() ?><?= botao('Cancelar contrato', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'ban']) ?>
            </form>
        <?php endif; ?>
        <?= botao('Ver impressão', ['href' => url('/contratos/' . $id . '/imprimir'), 'variante' => 'outline', 'icone' => 'printer']) ?>
        <?= botao_arquivar(url('/contratos/' . $id . '/arquivar'), 'Arquivar este contrato? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<?php if (!$rascunho): ?>
    <?= card(['tamanho' => 'sm', 'corpo_html' =>
        '<div class="flex flex-wrap items-center gap-2"><div class="min-w-0 flex-1"><div class="text-muted-foreground text-xs">Link de assinatura (o cliente acessa sem login)</div>'
        . '<code class="text-sm break-all">' . e($linkPublico) . '</code></div>'
        . botao('Copiar link', ['variante' => 'outline', 'icone' => 'copy', 'attrs' => ['data-copiar' => $linkPublico]])
        . botao('Abrir', ['href' => $linkPublico, 'variante' => 'ghost', 'icone' => 'external-link', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) . '</div>']) ?>
<?php endif; ?>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-4">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Valores', 'corpo_html' => ficha([
            'Valor total' => $r['valor_total'] !== null ? moeda((int) $r['valor_total']) : '', 'Valor mensal' => $r['valor_mensal'] !== null ? moeda((int) $r['valor_mensal']) : '',
            'Recorrência' => $op('recorrencia_contrato', $r['recorrencia']), 'Vencimento do pagamento' => $r['dia_vencimento_pagamento'] ? 'Dia ' . $r['dia_vencimento_pagamento'] : '',
            'Forma de pagamento' => (string) $r['forma_pagamento'],
        ])]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Vigência e reajuste', 'corpo_html' => ficha([
            'Início' => data_br($r['data_inicio']), 'Fim' => ['html' => fim_vigencia_html($r)], 'Renovação automática' => $r['renovacao_automatica'] ? 'Sim' : 'Não',
            'Aviso de renovação' => $r['aviso_renovacao_dias'] !== null ? $r['aviso_renovacao_dias'] . ' dias' : '',
            'Reajuste' => $op('indice_reajuste', $r['indice_reajuste']) . ($r['percentual_reajuste'] !== null ? ' · ' . percentual_br((int) $r['percentual_reajuste']) : ''),
            'Próximo reajuste' => data_br($r['data_proximo_reajuste']), 'Multa rescisória' => $r['multa_rescisoria'] !== null ? percentual_br((int) $r['multa_rescisoria']) : '',
            'Aviso prévio' => $r['aviso_previo_dias'] !== null ? $r['aviso_previo_dias'] . ' dias' : '',
        ])]) ?>
        <?php if ($r['assinado_em']): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Assinatura', 'corpo_html' => ficha([
                'Nome' => (string) $r['assinatura_nome'], 'Documento' => documento_formatado($r['assinatura_documento']), 'IP' => (string) $r['assinatura_ip'], 'Data' => datahora_br($r['assinado_em']),
            ], 1)]) ?>
        <?php endif; ?>
        <?php if ($renovacoes !== []): ?>
            <?php $lr = '<ul>'; foreach ($renovacoes as $c) { $lr .= '<li class="flex items-center justify-between gap-2 py-1">' . link_para('/contratos/' . (int) $c['id'], $c['numero']) . badge_contrato($c) . '</li>'; } ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Renovações', 'corpo_html' => $lr . '</ul>']) ?>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2">
        <?= card(['corpo_html' => abas('abas-contrato', $abas, 0, ['linha' => true])]) ?>
    </div>
</div>
