<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Config;
use App\Core\DB;
use App\Repositories\MigracaoRepository;
use App\Repositories\SeedRepository;
use App\Repositories\UsuarioRepository;

/** Banco SQLite em memória com todas as migrações aplicadas. */
function bancoDeTeste(): PDO
{
    $pdo = DB::conectar(':memory:');
    $repo = new MigracaoRepository($pdo);
    $repo->garantirTabela();
    $arquivos = glob(Config::obter('caminhos.migracoes') . '/*.sql') ?: [];
    sort($arquivos);
    foreach ($arquivos as $arquivo) {
        $repo->aplicar(basename($arquivo), (string) file_get_contents($arquivo));
    }
    DB::definir($pdo);
    return $pdo;
}

teste('migrações criam o banco do zero com todas as tabelas da Fase 1', function () {
    $pdo = bancoDeTeste();
    $tabelas = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'migracoes', 'usuarios', 'configuracoes', 'log_auditoria', 'tags', 'taggables', 'origens', 'motivos_perda',
        'pipelines', 'etapas', 'empresas', 'contatos', 'negocios', 'negocio_contatos', 'atividades', 'tarefas',
        'anexos', 'campos_extras_def', 'chat_mensagens', 'execucoes', 'agentes', 'agentes_versoes', 'acoes_pendentes',
    ] as $t) {
        verdadeiro(in_array($t, $tabelas, true), "tabela {$t} ausente");
    }
    igual([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
});

teste('migrações são idempotentes e registradas em migracoes', function () {
    $pdo = bancoDeTeste();
    $repo = new MigracaoRepository($pdo);
    igual(['0001_nucleo.sql', '0002_comercial.sql', '0003_chat.sql', '0004_agentes.sql'], $repo->aplicadas());
});

teste('tabelas de negócio têm colunas padrão e valores monetários em INTEGER', function () {
    $pdo = bancoDeTeste();
    foreach (['empresas', 'contatos', 'negocios', 'atividades', 'tarefas', 'anexos', 'tags', 'pipelines', 'etapas', 'origens', 'motivos_perda'] as $t) {
        $colunas = array_column($pdo->query("PRAGMA table_info({$t})")->fetchAll(), 'name');
        foreach (['id', 'criado_em', 'atualizado_em', 'arquivado_em', 'criado_por'] as $c) {
            verdadeiro(in_array($c, $colunas, true), "{$t}.{$c} ausente");
        }
    }
    $tipos = array_column($pdo->query('PRAGMA table_info(negocios)')->fetchAll(), 'type', 'name');
    igual('INTEGER', $tipos['valor_estimado']);
    igual('INTEGER', $tipos['valor_fechado']);
    $tiposEmp = array_column($pdo->query('PRAGMA table_info(empresas)')->fetchAll(), 'type', 'name');
    igual('INTEGER', $tiposEmp['ticket_potencial']);
});

teste('PRAGMAs obrigatórios estão ativos na conexão', function () {
    $pdo = DB::conectar(sys_get_temp_dir() . '/crm_teste_' . uniqid() . '.sqlite');
    igual(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    igual('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn());
    igual(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
});

teste('chaves estrangeiras são aplicadas e CNPJ é único entre ativos', function () {
    $pdo = bancoDeTeste();
    $agora = agora();
    dispara(PDOException::class, fn () => $pdo->exec(
        "INSERT INTO contatos (nome, empresa_id, criado_em, atualizado_em) VALUES ('Ana', 999, '{$agora}', '{$agora}')"
    ));
    $ins = $pdo->prepare('INSERT INTO empresas (nome_fantasia, cnpj, criado_em, atualizado_em) VALUES (:n, :c, :a, :a)');
    $ins->execute(['n' => 'A', 'c' => '11222333000181', 'a' => $agora]);
    dispara(PDOException::class, fn () => $ins->execute(['n' => 'B', 'c' => '11222333000181', 'a' => $agora]));
    $ins->execute(['n' => 'C', 'c' => null, 'a' => $agora]);
    $ins->execute(['n' => 'D', 'c' => null, 'a' => $agora]); // NULL repetido é permitido
});

teste('seed cria etapas, origens e motivos padrão e é idempotente', function () {
    $pdo = bancoDeTeste();
    $seed = new SeedRepository($pdo);
    for ($i = 0; $i < 2; $i++) {
        $seed->garantirPipeline('Vendas', [
            ['Novo lead', 10, '#64748b', 'aberta'], ['Ganho', 100, '#22c55e', 'ganho'], ['Perdido', 0, '#ef4444', 'perdido'],
        ]);
        $seed->garantirNomes('origens', ['Indicação', 'Outro']);
        $seed->garantirNomes('motivos_perda', ['Preço']);
        $seed->garantirConfiguracao('fuso', 'America/Sao_Paulo');
    }
    igual(1, (int) $pdo->query('SELECT COUNT(*) FROM pipelines')->fetchColumn());
    igual(3, (int) $pdo->query('SELECT COUNT(*) FROM etapas')->fetchColumn());
    igual(2, (int) $pdo->query('SELECT COUNT(*) FROM origens')->fetchColumn());
    igual(1, (int) $pdo->query('SELECT COUNT(*) FROM motivos_perda')->fetchColumn());
    igual(1, (int) $pdo->query('SELECT COUNT(*) FROM configuracoes')->fetchColumn());
    igual('ganho', $pdo->query("SELECT tipo FROM etapas WHERE nome = 'Ganho'")->fetchColumn());
    dispara(InvalidArgumentException::class, fn () => $seed->garantirNomes('usuarios', ['x']));
});

teste('Auth: login válido regenera sessão e inválido é recusado', function () {
    $pdo = bancoDeTeste();
    $_SESSION = [];
    $repo = new UsuarioRepository();
    $id = $repo->criar('Ana Souza', 'ana@larbous.com', password_hash('segredo123', PASSWORD_DEFAULT));

    verdadeiro(!Auth::tentar('ana@larbous.com', 'errada'));
    verdadeiro(!Auth::tentar('outra@larbous.com', 'segredo123'));
    verdadeiro(!Auth::logado());

    verdadeiro(Auth::tentar(' ANA@larbous.com ', 'segredo123'), 'e-mail é normalizado');
    verdadeiro(Auth::logado());
    igual($id, Auth::usuario()['id']);
    verdadeiro(!isset(Auth::usuario()['senha_hash']), 'hash não é exposto');
    verdadeiro($pdo->query('SELECT ultimo_login_em FROM usuarios')->fetchColumn() !== null);

    Auth::sair();
    verdadeiro(!Auth::logado());
});

teste('Auth::exigir redireciona telas ao login e devolve 401 em /api', function () {
    bancoDeTeste();
    $_SESSION = [];
    $tela = Auth::exigir(['caminho' => '/empresas']);
    igual(302, $tela->status);
    igual('/login', $tela->cabecalhos['Location']);
    igual(401, Auth::exigir(['caminho' => '/api/ping'])->status);
});
