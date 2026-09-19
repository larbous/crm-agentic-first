<?php
/**
 * @var array $usuario
 * @var list<array> $atrasadas
 * @var list<array> $hoje
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
            'descricao'  => 'Comandos e linguagem natural entram na Fase 4.',
            'corpo_html' => vazio('Chat em breve', 'Use o painel lateral (Ctrl+K) quando o chat estiver disponível.', [
                'icone'     => 'message-square',
                'acao_html' => botao('Abrir painel de chat', ['variante' => 'outline', 'attrs' => ['data-alternar-chat' => true]]),
            ]),
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
