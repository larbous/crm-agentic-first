<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\InstalacaoController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Repositories\UsuarioRepository;
use App\Services\Instalador;

// Instalação por cópia (sem CLI/SSH): auto-migração + seed na primeira requisição, e a tela /instalar.

/** Roda $fn com Instalador::pastaDb() e o banco apontando para um diretório temporário isolado do projeto real. */
function comInstaladorIsolado(callable $fn): void
{
    $pasta = sys_get_temp_dir() . '/crm-instalador-teste-' . bin2hex(random_bytes(6));
    mkdir($pasta, 0775, true);
    Instalador::definirPasta($pasta);
    Config::definir(array_replace_recursive(configNeutra(), ['db' => ['caminho' => $pasta . '/crm.sqlite']]));
    DB::definir(null);
    try {
        $fn($pasta);
    } finally {
        DB::definir(null);
        Instalador::definirPasta(null);
        Config::definir(configNeutra());
        foreach (glob($pasta . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($pasta);
    }
}

teste('Instalador: primeira requisição aplica migrações e semeia, sem precisar de CLI', function () {
    comInstaladorIsolado(function (string $pasta) {
        verdadeiro(!is_file($pasta . '/.instalado'));

        Instalador::executarSeNecessario();

        verdadeiro(is_file($pasta . '/.instalado'), 'marca de instalação concluída deve existir');
        $pdo = DB::conexao();
        $tabelas = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        verdadeiro(in_array('usuarios', $tabelas, true));
        verdadeiro(in_array('cobrancas', $tabelas, true), 'todas as migrações (até a mais recente) devem ter sido aplicadas');
        $pipeline = $pdo->query("SELECT nome FROM pipelines WHERE padrao = 1")->fetchColumn();
        igual('Vendas', $pipeline, 'o seed padrão deve ter rodado');
        igual(true, Instalador::precisaDoPrimeiroUsuario(), 'ainda não existe usuário nenhum');

        $migracoesAntes = (int) $pdo->query('SELECT COUNT(*) FROM migracoes')->fetchColumn();
        Instalador::executarSeNecessario(); // segunda chamada: marca já existe, não faz nada de novo
        igual($migracoesAntes, (int) $pdo->query('SELECT COUNT(*) FROM migracoes')->fetchColumn());
    });
});

teste('Instalador::precisaDoPrimeiroUsuario: falso assim que existe um usuário', function () {
    bancoComSeed();
    igual(true, Instalador::precisaDoPrimeiroUsuario());
    (new UsuarioRepository())->criar('Operador', 'op@exemplo.com', password_hash('senha-forte', PASSWORD_DEFAULT));
    igual(false, Instalador::precisaDoPrimeiroUsuario());
});

teste('Instalador::middleware: redireciona para /instalar sem usuário; deixa passar com usuário', function () {
    bancoComSeed();
    $r = Instalador::middleware(['caminho' => '/']);
    verdadeiro($r !== null);
    contem('/instalar', $r->cabecalhos['Location']);

    (new UsuarioRepository())->criar('Operador', 'op@exemplo.com', password_hash('senha-forte', PASSWORD_DEFAULT));
    igual(null, Instalador::middleware(['caminho' => '/']));
});

teste('/instalar: mostra o formulário só sem usuário; cria o primeiro usuário e já entra logado', function () {
    bancoComSeed();
    Auth::sair();
    $controller = new InstalacaoController();

    igual(200, $controller->formulario()->status);

    $_POST = ['nome' => 'Ana Operadora', 'email' => 'ana@exemplo.com', 'senha' => 'senha-bem-forte'];
    $r = $controller->criar();
    igual(302, $r->status);
    contem('/', $r->cabecalhos['Location']);
    verdadeiro(Auth::logado(), 'o operador já deve entrar logado depois de criar o primeiro usuário');
    igual('ana@exemplo.com', Auth::usuario()['email']);

    // Segunda visita: não há mais "primeiro usuário" a criar, a tela redireciona para /login.
    $rForm = $controller->formulario();
    igual(302, $rForm->status);
    contem('/login', $rForm->cabecalhos['Location']);
});

teste('/instalar: valida os campos e recusa e-mail já cadastrado (proteção contra corrida entre duas primeiras requisições)', function () {
    bancoComSeed();
    Auth::sair();
    $controller = new InstalacaoController();

    $_POST = ['nome' => '', 'email' => 'invalido', 'senha' => '123'];
    $r = $controller->criar();
    igual(422, $r->status);

    (new UsuarioRepository())->criar('Já existe', 'ja@exemplo.com', password_hash('outra-senha', PASSWORD_DEFAULT));
    $_POST = ['nome' => 'Segundo', 'email' => 'segundo@exemplo.com', 'senha' => 'senha-bem-forte'];
    $r2 = $controller->criar();
    igual(302, $r2->status);
    contem('/login', $r2->cabecalhos['Location'], 'com usuário já existente, a tela nem tenta criar outro');
});

teste('/login: redireciona para /instalar enquanto não houver usuário', function () {
    bancoComSeed();
    Auth::sair();
    $r = (new AuthController())->formLogin();
    igual(302, $r->status);
    contem('/instalar', $r->cabecalhos['Location']);

    (new UsuarioRepository())->criar('Operador', 'op@exemplo.com', password_hash('senha-forte', PASSWORD_DEFAULT));
    $r2 = (new AuthController())->formLogin();
    igual(200, $r2->status);
});
