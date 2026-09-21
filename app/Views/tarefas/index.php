<?php
/**
 * Tela de tarefas e chamados: Hoje / Atrasadas / Próximos 7 dias / Todas, com filtro de tipo e de área.
 * @var string $visao
 * @var list<array{tipo:string,aberto:bool,r:array}> $itens
 * @var array $contagens
 * @var string $tipo todos | tarefas | chamados
 * @var int $area filtro de área (0 = todas)
 * @var array<int,string> $areas
 * @var array<string,mixed> $filtros parâmetros de filtro a manter nos links
 * @var string $voltar
 */
$abas = ['hoje' => 'Hoje', 'atrasadas' => 'Atrasadas', 'proximas' => 'Próximos 7 dias', 'todas' => 'Todas'];
$vazios = [
    'hoje'      => ['Nada para hoje', 'Sem tarefas ou chamados com prazo hoje.'],
    'atrasadas' => ['Nada atrasado', 'Você está em dia.'],
    'proximas'  => ['Nada nos próximos 7 dias', 'Sem tarefas ou chamados com prazo na semana.'],
    'todas'     => ['Nada por aqui ainda', 'Crie a primeira tarefa ou o primeiro chamado.'],
];
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Tarefas e chamados</h1>
        <p class="text-muted-foreground"><?= (int) $contagens['atrasadas'] ?> atrasado(s) · <?= (int) $contagens['hoje'] ?> para hoje. Chamados são as demandas de execução por área; tarefas são o que você precisa fazer.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Lista de chamados', ['href' => url('/chamados'), 'variante' => 'ghost', 'icone' => 'list-checks']) ?>
        <?= botao('Novo chamado', ['href' => url('/chamados/nova?voltar=' . rawurlencode($voltar)), 'variante' => 'outline', 'icone' => 'plus']) ?>
        <?= botao('Nova tarefa', ['href' => url('/tarefas/nova'), 'icone' => 'plus']) ?>
    </div>
</div>

<div class="flex flex-wrap items-center justify-between gap-2">
    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Período">
        <?php foreach ($abas as $chave => $rotulo): ?>
            <?= botao($rotulo . ' (' . (int) $contagens[$chave] . ')', [
                'href' => url('/tarefas?' . http_build_query(['visao' => $chave] + $filtros)), 'tamanho' => 'sm',
                'variante' => $visao === $chave ? 'primary' : 'outline',
                'attrs' => $visao === $chave ? ['aria-current' => 'page'] : [],
            ]) ?>
        <?php endforeach; ?>
    </div>
    <form method="get" action="<?= e(url('/tarefas')) ?>" class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="visao" value="<?= e($visao) ?>">
        <label class="sr-only" for="filtro-tipo">Mostrar</label>
        <?= select('tipo', ['todos' => 'Tarefas e chamados', 'tarefas' => 'Só tarefas', 'chamados' => 'Só chamados'], $tipo, ['id' => 'filtro-tipo', 'attrs' => ['class' => 'select w-auto', 'onchange' => 'this.form.requestSubmit()']]) ?>
        <label class="sr-only" for="filtro-area">Área</label>
        <?= select('area', [0 => 'Todas as áreas'] + $areas, $area, ['id' => 'filtro-area', 'attrs' => ['class' => 'select w-auto', 'onchange' => 'this.form.requestSubmit()']]) ?>
    </form>
</div>

<?php if ($itens === []): ?>
    <?= card(['corpo_html' => vazio($vazios[$visao][0], $vazios[$visao][1], ['icone' => 'list-checks', 'acao_html' => botao('Novo chamado', ['href' => url('/chamados/nova?voltar=' . rawurlencode($voltar)), 'variante' => 'outline', 'icone' => 'plus'])])]) ?>
<?php else: ?>
    <?php
    $html = '<ul>';
    foreach ($itens as $item) {
        $html .= $item['tipo'] === 'chamado' ? chamado_linha($item['r'], $voltar) : tarefa_linha($item['r'], $voltar);
    }
    $html .= '</ul>';
    ?>
    <?= card(['corpo_html' => $html]) ?>
<?php endif; ?>
