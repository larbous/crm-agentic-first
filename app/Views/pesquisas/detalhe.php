<?php
/**
 * Um envio de pesquisa NPS: link individual, atalhos de WhatsApp/e-mail e, se respondida, a resposta.
 * @var array $pesquisa linha de PesquisaRepository::encontrar
 */
$p = $pesquisa;
$id = (int) $p['id'];
$link = pesquisa_link($p);
$pendente = $p['status'] === 'pendente' && strtotime((string) $p['expira_em']) >= time();
$whatsapp = pesquisa_url_whatsapp($p);
$email = pesquisa_url_email($p);
$voltar = '/pesquisas/' . $id;

$envio = '';
if ($pendente) {
    $envio = '<div class="grid gap-3">'
        . '<div class="grid gap-1"><label class="text-sm font-medium" for="pesq-link">Link individual (vale até ' . e(data_br(substr((string) $p['expira_em'], 0, 10))) . ')</label>'
        . '<div class="flex flex-wrap items-center gap-2"><input id="pesq-link" class="input min-w-64 flex-1" type="text" readonly value="' . e($link) . '">'
        . botao('Copiar link', ['variante' => 'outline', 'icone' => 'copy', 'attrs' => ['data-copiar' => $link, 'type' => 'button']]) . '</div></div>'
        . '<div class="grid gap-1"><span class="text-sm font-medium">Mensagem pronta</span>'
        . '<div class="flex flex-wrap items-start gap-2"><p class="bg-muted flex-1 rounded-md p-3 text-sm">' . e(pesquisa_mensagem($p)) . '</p>'
        . botao('Copiar mensagem', ['variante' => 'outline', 'icone' => 'copy', 'attrs' => ['data-copiar' => pesquisa_mensagem($p), 'type' => 'button']]) . '</div></div>'
        . '<div class="flex flex-wrap items-center gap-2">'
        . ($whatsapp !== null ? botao('Abrir no WhatsApp', ['href' => $whatsapp, 'icone' => 'message-square', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']]) : '<span class="text-muted-foreground text-sm">Contato sem WhatsApp.</span>')
        . ($email !== null ? botao('Abrir no e-mail', ['href' => $email, 'variante' => 'outline', 'icone' => 'send']) : '')
        . botao('Ver como o cliente vê', ['href' => $link, 'variante' => 'ghost', 'icone' => 'external-link', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']])
        . '</div>'
        . '<div class="flex flex-wrap items-center gap-2 border-t pt-3">'
        . ($p['enviada_em'] === null
            ? '<form method="post" action="' . e(url("/pesquisas/{$id}/enviada")) . '">' . csrf_field() . botao('Marcar como enviada', ['tipo' => 'submit', 'variante' => 'secondary', 'icone' => 'circle-check']) . '</form>'
            : badge('Enviada em ' . datahora_br((string) $p['enviada_em']), 'secondary'))
        . '<form method="post" action="' . e(url("/pesquisas/{$id}/cancelar")) . '" data-confirmar="Cancelar esta pesquisa? O link deixa de funcionar.">' . csrf_field()
        . '<input type="hidden" name="voltar" value="/pesquisas">' . botao('Cancelar pesquisa', ['tipo' => 'submit', 'variante' => 'ghost', 'icone' => 'x']) . '</form>'
        . '</div></div>';
}

$resposta = '';
if ($p['status'] === 'respondida') {
    $extras = [];
    foreach ($p['respostas'] as $r) {
        $extras[(string) $r['rotulo']] = (string) $r['valor'];
    }
    $resposta = card(['titulo' => 'Resposta', 'descricao' => 'Recebida em ' . datahora_br((string) $p['respondida_em']),
        'corpo_html' => '<div class="mb-3">' . badge_nps((int) $p['nota'], (string) $p['categoria']) . '</div>'
            . ficha(['Comentário' => (string) $p['comentario']] + $extras, 1)]);
}
?>
<div class="page-cabecalho">
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e((string) $p['empresa_nome']) ?></h1>
            <?= badge_pesquisa_status($p) ?>
        </div>
        <p class="text-muted-foreground"><?= e((string) $p['formulario_nome']) ?><?= $p['contato_nome'] ? ' · ' . e((string) $p['contato_nome']) : '' ?><?= $p['contrato_numero'] ? ' · contrato ' . e((string) $p['contrato_numero']) : '' ?></p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Empresa', ['href' => url('/empresas/' . (int) $p['empresa_id']), 'variante' => 'outline', 'icone' => 'building']) ?>
        <?= botao('Pesquisas NPS', ['href' => url('/pesquisas'), 'variante' => 'outline']) ?>
    </div>
</div>

<div class="grid gap-4">
    <?php if ($pendente): ?>
        <?= card(['titulo' => 'Enviar ao cliente', 'descricao' => 'O CRM ainda não entrega mensagens sozinho (canais chegam com a Fase 14): use os atalhos abaixo.', 'corpo_html' => $envio]) ?>
    <?php elseif ($p['status'] !== 'respondida'): ?>
        <?= alerta($p['status'] === 'cancelada' ? 'Pesquisa cancelada' : 'Pesquisa expirada', 'O link não aceita mais respostas.', 'default') ?>
    <?php endif; ?>
    <?= $resposta ?>
</div>
