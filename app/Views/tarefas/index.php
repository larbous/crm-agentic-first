<?php
/**
 * Tela de tarefas: Hoje / Atrasadas / Próximos 7 dias / Todas.
 * @var string $visao
 * @var list<array> $tarefas
 * @var array $contagens
 * @var string $voltar
 */
$abas = ['hoje' => 'Hoje', 'atrasadas' => 'Atrasadas', 'proximas' => 'Próximos 7 dias', 'todas' => 'Todas'];
$vazios = [
    'hoje'      => ['Nada para hoje', 'Sem tarefas com vencimento hoje.'],
    'atrasadas' => ['Nenhuma tarefa atrasada', 'Você está em dia.'],
    'proximas'  => ['Nada nos próximos 7 dias', 'Sem tarefas com vencimento na semana.'],
    'todas'     => ['Nenhuma tarefa ainda', 'Crie a primeira tarefa.'],
];
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Tarefas</h1>
        <p class="text-muted-foreground"><?= (int) $contagens['atrasadas'] ?> atrasada(s) · <?= (int) $contagens['hoje'] ?> para hoje</p>
    </div>
    <?= botao('Nova tarefa', ['href' => url('/tarefas/nova'), 'icone' => 'plus']) ?>
</div>

<div class="flex flex-wrap gap-2" role="tablist" aria-label="Período">
    <?php foreach ($abas as $chave => $rotulo): ?>
        <?= botao($rotulo . ' (' . (int) $contagens[$chave] . ')', [
            'href' => url('/tarefas?visao=' . $chave), 'tamanho' => 'sm',
            'variante' => $visao === $chave ? 'primary' : 'outline',
            'attrs' => $visao === $chave ? ['aria-current' => 'page'] : [],
        ]) ?>
    <?php endforeach; ?>
</div>

<?php if ($tarefas === []): ?>
    <?= card(['corpo_html' => vazio($vazios[$visao][0], $vazios[$visao][1], ['icone' => 'list-checks', 'acao_html' => botao('Nova tarefa', ['href' => url('/tarefas/nova'), 'variante' => 'outline', 'icone' => 'plus'])])]) ?>
<?php else: ?>
    <?php $html = '<ul>'; foreach ($tarefas as $t) { $html .= tarefa_linha($t, $voltar); } $html .= '</ul>'; ?>
    <?= card(['corpo_html' => $html]) ?>
<?php endif; ?>
