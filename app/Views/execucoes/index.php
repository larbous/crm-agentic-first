<?php
/**
 * Execuções de IA: histórico, tokens e totais do mês por modelo.
 * @var array $filtros
 * @var array $resultado
 * @var string $mes AAAA-MM
 * @var list<array> $totais por modelo
 * @var array<int,string> $agentes id => nome
 */
use App\Services\AI\CommandRouter;

$statusRotulos = ['fila' => 'Na fila', 'rodando' => 'Rodando', 'concluida' => 'Concluída', 'erro' => 'Erro', 'aguardando_aprovacao' => 'Aguardando aprovação', 'cancelada' => 'Cancelada'];
$variantes = ['concluida' => 'success', 'erro' => 'destructive', 'aguardando_aprovacao' => 'warning', 'rodando' => 'info'];
$tipos = ['agentes' => 'Agentes', 'squads' => 'Squads', 'roteador' => 'Roteador do chat', 'acoes_rapidas' => 'Ações rápidas'];
$acoesRapidas = ['melhorar' => 'melhorar texto', 'formal' => 'tom formal', 'amigavel' => 'tom amigável', 'resumir' => 'resumir histórico', 'resposta' => 'sugerir resposta'];
$rotaEntidade = ['empresas' => '/empresas/', 'contatos' => '/contatos/', 'negocios' => '/negocios/', 'propostas' => '/propostas/', 'contratos' => '/contratos/'];
$num = static fn (int $n): string => number_format($n, 0, ',', '.');

$somaEntrada = array_sum(array_column($totais, 'tokens_entrada'));
$somaSaida = array_sum(array_column($totais, 'tokens_saida'));
$totaisLinhas = [];
foreach ($totais as $t) {
    $totaisLinhas[] = [
        'modelo' => $t['modelo'], 'execucoes' => $num($t['execucoes']),
        'entrada' => $num($t['tokens_entrada']), 'saida' => $num($t['tokens_saida']), 'total' => $num($t['tokens_entrada'] + $t['tokens_saida']),
    ];
}

$linhas = [];
foreach ($resultado['linhas'] as $x) {
    $cabecalhoSquad = $x['squad_id'] !== null && $x['squad_execucao_id'] === null;
    $origem = $x['agente_id'] !== null
        ? '<a class="underline-offset-4 hover:underline" href="' . e(url('/agentes/' . (int) $x['agente_id'] . '/editar')) . '">' . e((string) ($x['agente_nome'] ?? 'agente #' . $x['agente_id'])) . '</a>'
            . ($x['squad_execucao_id'] !== null ? ' <span class="text-muted-foreground text-xs">· etapa ' . (int) $x['etapa_ordem'] . ' do <a class="underline underline-offset-4" href="' . e(url('/squads/execucoes/' . (int) $x['squad_execucao_id'])) . '">squad #' . (int) $x['squad_execucao_id'] . '</a></span>' : '')
        : ($x['squad_id'] !== null
            ? '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/squads/execucoes/' . (int) $x['id'])) . '">Squad ' . e((string) ($x['squad_nome'] ?? '#' . $x['squad_id'])) . '</a>'
            : ($x['acao_rapida'] !== null ? 'Ação rápida <span class="text-muted-foreground text-xs">· ' . e($acoesRapidas[$x['acao_rapida']] ?? $x['acao_rapida']) . '</span>' : 'Roteador do chat'));
    $alvo = '';
    if ($x['entidade'] !== null && $x['registro_id'] !== null && isset($rotaEntidade[$x['entidade']])) {
        $alvo = '<a class="underline-offset-4 hover:underline" href="' . e(url($rotaEntidade[$x['entidade']] . (int) $x['registro_id'])) . '">' . e($x['entidade']) . ' #' . (int) $x['registro_id'] . '</a>';
    }

    // Detalhe: saída do agente (resumo/texto) ou texto bruto; entrada e erro.
    $saida = $cabecalhoSquad ? '' : (string) $x['saida'];
    $json = $saida !== '' ? CommandRouter::extrairJson($saida) : null;
    $detalhe = '';
    if ($x['erro']) {
        $detalhe .= '<div class="mb-2"><div class="text-muted-foreground text-xs font-medium uppercase">Erro</div><p class="text-destructive text-sm">' . e($x['erro']) . '</p></div>';
    }
    if (is_array($json) && (isset($json['resumo']) || isset($json['texto']))) {
        if (!empty($json['resumo'])) {
            $detalhe .= '<div class="mb-2"><div class="text-muted-foreground text-xs font-medium uppercase">Resumo</div><p class="text-sm">' . e((string) $json['resumo']) . '</p></div>';
        }
        if (!empty($json['texto'])) {
            $detalhe .= '<div class="mb-2"><div class="text-muted-foreground text-xs font-medium uppercase">Texto</div><div class="text-sm whitespace-pre-line">' . e((string) $json['texto']) . '</div></div>';
        }
    }
    if ($cabecalhoSquad) {
        $detalhe .= '<p class="mb-2 text-sm"><a class="underline underline-offset-4" href="' . e(url('/squads/execucoes/' . (int) $x['id'])) . '">Ver andamento das etapas</a></p>';
    } else {
        $detalhe .= '<div class="mb-2"><div class="text-muted-foreground text-xs font-medium uppercase">Entrada enviada</div><pre class="max-h-64 overflow-auto rounded-md border p-2 text-xs whitespace-pre-wrap">' . e((string) $x['entrada']) . '</pre></div>';
    }
    if ($saida !== '') {
        $detalhe .= '<div class="mb-2"><div class="text-muted-foreground text-xs font-medium uppercase">Saída bruta</div><pre class="max-h-64 overflow-auto rounded-md border p-2 text-xs whitespace-pre-wrap">' . e($saida) . '</pre></div>';
    }
    $links = '<a class="text-sm underline underline-offset-4" href="' . e(url('/auditoria?execucao_id=' . (int) $x['id'])) . '">Ver alterações na Auditoria</a>';
    if ((int) $x['pendentes'] > 0) {
        $links .= ' · <a class="text-sm underline underline-offset-4" href="' . e(url('/acoes-pendentes')) . '">' . (int) $x['pendentes'] . ' ação(ões) pendente(s)</a>';
    }

    $linhas[] = [
        'id'      => '#' . (int) $x['id'],
        'quando'  => datahora_br((string) $x['iniciado_em']),
        'origem'  => ['html' => $origem . ((int) $x['simulacao'] === 1 ? ' ' . badge('simulação', 'outline') : '')],
        'alvo'    => ['html' => $alvo],
        'modelo'  => (string) $x['modelo'],
        'tokens'  => $x['tokens_entrada'] !== null ? $num((int) $x['tokens_entrada']) . ' / ' . $num((int) $x['tokens_saida']) : '—',
        'duracao' => $x['duracao_ms'] !== null ? number_format((int) $x['duracao_ms'] / 1000, 1, ',', '.') . ' s' : '—',
        'status'  => ['html' => badge($statusRotulos[$x['status']] ?? $x['status'], $variantes[$x['status']] ?? 'secondary')],
        'detalhe' => ['html' => '<details><summary class="cursor-pointer text-sm underline-offset-4 hover:underline">Detalhes</summary><div class="mt-2 max-w-3xl">' . $detalhe . '<div>' . $links . '</div></div></details>'],
    ];
}

$url = static fn (array $extra): string => url('/execucoes?' . http_build_query(array_filter($extra + $filtros + ['mes' => $mes], static fn ($v) => $v !== '' && $v !== null)));
$temFiltro = array_filter($filtros, static fn ($v) => $v !== '') !== [];
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Execuções</h1>
        <p class="text-muted-foreground"><?= (int) $resultado['total'] ?> execução(ões). Toda chamada à IA (chat, agentes, squads e testes) fica registrada aqui com modelo, tokens e duração.</p>
    </div>
</div>

<div class="mb-4 grid gap-4 lg:grid-cols-3">
    <div class="lg:col-span-1">
        <?= card([
            'titulo' => 'Tokens de ' . e(substr($mes, 5, 2) . '/' . substr($mes, 0, 4)),
            'tamanho' => 'sm',
            'corpo_html' => '<div class="text-2xl font-semibold">' . $num($somaEntrada + $somaSaida) . '</div><p class="text-muted-foreground text-sm">' . $num($somaEntrada) . ' de entrada · ' . $num($somaSaida) . ' de saída</p>'
                . '<form method="get" action="' . e(url('/execucoes')) . '" class="mt-3 flex items-center gap-2">'
                . '<label class="sr-only" for="mes">Mês</label><input id="mes" type="month" name="mes" class="input" value="' . e($mes) . '">'
                . botao('Ver', ['tipo' => 'submit', 'variante' => 'outline', 'tamanho' => 'sm']) . '</form>',
        ]) ?>
    </div>
    <div class="lg:col-span-2">
        <?= $totaisLinhas !== []
            ? tabela(['modelo' => 'Modelo', 'execucoes' => 'Execuções', 'entrada' => 'Tokens de entrada', 'saida' => 'Tokens de saída', 'total' => 'Total'], $totaisLinhas)
            : vazio('Sem uso neste mês', 'Nenhuma chamada de IA registrada no período.', ['icone' => 'activity']) ?>
    </div>
</div>

<form method="get" action="<?= e(url('/execucoes')) ?>" class="filter-bar">
    <input type="hidden" name="mes" value="<?= e($mes) ?>">
    <?= select('tipo', $tipos, $filtros['tipo'] ?: null, ['id' => 'f-tipo', 'placeholder' => 'Origem: todas', 'attrs' => ['class' => 'select w-auto', 'aria-label' => 'Origem', 'onchange' => 'this.form.requestSubmit()']]) ?>
    <?= select('agente_id', $agentes, $filtros['agente_id'] ?: null, ['id' => 'f-agente', 'placeholder' => 'Agente: todos', 'attrs' => ['class' => 'select w-auto', 'aria-label' => 'Agente', 'onchange' => 'this.form.requestSubmit()']]) ?>
    <?= select('status', $statusRotulos, $filtros['status'] ?: null, ['id' => 'f-status', 'placeholder' => 'Status: todos', 'attrs' => ['class' => 'select w-auto', 'aria-label' => 'Status', 'onchange' => 'this.form.requestSubmit()']]) ?>
    <?php if ($temFiltro): ?><?= botao('Limpar', ['href' => url('/execucoes?mes=' . $mes), 'variante' => 'ghost', 'tamanho' => 'sm', 'icone' => 'x']) ?><?php endif; ?>
</form>

<?= data_table(
    ['id' => ['rotulo' => '#'], 'quando' => ['rotulo' => 'Início'], 'origem' => ['rotulo' => 'Origem'], 'alvo' => ['rotulo' => 'Registro'], 'modelo' => ['rotulo' => 'Modelo'],
     'tokens' => ['rotulo' => 'Tokens (entrada / saída)'], 'duracao' => ['rotulo' => 'Duração'], 'status' => ['rotulo' => 'Status'], 'detalhe' => ['rotulo' => '']],
    $linhas,
    ['id' => 'tabela-execucoes', 'vazio_html' => vazio('Nenhuma execução', $temFiltro ? 'Nenhuma execução com esses filtros.' : 'As chamadas de IA aparecerão aqui.', ['icone' => 'activity'])],
) ?>

<?= paginacao($resultado['pagina'], $resultado['paginas'], $resultado['total'], $resultado['por_pagina'], static fn (int $p): string => $url(['pagina' => $p])) ?>
