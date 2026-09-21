<?php
/**
 * Início (SPEC §9): chat, tarefas de hoje/atrasadas, funil por etapa, negócios parados, contratos vencendo, ações pendentes e metas.
 * @var array $usuario
 * @var list<array> $atrasadas
 * @var list<array> $hoje
 * @var string $caminho
 * @var array|null $pipeline
 * @var list<array{id:int,nome:string,cor:?string,qtd:int,valor:int,ponderado:int}> $funil
 * @var array{total:int,linhas:list<array>} $parados
 * @var array{total:int,linhas:list<array>} $contratos
 * @var int $acoesPendentes
 * @var list<array> $metas metas vigentes com progresso (Metas::vigentes)
 */
use App\Controllers\PaginaController;

$primeiroNome = explode(' ', trim($usuario['nome']))[0];
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Olá, <?= e($primeiroNome) ?></h1>
        <p class="text-muted-foreground"><?= e(data_br(hoje())) ?> — seu CRM agentic-first.</p>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <?= card([
            'titulo'     => 'Chat',
            'descricao'  => 'Comandos com / e linguagem natural. Ctrl+K foca o campo.',
            'corpo_html' => chat_caixa(['modo' => 'pagina', 'caminho' => $caminho]),
        ]) ?>
    </div>
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
    // ---- Funil por etapa (valor estimado e ponderado dos negócios abertos)
    $linhasFunil = '';
    $totQtd = $totValor = $totPond = 0;
    foreach ($funil as $etapa) {
        $totQtd += $etapa['qtd'];
        $totValor += $etapa['valor'];
        $totPond += $etapa['ponderado'];
        $linhasFunil .= '<tr><td>' . pill_etapa($etapa['nome'], $etapa['cor']) . '</td>'
            . '<td class="text-right">' . ($etapa['qtd'] > 0
                ? '<a class="underline-offset-4 hover:underline" href="' . e(url('/negocios?' . http_build_query(['etapa_id' => $etapa['id'], 'status' => 'aberto']))) . '">' . $etapa['qtd'] . '</a>'
                : '0') . '</td>'
            . '<td class="text-right">' . e(moeda($etapa['valor'])) . '</td>'
            . '<td class="text-right">' . e(moeda($etapa['ponderado'])) . '</td></tr>';
    }
    $rodapeFunil = '<tfoot><tr class="font-medium"><td>Total</td><td class="text-right">' . $totQtd . '</td>'
        . '<td class="text-right">' . e(moeda($totValor)) . '</td><td class="text-right">' . e(moeda($totPond)) . '</td></tr></tfoot>';
    ?>
    <div class="lg:col-span-2">
        <?= card([
            'titulo'     => 'Funil' . ($pipeline !== null ? ' — ' . $pipeline['nome'] : ''),
            'descricao'  => 'Negócios abertos por etapa; ponderado = valor × probabilidade.',
            'acao_html'  => botao('Kanban', ['href' => url('/negocios/kanban'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'kanban']),
            'corpo_html' => $funil === []
                ? vazio('Sem etapas', 'Configure um pipeline com etapas abertas.', ['icone' => 'kanban'])
                : '<div class="table-container"><table class="table"><thead><tr><th>Etapa</th><th class="text-right">Negócios</th>'
                    . '<th class="text-right">Valor</th><th class="text-right">Ponderado</th></tr></thead><tbody>'
                    . $linhasFunil . '</tbody>' . $rodapeFunil . '</table></div>',
        ]) ?>
    </div>

    <div class="grid content-start gap-4">
        <?= metas_card($metas) ?>

        <?php
        $pendentes = $acoesPendentes > 0
            ? '<p class="text-3xl font-semibold">' . $acoesPendentes . '</p><p class="text-muted-foreground pb-3 text-sm">aguardando sua aprovação</p>'
                . botao('Revisar ações', ['href' => url('/acoes-pendentes'), 'icone' => 'check'])
            : vazio('Nada pendente', 'Nenhuma ação de agente aguardando aprovação.', ['icone' => 'check']);
        ?>
        <?= card(['titulo' => 'Ações pendentes', 'corpo_html' => $pendentes]) ?>

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
    </div>

    <?php
    $linhasParados = '';
    foreach ($parados['linhas'] as $n) {
        $diasParado = (int) floor((time() - strtotime((string) $n['entrou_etapa_em'])) / 86400);
        $linhasParados .= '<tr><td><a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/negocios/' . (int) $n['id'])) . '">' . e($n['titulo']) . '</a></td>'
            . '<td>' . e((string) $n['empresa_nome']) . '</td>'
            . '<td>' . pill_etapa((string) $n['etapa_nome'], $n['etapa_cor']) . '</td>'
            . '<td class="text-right whitespace-nowrap">' . $diasParado . ' dias</td>'
            . '<td class="text-right">' . ($n['valor_estimado'] !== null ? e(moeda((int) $n['valor_estimado'])) : '') . '</td></tr>';
    }
    ?>
    <div class="lg:col-span-3">
        <?= card([
            'titulo'     => 'Negócios parados',
            'descricao'  => 'Abertos há mais de ' . PaginaController::DIAS_NEGOCIO_PARADO . ' dias na mesma etapa'
                . ($parados['total'] > 0 ? ' — ' . $parados['total'] . ' no total.' : '.'),
            'corpo_html' => $parados['linhas'] === []
                ? vazio('Nenhum negócio parado', 'Tudo em movimento.', ['icone' => 'circle-check'])
                : '<div class="table-container"><table class="table"><thead><tr><th>Negócio</th><th>Empresa</th><th>Etapa</th>'
                    . '<th class="text-right">Parado há</th><th class="text-right">Valor</th></tr></thead><tbody>' . $linhasParados . '</tbody></table></div>'
                    . ($parados['total'] > count($parados['linhas'])
                        ? '<p class="pt-2 text-sm">' . link_para('/negocios?status=aberto', 'Ver negócios abertos', 'underline') . '</p>' : ''),
        ]) ?>
    </div>
</div>
