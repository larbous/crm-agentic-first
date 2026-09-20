<?php
/**
 * Detalhe da proposta.
 * @var array $registro
 * @var list<array> $itens
 * @var list<array> $versoes
 * @var bool $ehUltima
 * @var list<array> $historico
 * @var list<array> $contratos
 * @var string $linkPublico
 * @var list<array> $anexos
 */
$r = $registro;
$id = (int) $r['id'];
$voltar = '/propostas/' . $id;
$rascunho = $r['status'] === 'rascunho';
$acoesHistorico = ['criar' => 'Criada', 'atualizar' => 'Editada', 'enviar_proposta' => 'Enviada', 'visualizar_proposta' => 'Visualizada pelo cliente',
    'aceitar_proposta' => 'Aceita pelo cliente', 'recusar_proposta' => 'Recusada pelo cliente', 'nova_versao' => 'Nova versão criada', 'arquivar' => 'Arquivada', 'desfazer' => 'Ação desfeita'];

$linhasItens = [];
foreach ($itens as $i) {
    $linhasItens[] = [
        'descricao' => ['html' => e($i['descricao']) . ((int) $i['recorrente'] === 1 ? ' ' . badge('Recorrente', 'info') : '')],
        'qtd'       => rtrim(rtrim(number_format((float) $i['quantidade'], 3, ',', '.'), '0'), ',') . ($i['unidade'] ? ' ' . $i['unidade'] : ''),
        'unitario'  => moeda((int) $i['valor_unitario']),
        'desconto'  => (int) $i['desconto'] > 0 ? moeda((int) $i['desconto']) : '',
        'total'     => moeda((int) $i['total']),
    ];
}
$descontoTotal = (int) $r['subtotal'] - (int) $r['total'];

$listaVersoes = '<ul>';
foreach ($versoes as $v) {
    $listaVersoes .= '<li class="flex items-center justify-between gap-2 border-b py-1.5 last:border-b-0"><span>'
        . ((int) $v['id'] === $id ? '<strong>v' . (int) $v['versao'] . '</strong>' : link_para('/propostas/' . (int) $v['id'], 'v' . (int) $v['versao']))
        . ' <span class="text-muted-foreground text-xs">' . e(data_br($v['data_emissao'])) . '</span></span>' . badge_proposta($v) . '</li>';
}
$listaVersoes .= '</ul>';

$hist = '';
foreach ($historico as $h) {
    $hist .= '<li class="flex justify-between gap-2 border-b py-1.5 text-sm last:border-b-0"><span>' . e($acoesHistorico[$h['acao']] ?? $h['acao'])
        . ' <span class="text-muted-foreground text-xs">· ' . e($h['origem']) . '</span></span><span class="text-muted-foreground text-xs">' . e(datahora_br($h['data'])) . '</span></li>';
}

$abas = [
    ['rotulo' => 'Documento', 'html' =>
        ($linhasItens !== [] ? tabela(['descricao' => 'Item', 'qtd' => 'Qtd.', 'unitario' => 'Unitário', 'desconto' => 'Desconto', 'total' => 'Total'], $linhasItens)
            : vazio('Sem itens', 'Adicione itens editando a proposta.', ['icone' => 'receipt-text']))
        . '<dl class="mt-4 ml-auto grid max-w-xs gap-1 text-sm"><div class="flex justify-between"><dt class="text-muted-foreground">Subtotal</dt><dd>' . e(moeda((int) $r['subtotal'])) . '</dd></div>'
        . ($descontoTotal > 0 ? '<div class="flex justify-between"><dt class="text-muted-foreground">Desconto' . ($r['desconto_tipo'] === 'percentual' ? ' (' . e(percentual_br((int) $r['desconto_valor'])) . ')' : '') . '</dt><dd>− ' . e(moeda($descontoTotal)) . '</dd></div>' : '')
        . '<div class="flex justify-between border-t pt-1 text-base font-semibold"><dt>Total</dt><dd>' . e(moeda((int) $r['total'])) . '</dd></div>'
        . ((int) $r['total_recorrente'] > 0 ? '<div class="text-muted-foreground flex justify-between"><dt>Parte recorrente</dt><dd>' . e(moeda((int) $r['total_recorrente'])) . '</dd></div>' : '')
        . '</dl>'
        . '<div class="mt-6">' . ficha([
            'Forma de pagamento' => (string) $r['forma_pagamento'], 'Parcelas' => $r['parcelas'] ? $r['parcelas'] . 'x' : '',
            'Entrada' => $r['entrada_percentual'] !== null ? $r['entrada_percentual'] . '%' : '', 'Prazo de entrega' => $r['prazo_entrega_dias'] !== null ? $r['prazo_entrega_dias'] . ' dias' : '',
            'Condições de pagamento' => (string) $r['condicoes_pagamento'],
        ]) . '</div>'
        . '<div class="mt-6 grid gap-4">' . implode('', array_map(
            static fn (string $campo, string $rotulo): string => trim((string) $r[$campo]) === '' ? ''
                : '<div><h3 class="mb-1 text-sm font-semibold">' . e($rotulo) . '</h3><p class="text-sm whitespace-pre-line">' . e($r[$campo]) . '</p></div>',
            ['apresentacao', 'escopo', 'fora_escopo', 'cronograma', 'garantia', 'observacoes'],
            ['Apresentação', 'Escopo', 'Fora do escopo', 'Cronograma', 'Garantia', 'Observações'],
        )) . '</div>'],
    ['rotulo' => 'Histórico', 'contagem' => count($historico), 'html' => $hist !== '' ? '<ul>' . $hist . '</ul>' : vazio('Sem histórico', '', ['icone' => 'scroll-text'])],
    ['rotulo' => 'Anexos', 'contagem' => count($anexos), 'html' => anexos_painel($anexos, 'propostas', $id, $voltar)],
];
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="text-muted-foreground text-sm"><?= e($r['numero']) ?> · versão <?= (int) $r['versao'] ?><?= !$ehUltima ? ' (histórico)' : '' ?></div>
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e($r['titulo']) ?></h1>
            <?= badge_proposta($r) ?>
        </div>
        <p class="text-muted-foreground">
            <?php if ($r['empresa_id']): ?><?= link_para('/empresas/' . (int) $r['empresa_id'], (string) $r['empresa_nome']) ?><?php endif; ?>
            <?php if ($r['negocio_id']): ?> · <?= link_para('/negocios/' . (int) $r['negocio_id'], trim($r['negocio_codigo'] . ' ' . $r['negocio_titulo'])) ?><?php endif; ?>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= agentes_botoes('propostas', $id) ?>
        <?php if ($rascunho): ?>
            <form method="post" action="<?= e(url('/propostas/' . $id . '/enviar')) ?>" data-confirmar="Marcar como enviada? O link público passa a funcionar.">
                <?= csrf_field() ?><?= botao('Marcar como enviada', ['tipo' => 'submit', 'icone' => 'send']) ?>
            </form>
            <?= botao('Editar', ['href' => url('/propostas/' . $id . '/editar'), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?php elseif ($ehUltima): ?>
            <form method="post" action="<?= e(url('/propostas/' . $id . '/nova-versao')) ?>" data-confirmar="Criar uma nova versão em rascunho a partir desta?">
                <?= csrf_field() ?><?= botao('Nova versão', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'file-plus']) ?>
            </form>
        <?php endif; ?>
        <?php if ($r['status'] === 'aceita' && $ehUltima): ?>
            <?= botao('Criar contrato', ['href' => url('/contratos/nova?proposta_id=' . $id), 'variante' => 'secondary', 'icone' => 'file-pen']) ?>
        <?php endif; ?>
        <?= botao('Ver impressão', ['href' => url('/propostas/' . $id . '/imprimir'), 'variante' => 'outline', 'icone' => 'printer']) ?>
        <?= botao_arquivar(url('/propostas/' . $id . '/arquivar'), 'Arquivar esta versão da proposta? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<?php if (!$rascunho): ?>
    <?= card(['tamanho' => 'sm', 'corpo_html' =>
        '<div class="flex flex-wrap items-center gap-2"><div class="min-w-0 flex-1"><div class="text-muted-foreground text-xs">Link público (o cliente acessa sem login)</div>'
        . '<code class="text-sm break-all">' . e($linkPublico) . '</code></div>'
        . botao('Copiar link', ['variante' => 'outline', 'icone' => 'copy', 'attrs' => ['data-copiar' => $linkPublico]])
        . botao('Abrir', ['href' => $linkPublico, 'variante' => 'ghost', 'icone' => 'external-link', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) . '</div>']) ?>
<?php endif; ?>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-4">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Resumo', 'corpo_html' => ficha([
            'Total' => moeda((int) $r['total']), 'Emissão' => data_br($r['data_emissao']), 'Validade' => data_br($r['validade']),
            'Enviada em' => datahora_br($r['enviada_em']), 'Visualizada em' => datahora_br($r['visualizada_em']), 'Respondida em' => datahora_br($r['respondida_em']),
            'Contato' => $r['contato_id'] ? ['html' => link_para('/contatos/' . (int) $r['contato_id'], (string) $r['contato_nome'])] : '',
            'Modelo' => (string) $r['modelo_nome'],
        ])]) ?>
        <?php if ($r['status'] === 'aceita'): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Aceite', 'corpo_html' => ficha([
                'Nome' => (string) $r['aceite_nome'], 'Documento' => documento_formatado($r['aceite_documento']), 'IP' => (string) $r['aceite_ip'], 'Data' => datahora_br($r['respondida_em']),
            ], 1)]) ?>
        <?php elseif ($r['status'] === 'recusada'): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Recusa', 'corpo_html' => ficha(['Motivo' => (string) $r['motivo_recusa'], 'Data' => datahora_br($r['respondida_em'])], 1)]) ?>
        <?php endif; ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Versões', 'corpo_html' => $listaVersoes]) ?>
        <?php if ($contratos !== []): ?>
            <?php $lc = '<ul>'; foreach ($contratos as $c) { $lc .= '<li class="flex items-center justify-between gap-2 py-1">' . link_para('/contratos/' . (int) $c['id'], $c['numero']) . badge_contrato($c) . '</li>'; } ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Contratos', 'corpo_html' => $lc . '</ul>']) ?>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2">
        <?= card(['corpo_html' => abas('abas-proposta', $abas, 0, ['linha' => true])]) ?>
    </div>
</div>
