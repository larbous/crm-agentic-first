<?php
/**
 * Submissões dos formulários de captação.
 * @var array{linhas:list<array>,total:int,pagina:int,paginas:int} $resultado
 * @var array{formulario_id:int,status:string} $filtros
 * @var array<int,string> $formulariosOpcoes
 * @var callable $urlPagina
 * @var int $porPagina
 */
$status = ['processada' => ['Processada', 'success'], 'duplicada' => ['Duplicada', 'warning'], 'spam' => ['Spam', 'destructive']];

$linhas = [];
foreach ($resultado['linhas'] as $s) {
    [$rotuloStatus, $variante] = $status[$s['status']] ?? [$s['status'], 'secondary'];
    $quem = '';
    if ($s['contato_id'] !== null) {
        $quem .= '<a class="underline-offset-4 hover:underline" href="' . e(url('/contatos/' . (int) $s['contato_id'])) . '">' . e((string) $s['contato_nome']) . '</a>';
    }
    if ($s['empresa_id'] !== null) {
        $quem .= ($quem !== '' ? '<div class="text-muted-foreground text-xs">' : '<div>') . '<a class="underline-offset-4 hover:underline" href="' . e(url('/empresas/' . (int) $s['empresa_id'])) . '">' . e((string) $s['empresa_nome']) . '</a></div>';
    }
    if ($s['negocio_id'] !== null) {
        $quem .= '<div class="text-xs"><a class="underline-offset-4 hover:underline" href="' . e(url('/negocios/' . (int) $s['negocio_id'])) . '">' . e((string) $s['negocio_titulo']) . '</a></div>';
    }
    $utm = implode(' / ', array_filter([$s['utm_source'], $s['utm_medium'], $s['utm_campaign']], static fn ($x) => $x !== null && $x !== ''));

    $respostas = '';
    foreach ((array) json_decode((string) $s['dados'], true) as $r) {
        $respostas .= '<div><dt class="text-muted-foreground text-xs">' . e($r['rotulo'] ?? '') . '</dt><dd class="text-sm whitespace-pre-line">' . e($r['valor'] ?? '') . '</dd></div>';
    }
    $detalhes = '<details><summary class="cursor-pointer text-sm underline-offset-4 hover:underline">Respostas</summary>'
        . '<dl class="mt-2 grid gap-2">' . ($respostas !== '' ? $respostas : '<p class="text-muted-foreground text-sm">Sem respostas.</p>') . '</dl>'
        . '<p class="text-muted-foreground mt-2 text-xs">IP ' . e((string) $s['ip']) . ($s['pagina_origem'] ? ' · Página: ' . e((string) $s['pagina_origem']) : '')
        . ($s['referer'] ? ' · Referência: ' . e((string) $s['referer']) : '') . '</p></details>';

    $linhas[] = [
        'data' => datahora_br($s['criado_em']),
        'formulario' => $s['formulario_nome'],
        'quem' => ['html' => $quem !== '' ? $quem : '<span class="text-muted-foreground">—</span>'],
        'status' => ['html' => badge($rotuloStatus, $variante)],
        'origem' => $utm !== '' ? $utm : '—',
        'respostas' => ['html' => $detalhes],
    ];
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Submissões</h1>
        <p class="text-muted-foreground">Tudo o que chegou pelos formulários de captação, mais recentes primeiro.</p>
    </div>
    <?= botao('Formulários', ['href' => url('/formularios'), 'variante' => 'outline', 'icone' => 'clipboard-list']) ?>
</div>

<form method="get" action="<?= e(url('/formularios/submissoes')) ?>" class="flex flex-wrap items-end gap-2 pb-3">
    <div class="field"><label for="f-formulario">Formulário</label>
        <?= select('formulario', $formulariosOpcoes, $filtros['formulario_id'] ?: null, ['placeholder' => 'Todos', 'id' => 'f-formulario']) ?></div>
    <div class="field"><label for="f-status">Status</label>
        <?= select('status', ['processada' => 'Processada', 'duplicada' => 'Duplicada', 'spam' => 'Spam'], $filtros['status'] ?: null, ['placeholder' => 'Todos', 'id' => 'f-status']) ?></div>
    <?= botao('Filtrar', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'search']) ?>
</form>

<?= data_table(
    ['data' => ['rotulo' => 'Recebida em'], 'formulario' => ['rotulo' => 'Formulário'], 'quem' => ['rotulo' => 'Quem'], 'status' => ['rotulo' => 'Status'],
     'origem' => ['rotulo' => 'Origem (UTM)'], 'respostas' => ['rotulo' => 'Respostas']],
    $linhas,
    ['id' => 'tabela-submissoes', 'vazio_html' => vazio('Nenhuma submissão', 'Quando alguém preencher um formulário, o envio aparece aqui.', ['icone' => 'inbox'])],
) ?>
<?= paginacao($resultado['pagina'], $resultado['paginas'], $resultado['total'], $porPagina, $urlPagina) ?>
