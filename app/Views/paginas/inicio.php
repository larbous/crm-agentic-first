<?php
/** @var array $usuario */
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
    <?= card([
        'titulo'     => 'Tarefas de hoje',
        'descricao'  => 'Aparecem aqui a partir da Fase 2.',
        'corpo_html' => vazio('Sem tarefas', 'Nada para hoje.', ['icone' => 'list-checks']),
    ]) ?>
</div>
