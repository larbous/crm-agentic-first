<?php

declare(strict_types=1);

teste('e() escapa HTML e aspas', function () {
    igual('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
    igual('a &amp; b &#039;c&#039;', e("a & b 'c'"));
    igual('', e(null));
});

teste('moeda() formata centavos em reais pt-BR', function () {
    igual('R$ 8.000,00', moeda(800000));
    igual('R$ 0,05', moeda(5));
    igual('R$ 1.234.567,89', moeda(123456789));
    igual('-R$ 10,50', moeda(-1050));
    igual('R$ 0,00', moeda(null));
});

teste('reais_para_centavos() converte números e textos', function () {
    igual(800000, reais_para_centavos(8000));
    igual(800050, reais_para_centavos(8000.5));
    igual(800000, reais_para_centavos('8.000,00'));
    igual(800000, reais_para_centavos('R$ 8.000'));
    igual(150, reais_para_centavos('1,50'));
    igual(150, reais_para_centavos('1.5'));
    igual(123456789, reais_para_centavos('1.234.567,89'));
    igual(-1050, reais_para_centavos('-10,50'));
    igual(null, reais_para_centavos(''));
    igual(null, reais_para_centavos('abc'));
    igual(null, reais_para_centavos(null));
    igual(1999, reais_para_centavos(19.99), 'sem erro de ponto flutuante');
});

teste('centavos_para_texto() e centavos_para_reais()', function () {
    igual('8.000,00', centavos_para_texto(800000));
    igual('', centavos_para_texto(null));
    igual(80.5, centavos_para_reais(8050));
    igual(null, centavos_para_reais(null));
});

teste('data_br() e datahora_br() formatam ISO 8601', function () {
    igual('19/09/2026', data_br('2026-09-19'));
    igual('19/09/2026', data_br('2026-09-19 14:30:00'));
    igual('19/09/2026 14:30', datahora_br('2026-09-19 14:30:00'));
    igual('19/09/2026', datahora_br('2026-09-19'));
    igual('', data_br('2026-02-31'), 'data inexistente');
    igual('', data_br(null));
    igual('', data_br('lixo'));
});

teste('data_iso() converte dd/mm/aaaa e rejeita inválidas', function () {
    igual('2026-09-19', data_iso('19/09/2026'));
    igual('2026-01-05', data_iso('5/1/2026'));
    igual(null, data_iso('31/02/2026'));
    igual(null, data_iso('2026-09-19'));
    igual(null, data_iso(null));
});

teste('iniciais() gera até duas letras', function () {
    igual('AS', iniciais('Ana Souza'));
    igual('A', iniciais('Ana'));
    igual('JS', iniciais('João da Silva'));
    igual('?', iniciais('   '));
});

teste('url() e asset() respeitam base_url', function () {
    igual('/login', url('/login'));
    igual('/assets/css/app.css', asset('css/app.css'));
    \App\Core\Config::definir(['app' => ['base_url' => '/crm/']]);
    igual('/crm/login', url('login'));
    igual('/crm/assets/x.js', asset('/x.js'));
    \App\Core\Config::definir(['app' => ['base_url' => '']]);
});

teste('Validator valida regras e devolve mensagens em português', function () {
    $v = new \App\Core\Validator(
        ['nome' => '', 'email' => 'x', 'idade' => '12a', 'status' => 'zzz', 'data' => '2026-02-30', 'ok' => ' valor '],
        ['nome' => 'required', 'email' => 'required|email', 'idade' => 'integer', 'status' => 'in:a,b', 'data' => 'date', 'ok' => 'required|max:10', 'opcional' => 'email'],
        ['nome' => 'nome'],
    );
    verdadeiro($v->falha());
    igual('O campo nome é obrigatório.', $v->erros()['nome']);
    contem('e-mail válido', $v->erros()['email']);
    contem('inteiro', $v->erros()['idade']);
    contem('inválido', $v->erros()['status']);
    contem('data válida', $v->erros()['data']);
    verdadeiro(!isset($v->erros()['opcional']), 'campo vazio opcional não falha');
    igual('valor', $v->validados()['ok'], 'aparado');
});

teste('Validator passa com dados corretos e recusa regra desconhecida', function () {
    $v = new \App\Core\Validator(['email' => 'a@b.com', 'n' => 'abc'], ['email' => 'required|email', 'n' => 'min:2|max:5']);
    verdadeiro($v->passa());
    dispara(InvalidArgumentException::class, fn () => new \App\Core\Validator(['a' => 'x'], ['a' => 'inexistente']));
});

teste('Csrf valida token da sessão e bloqueia POST sem token', function () {
    $_SESSION = [];
    $_POST = [];
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $token = \App\Core\Csrf::token();
    igual(64, strlen($token));
    igual($token, \App\Core\Csrf::token(), 'estável na sessão');
    verdadeiro(\App\Core\Csrf::validar($token));
    verdadeiro(!\App\Core\Csrf::validar('errado'));
    verdadeiro(!\App\Core\Csrf::validar(null));

    $ctx = ['metodo' => 'POST', 'caminho' => '/x', 'opcoes' => []];
    igual(419, \App\Core\Csrf::middleware($ctx)?->status);
    igual(419, \App\Core\Csrf::middleware(['caminho' => '/api/x'] + $ctx)?->status);
    $_POST['_csrf'] = $token;
    igual(null, \App\Core\Csrf::middleware($ctx));
    $_POST = [];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    igual(null, \App\Core\Csrf::middleware($ctx), 'header X-CSRF-Token');
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    igual(null, \App\Core\Csrf::middleware(['metodo' => 'GET'] + $ctx), 'GET não exige token');
    igual(null, \App\Core\Csrf::middleware(['opcoes' => ['csrf' => false]] + $ctx), 'rota pública isenta');
});

teste('componentes escapam saída e montam marcação Basecoat', function () {
    $b = botao('<b>x</b>', ['variante' => 'destructive', 'href' => '/a?b=1&c=2']);
    contem('&lt;b&gt;x&lt;/b&gt;', $b);
    contem('data-variant="destructive"', $b);
    contem('href="/a?b=1&amp;c=2"', $b);
    contem('aria-label="Fechar"', botao('Fechar', ['tamanho' => 'icon-sm', 'icone' => 'x']));
    contem('badge-success', badge('ok', 'success'));
    contem('aria-invalid="true"', campo(['nome' => 'a', 'erro' => 'ruim']));
    contem('role="alert"', campo(['nome' => 'a', 'erro' => 'ruim']));
    contem('<option value="b" selected>', select('s', ['a' => 'A', 'b' => 'B'], 'b'));
    contem('--etapa: #ff0000', pill_etapa('X', '#ff0000'));
    contem('--etapa: #64748b', pill_etapa('X', 'red;background:url(x)'), 'cor inválida cai no padrão');
    contem('data-sort="numero"', data_table(['v' => ['rotulo' => 'V', 'ordenavel' => true, 'tipo' => 'numero']], [['v' => 1]]));
    contem('class="empty"', vazio('Nada'));
    contem('AS', avatar('Ana Souza'));
});
