<?php
/**
 * Uma conversa: mensagens, resposta pelo canal, vínculo com contato e situação.
 * @var array $conversa
 * @var list<array> $mensagens
 * @var bool $canalAtivo
 * @var bool $dentroDaJanela
 * @var array<int,string> $contatos id => nome
 * @var array<int,string> $empresas id => nome
 */
use App\Services\Canais\Canais;

$c = $conversa;
$id = (int) $c['id'];
$quem = $c['contato_nome'] ?: ($c['nome'] ?: $c['identificador']);
$canal = (string) $c['canal'];

$lista = '';
foreach ($mensagens as $m) {
    $lista .= bolha_mensagem($m);
}

// ---- Resposta
if (!$canalAtivo) {
    $resposta = alerta('Canal não configurado', 'Configure ' . Canais::ROTULOS[$canal] . ' em config.local.php para responder daqui (docs/INSTALACAO.md).', 'default');
} elseif (!$dentroDaJanela) {
    $resposta = alerta('Fora da janela de ' . Canais::JANELA_HORAS . ' h', 'A ' . Canais::ROTULOS[$canal] . ' só permite mensagem livre até ' . Canais::JANELA_HORAS . ' h depois da última mensagem do cliente. Espere ele escrever de novo ou fale por outro canal.', 'default');
} else {
    $resposta = '<form method="post" action="' . e(url("/caixa/{$id}/enviar")) . '" class="grid gap-2">' . csrf_field()
        . campo(['nome' => 'texto', 'id' => 'cx-texto', 'rotulo' => 'Responder por ' . Canais::ROTULOS[$canal], 'tipo' => 'textarea', 'ia' => true, 'linhas' => 3, 'obrigatorio' => true, 'attrs' => ['maxlength' => 4000]])
        . '<div>' . botao('Enviar', ['tipo' => 'submit', 'icone' => 'send']) . '</div></form>';
}

// ---- Vínculo
$vinculo = '';
if ($c['contato_id'] === null) {
    $vinculo = '<p class="text-muted-foreground mb-3 text-sm">Esta conversa ainda não está ligada a um contato: as mensagens só entram na timeline depois do vínculo.</p>'
        . '<form method="post" action="' . e(url("/caixa/{$id}/vincular")) . '" class="mb-4 flex flex-wrap items-end gap-2">' . csrf_field()
        . '<div class="min-w-56 flex-1"><label class="text-sm font-medium" for="cx-contato">Vincular a um contato existente</label>'
        . select('contato_id', $contatos, null, ['id' => 'cx-contato', 'placeholder' => 'Escolha…', 'obrigatorio' => true]) . '</div>'
        . botao('Vincular', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'link']) . '</form>'
        . '<form method="post" action="' . e(url("/caixa/{$id}/criar-contato")) . '" class="flex flex-wrap items-end gap-2">' . csrf_field()
        . '<div class="min-w-48 flex-1">' . campo(['nome' => 'nome', 'id' => 'cx-nome', 'rotulo' => 'Ou criar um contato novo', 'valor' => (string) ($c['nome'] ?? ''), 'attrs' => ['maxlength' => 120]]) . '</div>'
        . '<div class="min-w-48 flex-1"><label class="text-sm font-medium" for="cx-empresa">Empresa (opcional)</label>' . select('empresa_id', $empresas, null, ['id' => 'cx-empresa', 'placeholder' => '— Nenhuma —']) . '</div>'
        . botao('Criar contato', ['tipo' => 'submit', 'icone' => 'user-plus']) . '</form>';
}
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e((string) $quem) ?></h1>
            <?= badge_canal($canal) ?>
            <?= $c['status'] === 'resolvida' ? badge('Resolvida', 'secondary') : '' ?>
        </div>
        <p class="text-muted-foreground">
            <?= $c['empresa_id'] ? link_para('/empresas/' . (int) $c['empresa_id'], (string) $c['empresa_nome']) . ' · ' : '' ?>
            <?= $c['contato_id'] ? link_para('/contatos/' . (int) $c['contato_id'], (string) $c['contato_nome']) . ' · ' : '' ?>
            <?= e((string) $c['identificador']) ?><?= $canal === 'email' && $c['assunto'] ? ' · ' . e((string) $c['assunto']) : '' ?>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Caixa de entrada', ['href' => url('/caixa'), 'variante' => 'outline', 'icone' => 'inbox']) ?>
        <?php if ($c['status'] === 'aberta'): ?>
            <form method="post" action="<?= e(url("/caixa/{$id}/resolver")) ?>"><?= csrf_field() ?><?= botao('Resolver', ['tipo' => 'submit', 'variante' => 'secondary', 'icone' => 'circle-check']) ?></form>
        <?php else: ?>
            <form method="post" action="<?= e(url("/caixa/{$id}/reabrir")) ?>"><?= csrf_field() ?><?= botao('Reabrir', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'rotate-ccw']) ?></form>
        <?php endif; ?>
    </div>
</div>

<div class="grid gap-4">
    <?php if ($vinculo !== ''): ?><?= card(['tamanho' => 'sm', 'titulo' => 'Quem é', 'corpo_html' => $vinculo]) ?><?php endif; ?>
    <?= card(['titulo' => 'Mensagens', 'corpo_html' => $lista !== '' ? '<ul class="grid gap-2">' . $lista . '</ul>' : vazio('Sem mensagens', '', ['icone' => 'message-square'])]) ?>
    <?= card(['titulo' => 'Resposta', 'corpo_html' => $resposta]) ?>
</div>
