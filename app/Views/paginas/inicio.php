<?php
/**
 * Início (SPEC §9): indicadores em gráficos por módulo, tarefas de hoje/atrasadas, negócios parados, contratos vencendo e metas.
 * O chat fica no painel lateral, já aberto nesta tela.
 * @var array $usuario
 * @var list<array> $atrasadas
 * @var list<array> $hoje
 * @var array|null $pipeline
 * @var list<array{id:int,nome:string,cor:?string,qtd:int,valor:int,ponderado:int}> $funil
 * @var array{total:int,linhas:list<array>} $parados
 * @var array{total:int,linhas:list<array>} $contratos
 * @var int $acoesPendentes
 * @var list<array> $metas metas vigentes com progresso (Metas::vigentes)
 * @var array $indicadores séries e totais dos gráficos (PaginaController::indicadores)
 */
use App\Controllers\PaginaController;

$primeiroNome = explode(' ', trim($usuario['nome']))[0];

$ind = $indicadores;
$cob = $ind['cobrancas'];
$rotMeses = array_map('rotulo_periodo', $ind['meses']);
$rotDias = array_map('rotulo_periodo', $ind['dias']);
$mesAtual = $ind['meses'][count($ind['meses']) - 1];
$fechados = $ind['conversao']['ganhos'] + $ind['conversao']['perdidos'];
$taxa = $fechados > 0 ? (int) round(100 * $ind['conversao']['ganhos'] / $fechados) : null;
$totalNps = array_sum($ind['nps']);
$npsNota = $totalNps > 0 ? (int) round(100 * ($ind['nps']['promotor'] - $ind['nps']['detrator']) / $totalNps) : null;
$saldoMes = $ind['fluxo'][$mesAtual]['recebido'] - $ind['fluxo'][$mesAtual]['pago'];
$atrasos = $ind['tarefas']['atrasadas'] + $ind['chamados']['atrasadas'];
$paraHoje = $ind['tarefas']['hoje'] + $ind['chamados']['hoje'];
$proximos = $ind['tarefas']['proximas'] + $ind['chamados']['proximas'];
$nErros = array_sum(array_column($ind['execucoes'], 'erro'));
$abertos = array_sum(array_column($funil, 'qtd'));
$ponderado = array_sum(array_column($funil, 'ponderado'));
$ativos = ($ind['contratos']['por_status']['ativo'] ?? 0) + ($ind['contratos']['por_status']['assinado'] ?? 0);
$qtd = static fn (int|float $v): string => (string) (int) $v;
$curto = static fn (int|float $v): string => moeda_curta($v);
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Olá, <?= e($primeiroNome) ?></h1>
        <p class="text-muted-foreground"><?= e(data_br(hoje())) ?> — o que pede atenção hoje.</p>
    </div>
</div>

<?php /* Faixa de indicadores: um por módulo, o que pede atenção agora */ ?>
<div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
    <?= kpi('Atrasos', (string) $atrasos, ['icone' => 'list-checks', 'href' => '/tarefas', 'tom' => $atrasos > 0 ? 'destructive' : 'success',
        'detalhe' => $paraHoje . ' para hoje']) ?>
    <?= kpi('Mensagens novas', (string) $ind['naoLidas'], ['icone' => 'inbox', 'href' => '/caixa', 'tom' => $ind['naoLidas'] > 0 ? 'warning' : '',
        'detalhe' => 'conversas não lidas']) ?>
    <?= kpi('Ações pendentes', (string) $acoesPendentes, ['icone' => 'bot', 'href' => '/acoes-pendentes', 'tom' => $acoesPendentes > 0 ? 'warning' : '',
        'detalhe' => 'aguardando aprovação']) ?>
    <?= kpi('Cobranças vencidas', moeda($cob['vencidas']), ['icone' => 'receipt', 'href' => '/financeiro/cobrancas', 'tom' => $cob['vencidas'] > 0 ? 'destructive' : 'success',
        'detalhe' => moeda($cob['semana']) . ' vencem em 7 dias']) ?>
    <?= kpi('Pipeline ponderado', moeda($ponderado), ['icone' => 'handshake', 'href' => '/negocios/kanban',
        'detalhe' => $abertos . ' negócio(s) aberto(s)']) ?>
    <?= kpi('Receita recorrente', moeda($ind['contratos']['mrr']), ['icone' => 'file-pen', 'href' => '/contratos',
        'detalhe' => $ativos . ' contrato(s) ativo(s)']) ?>
</div>

<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <?php
    // ---- Comercial: funil por etapa
    $itensFunil = [];
    foreach ($funil as $etapa) {
        $itensFunil[] = [
            'rotulo' => $etapa['nome'], 'valor' => $etapa['qtd'], 'cor' => 'chart-1',
            'texto' => $etapa['qtd'] . ' · ' . moeda_curta($etapa['ponderado']) . ' pond.',
            'href' => '/negocios?' . http_build_query(['etapa_id' => $etapa['id'], 'status' => 'aberto']),
        ];
    }
    ?>
    <?= card([
        'titulo'     => 'Funil' . ($pipeline !== null ? ' — ' . $pipeline['nome'] : ''),
        'descricao'  => 'Negócios abertos por etapa; ponderado = valor × probabilidade.',
        'acao_html'  => botao('Kanban', ['href' => url('/negocios/kanban'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'kanban']),
        'corpo_html' => $itensFunil === []
            ? vazio('Sem etapas', 'Configure um pipeline com etapas abertas.', ['icone' => 'kanban'])
            : grafico_barras($itensFunil),
    ]) ?>

    <?= card([
        'titulo'     => 'Negócios ganhos por mês',
        'descricao'  => ($taxa !== null ? "Conversão de {$taxa}% em 90 dias · " : '') . $ind['novos'] . ' novo(s) negócio(s) em 30 dias.',
        'corpo_html' => grafico_colunas($rotMeses, [
            ['nome' => 'Valor ganho', 'valores' => array_column($ind['ganhos'], 'valor'), 'cor' => 'chart-2'],
        ], $curto, 'Valor de negócios ganhos por mês'),
    ]) ?>

    <?= card([
        'titulo'     => 'Recebido × pago',
        'descricao'  => ($saldoMes >= 0 ? 'Saldo do mês: ' : 'Saldo negativo no mês: ') . moeda($saldoMes) . '.',
        'acao_html'  => botao('DRE', ['href' => url('/financeiro/dre'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'chart-bar']),
        'corpo_html' => grafico_colunas($rotMeses, [
            ['nome' => 'Recebido (cobranças)', 'valores' => array_column($ind['fluxo'], 'recebido'), 'cor' => 'success'],
            ['nome' => 'Pago (despesas)', 'valores' => array_column($ind['fluxo'], 'pago'), 'cor' => 'destructive'],
        ], $curto, 'Cobranças recebidas e despesas pagas por mês'),
    ]) ?>

    <?= card([
        'titulo'     => 'Cobranças em aberto',
        'descricao'  => 'Quanto ainda vai entrar e quanto está atrasado.',
        'acao_html'  => botao('Cobranças', ['href' => url('/financeiro/cobrancas'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'receipt']),
        'corpo_html' => grafico_rosca([
            ['Vencidas', $cob['vencidas'], 'destructive'],
            ['Vencem em 7 dias', $cob['semana'], 'warning'],
            ['A vencer depois', $cob['depois'], 'chart-1'],
        ], $curto($cob['vencidas'] + $cob['semana'] + $cob['depois']), 'em aberto', static fn (int|float $v): string => moeda($v), 'Cobranças em aberto por situação'),
    ]) ?>

    <?php
    $rotProposta = ['rascunho' => 'Rascunho', 'enviada' => 'Enviada', 'visualizada' => 'Visualizada', 'aceita' => 'Aceita', 'recusada' => 'Recusada', 'expirada' => 'Expirada'];
    $corProposta = ['rascunho' => 'chart-4', 'enviada' => 'chart-1', 'visualizada' => 'chart-2', 'aceita' => 'success', 'recusada' => 'destructive', 'expirada' => 'warning'];
    $itensProp = [];
    foreach ($rotProposta as $st => $rot) {
        $n = $ind['propostas'][$st] ?? 0;
        $itensProp[] = ['rotulo' => $rot, 'valor' => $n, 'cor' => $corProposta[$st], 'texto' => (string) $n, 'href' => '/propostas?status=' . $st];
    }
    ?>
    <?= card([
        'titulo'     => 'Propostas',
        'descricao'  => $ind['emAberto']['qtd'] > 0
            ? $ind['emAberto']['qtd'] . ' aguardando resposta, somando ' . moeda($ind['emAberto']['valor']) . '.'
            : 'Nenhuma proposta aguardando resposta.',
        'acao_html'  => botao('Propostas', ['href' => url('/propostas'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'file-text']),
        'corpo_html' => grafico_barras($itensProp),
    ]) ?>

    <?= card([
        'titulo'     => 'Satisfação (NPS)',
        'descricao'  => $npsNota !== null ? "NPS {$npsNota} · {$totalNps} resposta(s) em 90 dias." : 'Respostas dos últimos 90 dias.',
        'acao_html'  => botao('Pesquisas', ['href' => url('/pesquisas'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'star']),
        'corpo_html' => grafico_rosca([
            ['Promotores', $ind['nps']['promotor'], 'success'],
            ['Neutros', $ind['nps']['neutro'], 'warning'],
            ['Detratores', $ind['nps']['detrator'], 'destructive'],
        ], $npsNota !== null ? (string) $npsNota : '–', 'NPS', $qtd, 'Respostas de NPS por categoria'),
    ]) ?>

    <?= card([
        'titulo'     => 'Agentes de IA (7 dias)',
        'descricao'  => $nErros > 0 ? $nErros . ' execução(ões) com erro — vale conferir.' : 'Execuções por dia, sem erros.',
        'acao_html'  => botao('Execuções', ['href' => url('/execucoes'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'activity']),
        'corpo_html' => grafico_colunas($rotDias, [
            ['nome' => 'Concluídas', 'valores' => array_column($ind['execucoes'], 'ok'), 'cor' => 'chart-1'],
            ['nome' => 'Com erro', 'valores' => array_column($ind['execucoes'], 'erro'), 'cor' => 'destructive'],
        ], $qtd, 'Execuções de agentes por dia'),
    ]) ?>

    <?= card([
        'titulo'     => 'Tarefas e chamados',
        'descricao'  => 'Em aberto, por prazo.',
        'acao_html'  => botao('Tarefas', ['href' => url('/tarefas'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'list-checks']),
        'corpo_html' => grafico_barras([
            ['rotulo' => 'Atrasados', 'valor' => $atrasos, 'cor' => 'destructive', 'texto' => (string) $atrasos, 'href' => '/tarefas'],
            ['rotulo' => 'Para hoje', 'valor' => $paraHoje, 'cor' => 'warning', 'texto' => (string) $paraHoje, 'href' => '/tarefas'],
            ['rotulo' => 'Próximos 7 dias', 'valor' => $proximos, 'cor' => 'chart-1', 'texto' => (string) $proximos, 'href' => '/tarefas'],
        ]),
    ]) ?>

    <?= metas_card($metas) ?>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <?php
    $todas = array_merge($atrasadas, $hoje);
    $lista = '<ul>';
    foreach (array_slice($todas, 0, 8) as $t) {
        $lista .= $t['tipo_item'] === 'chamado' ? chamado_linha($t, '/') : tarefa_linha($t, '/');
    }
    $lista .= '</ul>';
    ?>
    <?= card([
        'titulo'     => 'Tarefas e chamados de hoje',
        'descricao'  => count($atrasadas) . ' atrasada(s) · ' . count($hoje) . ' para hoje',
        'corpo_html' => $todas === []
            ? vazio('Nada para hoje', 'Sem tarefas ou chamados com prazo hoje.', ['icone' => 'list-checks'])
            : $lista . (count($todas) > 8 ? '<p class="pt-2 text-sm">' . link_para('/tarefas', 'Ver tudo em Tarefas e chamados', 'underline') . '</p>' : ''),
    ]) ?>

    <?php
    $itensContrato = '<ul>';
    foreach ($contratos['linhas'] as $c) {
        $dias = (int) floor((strtotime((string) $c['data_fim']) - strtotime(hoje())) / 86400);
        $itensContrato .= '<li class="flex items-start justify-between gap-3 border-b py-2 last:border-b-0"><div class="min-w-0">'
            . '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/contratos/' . (int) $c['id'])) . '">' . e($c['titulo']) . '</a>'
            . '<div class="text-muted-foreground truncate text-xs">' . e((string) $c['empresa_nome']) . '</div></div>'
            . '<div class="text-right text-xs whitespace-nowrap"><div>' . e(data_br($c['data_fim'])) . '</div>'
            . '<div class="text-muted-foreground">' . ($dias === 0 ? 'hoje' : ($dias === 1 ? 'amanhã' : "em {$dias} dias")) . '</div></div></li>';
    }
    $itensContrato .= '</ul>';
    ?>
    <?= card([
        'titulo'     => 'Contratos vencendo',
        'descricao'  => 'Terminam nos próximos ' . PaginaController::DIAS_CONTRATO_VENCENDO . ' dias.',
        'corpo_html' => $contratos['linhas'] === []
            ? vazio('Nenhum contrato vencendo', '', ['icone' => 'file-text'])
            : $itensContrato . ($contratos['total'] > count($contratos['linhas'])
                ? '<p class="pt-2 text-sm">' . link_para('/contratos?vencimento=vencendo', 'Ver todos (' . $contratos['total'] . ')', 'underline') . '</p>' : ''),
    ]) ?>

    <?php
    $linhasParados = '';
    foreach ($parados['linhas'] as $n) {
        $diasParado = (int) floor((time() - strtotime((string) $n['entrou_etapa_em'])) / 86400);
        $linhasParados .= '<li class="flex items-start justify-between gap-3 border-b py-2 last:border-b-0"><div class="min-w-0">'
            . '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/negocios/' . (int) $n['id'])) . '">' . e($n['titulo']) . '</a>'
            . '<div class="text-muted-foreground truncate text-xs">' . e((string) $n['empresa_nome']) . ' · ' . e((string) $n['etapa_nome']) . '</div></div>'
            . '<div class="text-right text-xs whitespace-nowrap"><div>' . $diasParado . ' dias</div>'
            . '<div class="text-muted-foreground">' . ($n['valor_estimado'] !== null ? e(moeda((int) $n['valor_estimado'])) : '') . '</div></div></li>';
    }
    ?>
    <?= card([
        'titulo'     => 'Negócios parados',
        'descricao'  => 'Abertos há mais de ' . PaginaController::DIAS_NEGOCIO_PARADO . ' dias na mesma etapa'
            . ($parados['total'] > 0 ? ' — ' . $parados['total'] . ' no total.' : '.'),
        'corpo_html' => $parados['linhas'] === []
            ? vazio('Nenhum negócio parado', 'Tudo em movimento.', ['icone' => 'circle-check'])
            : '<ul>' . $linhasParados . '</ul>'
                . ($parados['total'] > count($parados['linhas'])
                    ? '<p class="pt-2 text-sm">' . link_para('/negocios?status=aberto', 'Ver negócios abertos', 'underline') . '</p>' : ''),
    ]) ?>
</div>
