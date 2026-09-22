<?php
/**
 * Instalação por cópia (primeira requisição, sem CLI): cria o primeiro usuário. Só aparece com a tabela usuarios
 * vazia; depois disso o CRM funciona como qualquer instalação normal (tela /login).
 * @var array{nome:string,email:string} $valores
 * @var string|null $erro
 */
?>
<div class="w-full max-w-sm">
    <div class="mb-6 flex items-center justify-center gap-2">
        <span class="app-marca-logo" aria-hidden="true">L</span>
        <span class="text-lg font-semibold">CRM Lárbous</span>
    </div>
    <?= card([
        'titulo'     => 'Bem-vindo! Vamos configurar o seu CRM',
        'descricao'  => 'Este é o primeiro acesso: crie o usuário administrador para começar a usar.',
        'corpo_html' => '<form method="post" action="' . e(url('/instalar')) . '" class="grid gap-4" novalidate>'
            . csrf_field()
            . (!empty($erro) ? alerta('Não foi possível concluir', $erro, 'destructive') : '')
            . campo(['nome' => 'nome', 'rotulo' => 'Seu nome', 'valor' => $valores['nome'],
                     'obrigatorio' => true, 'attrs' => ['autofocus' => true, 'maxlength' => 120]])
            . campo(['nome' => 'email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'valor' => $valores['email'],
                     'obrigatorio' => true, 'attrs' => ['autocomplete' => 'username']])
            . campo(['nome' => 'senha', 'rotulo' => 'Senha (mín. 8 caracteres)', 'tipo' => 'password',
                     'obrigatorio' => true, 'attrs' => ['autocomplete' => 'new-password', 'minlength' => 8]])
            . botao('Criar usuário e entrar', ['tipo' => 'submit', 'classe' => 'w-full'])
            . '</form>',
    ]) ?>
    <p class="text-muted-foreground mt-4 text-center text-xs">
        CRM Lárbous · <a class="underline-offset-4 hover:underline" href="https://github.com/larbous/crm-agentic-first" target="_blank" rel="noopener">github.com/larbous</a>
    </p>
</div>
