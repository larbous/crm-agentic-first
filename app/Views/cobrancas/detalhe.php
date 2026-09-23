<?php
/**
 * Detalhe da cobrança (Fase 17): dados, situação no Asaas e histórico.
 * @var array $registro
 * @var list<array> $historico
 */
use App\Services\Schema;

$r = $registro;
$id = (int) $r['id'];
$voltar = '/financeiro/cobrancas/' . $id;
$op = static fn (string $grupo, ?string $v) => $v !== null ? (Schema::opcoes($grupo)[$v] ?? $v) : '';

$acoes = '';
if ($r['asaas_id'] === null) {
    $acoes .= '<form method="post" action="' . e(url("/financeiro/cobrancas/{$id}/emitir")) . '">' . csrf_field()
        . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
        . botao('Emitir no Asaas', ['tipo' => 'submit', 'icone' => 'send']) . '</form>';
} elseif ($r['url_fatura']) {
    $acoes .= botao('Ver fatura', ['href' => (string) $r['url_fatura'], 'variante' => 'outline', 'icone' => 'external-link', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]);
}
if (!in_array($r['status'], ['cancelado', 'pago'], true)) {
    $acoes .= '<form method="post" action="' . e(url("/financeiro/cobrancas/{$id}/cancelar")) . '" data-confirmar="Cancelar esta cobrança? Se já emitida, também será cancelada no Asaas.">' . csrf_field()
        . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
        . botao('Cancelar cobrança', ['tipo' => 'submit', 'variante' => 'ghost', 'icone' => 'x']) . '</form>';
}

// Falha da última tentativa de emissão: fica na tela até emitir com sucesso (o toast some em segundos).
$avisoAsaas = '';
if ($r['asaas_id'] === null && trim((string) ($r['asaas_erro'] ?? '')) !== '') {
    $avisoAsaas = '<div class="mb-4">' . alerta('Não foi possível emitir no Asaas', (string) $r['asaas_erro'], 'destructive') . '</div>';
}

$hist = '';
$rotulos = ['criar' => 'Criada', 'atualizar' => 'Editada', 'emitir' => 'Emitida no Asaas', 'cancelar' => 'Cancelada', 'status_asaas' => 'Status atualizado pelo Asaas', 'arquivar' => 'Arquivada', 'desfazer' => 'Ação desfeita'];
foreach ($historico as $h) {
    $hist .= '<li class="flex justify-between gap-2 border-b py-1.5 text-sm last:border-b-0"><span>' . e($rotulos[$h['acao']] ?? $h['acao'])
        . ' <span class="text-muted-foreground text-xs">· ' . e($h['origem']) . '</span></span><span class="text-muted-foreground text-xs">' . e(datahora_br($h['data'])) . '</span></li>';
}
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e((string) $r['descricao']) ?></h1>
            <?= badge_cobranca((string) $r['status']) ?>
            <?= badge($op('tipo_cobranca', $r['tipo']), 'outline') ?>
        </div>
        <p class="text-muted-foreground"><?= e(moeda((int) $r['valor'])) ?> · vencimento <?= e(data_br((string) $r['vencimento'])) ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= $acoes ?>
        <?= botao('Editar', ['href' => url("/financeiro/cobrancas/{$id}/editar"), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?= botao_arquivar(url("/financeiro/cobrancas/{$id}/arquivar"), 'Arquivar esta cobrança? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<?= $avisoAsaas ?>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-4">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Dados', 'corpo_html' => ficha([
            'Empresa' => ['html' => link_para('/empresas/' . (int) $r['empresa_id'], (string) $r['empresa_nome'])],
            'Negócio' => $r['negocio_id'] ? ['html' => link_para('/negocios/' . (int) $r['negocio_id'], (string) $r['negocio_titulo'])] : '',
            'Contrato' => $r['contrato_id'] ? ['html' => link_para('/contratos/' . (int) $r['contrato_id'], (string) $r['contrato_numero'])] : '',
            'Forma de pagamento' => $op('forma_pagamento_asaas', $r['forma_pagamento']),
            'Ciclo' => $r['ciclo'] ? $op('ciclo_cobranca', $r['ciclo']) : '',
            'Data do pagamento' => $r['data_pagamento'] ? data_br((string) $r['data_pagamento']) : '',
        ], 1)]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Asaas', 'corpo_html' => ficha([
            'Cliente' => (string) ($r['asaas_customer_id'] ?? ''),
            'Cobrança/assinatura' => (string) ($r['asaas_id'] ?? 'Não emitida'),
            'NF-e' => trim((string) $r['nfe_status']) !== '' ? ($r['nfe_url'] ? ['html' => '<a class="underline-offset-4 hover:underline" target="_blank" rel="noopener" href="' . e((string) $r['nfe_url']) . '">' . e((string) $r['nfe_status']) . '</a>'] : (string) $r['nfe_status']) : 'Não emitida',
        ], 1)]) ?>
        <?php if (trim((string) $r['notas']) !== ''): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Notas', 'corpo_html' => '<p class="text-sm whitespace-pre-line">' . e((string) $r['notas']) . '</p>']) ?>
        <?php endif; ?>
    </div>
    <div class="grid content-start gap-4 lg:col-span-2">
        <?= card(['titulo' => 'Histórico', 'corpo_html' => $hist !== '' ? '<ul>' . $hist . '</ul>' : '<p class="text-muted-foreground text-sm">Sem registros.</p>']) ?>
    </div>
</div>
