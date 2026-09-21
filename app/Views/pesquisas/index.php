<?php
/**
 * Pesquisas NPS: índice, distribuição, tendência, fila de envio e respostas.
 * @var array<int,string> $formularios pesquisas definidas (id => nome)
 * @var int $formularioId filtro (0 = todas)
 * @var int $periodo dias (0 = todo o período)
 * @var array<int,string> $periodos
 * @var array $resumo Nps::resumir
 * @var array<int,int> $distribuicao nota => quantidade
 * @var array<string,array> $tendencia 'AAAA-MM' => Nps::resumir
 * @var int $criadas pesquisas criadas no período (sem as canceladas)
 * @var int $respondidas
 * @var list<array> $aguardando pendentes
 * @var array $respostas resultado paginado de PesquisaRepository::listar
 * @var callable $urlPagina
 * @var int $porPagina
 * @var array<int,string> $ativas
 */
use App\Services\Nps;

$total = $resumo['total'];
$taxa = $criadas > 0 ? (int) round($respondidas * 100 / $criadas) : null;
$meses = ['01' => 'jan', '02' => 'fev', '03' => 'mar', '04' => 'abr', '05' => 'mai', '06' => 'jun', '07' => 'jul', '08' => 'ago', '09' => 'set', '10' => 'out', '11' => 'nov', '12' => 'dez'];

// ---- Filtros
$filtro = '<form method="get" action="' . e(url('/pesquisas')) . '" class="flex flex-wrap items-center gap-2">'
    . '<label class="sr-only" for="f-form">Pesquisa</label>'
    . select('formulario', [0 => 'Todas as pesquisas'] + $formularios, $formularioId, ['id' => 'f-form', 'attrs' => ['class' => 'select w-auto', 'onchange' => 'this.form.requestSubmit()']])
    . '<label class="sr-only" for="f-periodo">Período</label>'
    . select('periodo', $periodos, $periodo, ['id' => 'f-periodo', 'attrs' => ['class' => 'select w-auto', 'onchange' => 'this.form.requestSubmit()']])
    . '</form>';

// ---- Indicadores
$kpi = static fn (string $titulo, string $valor, string $legenda = ''): string => card([
    'tamanho' => 'sm', 'titulo' => $titulo,
    'corpo_html' => '<div class="text-3xl font-semibold">' . $valor . '</div>' . ($legenda !== '' ? '<p class="text-muted-foreground mt-1 text-sm">' . $legenda . '</p>' : ''),
]);
$indicadores = '<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">'
    . $kpi('NPS', $resumo['nps'] !== null ? (string) $resumo['nps'] : '—', $resumo['nps'] !== null ? e(Nps::zona($resumo['nps'])) : 'Sem respostas no período')
    . $kpi('Respostas', (string) $total, $total > 0 ? "{$resumo['promotores']} promotor(es) · {$resumo['neutros']} neutro(s) · {$resumo['detratores']} detrator(es)" : '')
    . $kpi('Taxa de resposta', $taxa !== null ? $taxa . '%' : '—', $criadas > 0 ? "{$respondidas} de {$criadas} pesquisas criadas no período" : 'Nenhuma pesquisa criada no período')
    . $kpi('A enviar', (string) count(array_filter($aguardando, static fn (array $p): bool => $p['enviada_em'] === null)), 'links criados que você ainda não marcou como enviados')
    . '</div>';

// ---- Distribuição: barra empilhada + notas de 0 a 10
$barra = '';
if ($total > 0) {
    $barra = '<div class="flex h-4 w-full overflow-hidden rounded-full" role="img" aria-label="Promotores ' . $resumo['pct_promotores'] . '%, neutros ' . $resumo['pct_neutros'] . '%, detratores ' . $resumo['pct_detratores'] . '%">'
        . '<div class="bg-destructive" style="width:' . $resumo['pct_detratores'] . '%"></div>'
        . '<div class="bg-warning" style="width:' . $resumo['pct_neutros'] . '%"></div>'
        . '<div class="bg-success" style="width:' . $resumo['pct_promotores'] . '%"></div></div>'
        . '<div class="text-muted-foreground mt-2 flex flex-wrap gap-4 text-sm"><span>Detratores (0–6): ' . $resumo['pct_detratores'] . '%</span><span>Neutros (7–8): ' . $resumo['pct_neutros'] . '%</span><span>Promotores (9–10): ' . $resumo['pct_promotores'] . '%</span></div>';
}
$maior = max(1, max($distribuicao ?: [0]));
$colunas = '';
for ($n = 0; $n <= 10; $n++) {
    $q = (int) ($distribuicao[$n] ?? 0);
    $cor = $n >= 9 ? 'bg-success' : ($n >= 7 ? 'bg-warning' : 'bg-destructive');
    $colunas .= '<div class="flex flex-1 flex-col items-center gap-1"><span class="text-muted-foreground text-xs">' . $q . '</span>'
        . '<div class="flex h-24 w-full items-end"><div class="' . $cor . ' w-full rounded-t" style="height:' . ($q > 0 ? max(4, (int) round($q * 100 / $maior)) : 0) . '%"></div></div>'
        . '<span class="text-xs font-medium">' . $n . '</span></div>';
}
$distribuicaoHtml = $total > 0
    ? $barra . '<div class="mt-4 flex gap-1" aria-label="Quantidade de respostas por nota">' . $colunas . '</div>'
    : vazio('Sem respostas ainda', 'Quando os clientes responderem, o índice e a distribuição aparecem aqui.', ['icone' => 'star']);

// ---- Tendência mensal
$linhasTendencia = [];
foreach (array_reverse($tendencia, true) as $mes => $t) {
    [$ano, $m] = explode('-', $mes);
    $linhasTendencia[] = ['mes' => ($meses[$m] ?? $m) . '/' . $ano, 'respostas' => (string) $t['total'], 'nps' => (string) $t['nps'],
        'mix' => $t['pct_promotores'] . '% promotores · ' . $t['pct_detratores'] . '% detratores'];
}

// ---- Aguardando resposta
$linhasAguardando = [];
foreach ($aguardando as $p) {
    $linhasAguardando[] = [
        'empresa' => ['html' => link_para('/pesquisas/' . (int) $p['id'], (string) $p['empresa_nome'], 'font-medium underline-offset-4 hover:underline')
            . '<div class="text-muted-foreground text-xs">' . e((string) $p['formulario_nome']) . '</div>'],
        'contato' => (string) ($p['contato_nome'] ?? ''),
        'envio' => ['html' => $p['enviada_em'] !== null ? badge('Enviada ' . data_br(substr((string) $p['enviada_em'], 0, 10)), 'secondary') : badge('A enviar', 'warning')],
        'expira' => data_br(substr((string) $p['expira_em'], 0, 10)),
        'acoes' => ['html' => '<div class="flex justify-end gap-1">'
            . botao('Copiar link', ['variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'copy', 'attrs' => ['data-copiar' => pesquisa_link($p), 'type' => 'button']])
            . botao('Abrir', ['href' => url('/pesquisas/' . (int) $p['id']), 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'send']) . '</div>'],
    ];
}

// ---- Respostas
$linhasRespostas = [];
foreach ($respostas['linhas'] as $p) {
    $extras = '';
    foreach ($p['respostas'] as $r) {
        $extras .= '<div class="text-muted-foreground text-xs">' . e((string) $r['rotulo']) . ': ' . e((string) $r['valor']) . '</div>';
    }
    $linhasRespostas[] = [
        'quando' => datahora_br((string) $p['respondida_em']),
        'empresa' => ['html' => link_para('/empresas/' . (int) $p['empresa_id'], (string) $p['empresa_nome'], 'font-medium underline-offset-4 hover:underline')
            . ($p['contato_nome'] ? '<div class="text-muted-foreground text-xs">' . e((string) $p['contato_nome']) . '</div>' : '')],
        'nota' => ['html' => badge_nps((int) $p['nota'], (string) $p['categoria'])],
        'comentario' => ['html' => ($p['comentario'] ? '<div class="text-sm whitespace-pre-line">' . e((string) $p['comentario']) . '</div>' : '<span class="text-muted-foreground">—</span>') . $extras],
    ];
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Pesquisas NPS</h1>
        <p class="text-muted-foreground">Satisfação dos clientes: cada pesquisa tem um link individual. O NPS é a % de promotores (9–10) menos a % de detratores (0–6).</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= $filtro ?>
        <?= botao('Modelos de pesquisa', ['href' => url('/formularios'), 'variante' => 'outline', 'icone' => 'clipboard-list']) ?>
    </div>
</div>

<div class="grid gap-4">
    <?= $indicadores ?>
    <?= card(['titulo' => 'Distribuição das notas', 'corpo_html' => $distribuicaoHtml]) ?>
    <?php if ($linhasTendencia !== []): ?>
        <?= card(['titulo' => 'Tendência mensal', 'descricao' => 'Todas as respostas de cada mês (não muda com o período acima).', 'corpo_html' => tabela(['mes' => 'Mês', 'respostas' => 'Respostas', 'nps' => 'NPS', 'mix' => 'Composição'], $linhasTendencia)]) ?>
    <?php endif; ?>
    <?= card(['titulo' => 'Aguardando resposta', 'descricao' => 'Links pendentes. O CRM ainda não envia mensagens sozinho: abra o envio para copiar o link ou usar o WhatsApp/e-mail, e marque como enviado.',
        'corpo_html' => $linhasAguardando !== []
            ? tabela(['empresa' => 'Empresa', 'contato' => 'Contato', 'envio' => 'Envio', 'expira' => 'Vale até', 'acoes' => ''], $linhasAguardando)
            : vazio('Nada aguardando', $ativas === [] ? 'Crie uma pesquisa em Formulários e envie pela tela de uma empresa.' : 'Crie um envio na aba Pesquisas de uma empresa ou deixe o gatilho automático da pesquisa fazer isso.', ['icone' => 'send'])]) ?>
    <?= card(['titulo' => 'Respostas', 'corpo_html' => $linhasRespostas !== []
        ? tabela(['quando' => 'Quando', 'empresa' => 'Empresa', 'nota' => 'Nota', 'comentario' => 'Comentário e respostas'], $linhasRespostas)
            . ($respostas['paginas'] > 1 ? '<div class="mt-3 flex items-center justify-between text-sm"><span class="text-muted-foreground">Página ' . (int) $respostas['pagina'] . ' de ' . (int) $respostas['paginas'] . '</span><div class="flex gap-2">'
                . ($respostas['pagina'] > 1 ? botao('Anterior', ['href' => $urlPagina($respostas['pagina'] - 1), 'variante' => 'outline', 'tamanho' => 'sm']) : '')
                . ($respostas['pagina'] < $respostas['paginas'] ? botao('Próxima', ['href' => $urlPagina($respostas['pagina'] + 1), 'variante' => 'outline', 'tamanho' => 'sm']) : '') . '</div></div>' : '')
        : vazio('Sem respostas no período', '', ['icone' => 'message-square'])]) ?>
</div>
