<?php
/**
 * @var array $usuario
 * @var list<array> $atrasadas
 * @var list<array> $hoje
 * @var string $caminho
 */
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
        $lista .= tarefa_linha($t, '/');
    }
    $lista .= '</ul>';
    ?>
    <?= card([
        'titulo'     => 'Tarefas de hoje',
        'descricao'  => count($atrasadas) . ' atrasada(s) · ' . count($hoje) . ' para hoje',
        'corpo_html' => $todas === []
            ? vazio('Sem tarefas', 'Nada para hoje.', ['icone' => 'list-checks'])
            : $lista . (count($todas) > 8 ? '<p class="pt-2 text-sm">' . link_para('/tarefas', 'Ver todas as tarefas', 'underline') . '</p>' : ''),
    ]) ?>
</div>
