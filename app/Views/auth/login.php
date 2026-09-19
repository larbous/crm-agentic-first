<?php
/**
 * @var string|null $erro
 * @var string $email
 */
?>
<div class="w-full max-w-sm">
    <div class="mb-6 flex items-center justify-center gap-2">
        <span class="app-marca-logo" aria-hidden="true">L</span>
        <span class="text-lg font-semibold">CRM Lárbous</span>
    </div>
    <?= card([
        'titulo'     => 'Entrar',
        'descricao'  => 'Use seu e-mail e senha para acessar o CRM.',
        'corpo_html' => '<form method="post" action="' . e(url('/login')) . '" class="grid gap-4" novalidate>'
            . csrf_field()
            . (!empty($erro) ? alerta('Não foi possível entrar', $erro, 'destructive') : '')
            . campo(['nome' => 'email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'valor' => $email ?? '',
                     'obrigatorio' => true, 'attrs' => ['autocomplete' => 'username', 'autofocus' => true]])
            . campo(['nome' => 'senha', 'rotulo' => 'Senha', 'tipo' => 'password',
                     'obrigatorio' => true, 'attrs' => ['autocomplete' => 'current-password']])
            . botao('Entrar', ['tipo' => 'submit', 'classe' => 'w-full'])
            . '</form>',
    ]) ?>
</div>
