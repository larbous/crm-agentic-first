<?php
/**
 * Caixa de entrada: conversas de WhatsApp, Instagram e e-mail.
 * @var array $resultado resultado paginado de ConversaRepository::listar
 * @var array $filtros canal, situacao, q, nao_lidas
 * @var callable $urlPagina
 * @var array<string,bool> $canais canal => configurado?
 */
use App\Services\Canais\Canais;

$linhas = [];
foreach ($resultado['linhas'] as $c) {
    $id = (int) $c['id'];
    $quem = $c['contato_nome'] ?: ($c['nome'] ?: $c['identificador']);
    $linhas[] = [
        'canal' => ['html' => badge_canal((string) $c['canal'])],
        'quem' => ['html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url("/caixa/{$id}")) . '">' . e((string) $quem) . '</a>'
            . '<div class="text-muted-foreground text-xs">' . ($c['empresa_nome'] ? e((string) $c['empresa_nome']) : ($c['contato_id'] === null ? 'Sem contato vinculado · ' . e((string) $c['identificador']) : '')) . '</div>'],
        'previa' => ['html' => '<span class="' . ((int) $c['nao_lidas'] > 0 ? 'font-medium' : 'text-muted-foreground') . '">' . ($c['ultima_direcao'] === 'saida' ? 'Você: ' : '') . e((string) $c['ultima_previa']) . '</span>'
            . ($c['canal'] === 'email' && $c['assunto'] ? '<div class="text-muted-foreground text-xs">' . e((string) $c['assunto']) . '</div>' : '')],
        'quando' => datahora_br((string) $c['ultima_mensagem_em']),
        'situacao' => ['html' => ((int) $c['nao_lidas'] > 0 ? badge((int) $c['nao_lidas'] . ' nova(s)', 'warning') . ' ' : '') . ($c['status'] === 'resolvida' ? badge('Resolvida', 'secondary') : '')],
    ];
}

$filtro = '<form method="get" action="' . e(url('/caixa')) . '" class="flex flex-wrap items-center gap-2">'
    . '<label class="sr-only" for="cx-q">Buscar</label><input id="cx-q" class="input w-56" type="search" name="q" value="' . e($filtros['q']) . '" placeholder="Buscar conversa…">'
    . select('canal', ['' => 'Todos os canais'] + Canais::ROTULOS, $filtros['canal'], ['id' => 'cx-canal', 'attrs' => ['class' => 'select w-auto', 'onchange' => 'this.form.requestSubmit()']])
    . select('situacao', ['abertas' => 'Abertas', 'resolvidas' => 'Resolvidas', 'todas' => 'Todas'], $filtros['situacao'], ['id' => 'cx-situacao', 'attrs' => ['class' => 'select w-auto', 'onchange' => 'this.form.requestSubmit()']])
    . '<label class="flex items-center gap-1 text-sm"><input type="checkbox" class="input" name="nao_lidas" value="1"' . ($filtros['nao_lidas'] ? ' checked' : '') . ' onchange="this.form.requestSubmit()"> Só não lidas</label>'
    . '</form>';

$avisos = '';
foreach ($canais as $canal => $ativo) {
    if (!$ativo) {
        $avisos .= '<span class="mr-3">' . badge(Canais::ROTULOS[$canal], 'outline') . ' não configurado</span>';
    }
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Caixa de entrada</h1>
        <p class="text-muted-foreground">Conversas de WhatsApp, Instagram e e-mail num só lugar. Cada mensagem também entra na timeline do contato.</p>
    </div>
    <?= $filtro ?>
</div>

<?php if ($avisos !== ''): ?>
    <p class="text-muted-foreground text-sm"><?= $avisos ?>· veja <code>docs/INSTALACAO.md</code> para ligar cada canal.</p>
<?php endif; ?>

<?php if ($linhas === []): ?>
    <?= card(['corpo_html' => vazio('Nenhuma conversa', 'As mensagens recebidas aparecem aqui, mais recentes primeiro.', ['icone' => 'inbox'])]) ?>
<?php else: ?>
    <?= card(['corpo_html' => tabela(['canal' => 'Canal', 'quem' => 'Conversa', 'previa' => 'Última mensagem', 'quando' => 'Quando', 'situacao' => ''], $linhas)
        . ($resultado['paginas'] > 1
            ? '<div class="mt-3 flex items-center justify-between text-sm"><span class="text-muted-foreground">Página ' . (int) $resultado['pagina'] . ' de ' . (int) $resultado['paginas'] . ' · ' . (int) $resultado['total'] . ' conversa(s)</span><div class="flex gap-2">'
                . ($resultado['pagina'] > 1 ? botao('Anterior', ['href' => $urlPagina($resultado['pagina'] - 1), 'variante' => 'outline', 'tamanho' => 'sm']) : '')
                . ($resultado['pagina'] < $resultado['paginas'] ? botao('Próxima', ['href' => $urlPagina($resultado['pagina'] + 1), 'variante' => 'outline', 'tamanho' => 'sm']) : '') . '</div></div>'
            : '')]) ?>
<?php endif; ?>
