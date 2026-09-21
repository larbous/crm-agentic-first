<?php

declare(strict_types=1);

use App\Controllers\NegocioController;
use App\Core\Config;
use App\Core\DB;
use App\Repositories\AcaoPendenteRepository;
use App\Repositories\AgenteRepository;
use App\Repositories\MigracaoRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\AI\AgenteDefinicao;
use App\Services\AI\AgentRunner;
use App\Services\Champ;
use App\Services\Schema;

// Fase 11 — lead scoring: temperatura "fervendo" e qualificação CHAMP (temperatura derivada pelo servidor).

/** @return array<string,?string> dimensões CHAMP na ordem D, A, M, P */
function champ(?string $d, ?string $a, ?string $m, ?string $p): array
{
    return ['champ_desafios' => $d, 'champ_autoridade' => $a, 'champ_dinheiro' => $m, 'champ_prioridade' => $p];
}

teste('champ: pontuação 0–8 e temperatura (7–8 fervendo, 5–6 quente, 3–4 morno, 0–2 frio)', function () {
    $c = 'confirmado';
    $p = 'parcial';
    $n = 'nao_identificado';
    $casos = [
        [champ($c, $c, $c, $c), 8, 'fervendo'],
        [champ($c, $c, $c, $p), 7, 'fervendo'],
        [champ($c, $c, $c, $n), 6, 'quente'],
        [champ($c, $c, $p, $p), 6, 'quente'],
        [champ($p, $p, $p, $p), 4, 'morno'],
        [champ($c, $n, $p, $n), 3, 'morno'],
        [champ($p, $n, $n, $n), 1, 'frio'],
        [champ($n, $n, $n, $n), 0, 'frio'],
        [champ($c, null, null, null), 2, 'frio'],
    ];
    foreach ($casos as [$dim, $pontos, $temp]) {
        igual($pontos, Champ::pontuar($dim), json_encode($dim));
        igual($temp, Champ::temperatura($dim), json_encode($dim));
    }
});

teste('champ: sem desafio confirmado ou parcial a temperatura nunca passa de morno; sem avaliação não há nada', function () {
    $c = 'confirmado';
    igual('morno', Champ::temperatura(champ('nao_identificado', $c, $c, $c)), '6 pontos, mas sem dor');
    igual('morno', Champ::temperatura(champ(null, $c, $c, $c)), 'desafio não avaliado também trava');
    igual('quente', Champ::temperatura(champ('parcial', $c, $c, 'nao_identificado')), 'desafio parcial basta');
    igual(null, Champ::pontuar(champ(null, null, null, null)));
    igual(null, Champ::temperatura(champ(null, null, null, null)));
    igual(null, Champ::pontuar([]));
});

teste('champ: derivar só age quando uma dimensão muda; temperatura explícita na mesma gravação vence', function () {
    $c = 'confirmado';
    igual([], Champ::derivar([], ['titulo' => 'x']), 'sem dimensão');
    igual([], Champ::derivar(champ($c, null, null, null), ['champ_desafios' => $c]), 'mesmo valor');
    igual([], Champ::derivar(champ($c, null, null, null), ['champ_resumo' => 'só justificativa']));

    $d = Champ::derivar([], champ($c, $c, $c, $c));
    igual(8, $d['champ_pontos']);
    igual('fervendo', $d['temperatura']);
    verdadeiro(isset($d['champ_avaliado_em']));

    $manual = Champ::derivar([], champ($c, $c, $c, $c) + ['temperatura' => 'morno']);
    igual(8, $manual['champ_pontos']);
    verdadeiro(!array_key_exists('temperatura', $manual), 'a temperatura explícita não é sobrescrita');

    $limpou = Champ::derivar(champ($c, null, null, null), champ(null, null, null, null));
    igual(null, $limpou['champ_pontos']);
    verdadeiro(!array_key_exists('temperatura', $limpou), 'apagar a avaliação não zera a temperatura');
});

teste('negócio: "fervendo" é temperatura válida; valores fora da lista e dimensões inválidas são recusados', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $id = novoNegocio($x, ['temperatura' => 'fervendo']);
    igual('fervendo', Repositorios::negocios()->encontrar($id)['temperatura']);
    igual('Fervendo', Schema::opcoes('temperatura')['fervendo']);

    $ruim = $x->atualizar('negocios', $id, ['temperatura' => 'escaldante']);
    verdadeiro(!$ruim->ok);
    $ruim = $x->atualizar('negocios', $id, ['champ_dinheiro' => 'talvez']);
    verdadeiro(!$ruim->ok);
    $sis = $x->atualizar('negocios', $id, ['champ_pontos' => 8]);
    verdadeiro(!$sis->ok, 'a pontuação é do servidor: campo de sistema não é gravável');
    igual(null, Repositorios::negocios()->encontrar($id)['champ_pontos']);
});

teste('negócio: gravar as dimensões CHAMP calcula pontos, data e temperatura; auditoria registra e desfazer restaura', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $id = novoNegocio($x, ['temperatura' => 'frio']);

    $r = $x->atualizar('negocios', $id, champ('confirmado', 'confirmado', 'confirmado', 'parcial') + ['champ_resumo' => 'D: dor clara; A: decisor na call; M: R$ 8 mil; P: quer para junho']);
    verdadeiro($r->ok, $r->mensagem);
    $n = Repositorios::negocios()->encontrar($id);
    igual('fervendo', $n['temperatura']);
    igual(7, (int) $n['champ_pontos']);
    verdadeiro($n['champ_avaliado_em'] !== null);

    $log = DB::conexao()->query("SELECT * FROM log_auditoria WHERE entidade = 'negocios' AND acao = 'atualizar' ORDER BY id DESC LIMIT 1")->fetch();
    contem('champ_pontos', (string) $log['depois']);
    contem('fervendo', (string) $log['depois']);

    // Esfriou: uma dimensão cai e a temperatura acompanha.
    $x->atualizar('negocios', $id, ['champ_prioridade' => 'nao_identificado', 'champ_dinheiro' => 'nao_identificado']);
    igual('morno', Repositorios::negocios()->encontrar($id)['temperatura']);
    igual(4, (int) Repositorios::negocios()->encontrar($id)['champ_pontos']);

    $desfeito = $x->desfazer();
    verdadeiro($desfeito->ok, $desfeito->mensagem);
    $n = Repositorios::negocios()->encontrar($id);
    igual('fervendo', $n['temperatura'], 'desfazer devolve a temperatura junto com as dimensões');
    igual(7, (int) $n['champ_pontos']);
});

teste('negócio: criar já com CHAMP deriva a temperatura; temperatura explícita não é sobrescrita', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $a = novoNegocio($x, champ('confirmado', 'confirmado', 'parcial', 'parcial'));
    igual('quente', Repositorios::negocios()->encontrar($a)['temperatura']);
    $b = novoNegocio($x, champ('confirmado', 'confirmado', 'confirmado', 'confirmado') + ['temperatura' => 'morno', 'titulo' => 'Outro']);
    $n = Repositorios::negocios()->encontrar($b);
    igual('morno', $n['temperatura'], 'quem escolheu a temperatura manda');
    igual(8, (int) $n['champ_pontos']);
});

teste('listas: ordenar por temperatura segue frio < morno < quente < fervendo e o filtro aceita fervendo', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    foreach (['fervendo', 'frio', 'quente', 'morno'] as $i => $t) {
        novoNegocio($x, ['titulo' => "Neg {$t}", 'temperatura' => $t]);
    }
    $ordem = static fn (string $dir): array => array_column(Repositorios::negocios()->listar(['ordem' => 'temperatura', 'dir' => $dir])['linhas'], 'temperatura');
    igual(['frio', 'morno', 'quente', 'fervendo'], $ordem('asc'));
    igual(['fervendo', 'quente', 'morno', 'frio'], $ordem('desc'));
    $so = Repositorios::negocios()->listar(['filtros' => ['temperatura' => 'fervendo']]);
    igual(1, $so['total']);
    igual('Neg fervendo', $so['linhas'][0]['titulo']);
});

teste('kanban: filtro por temperatura mostra só os cartões da temperatura; o cartão e o detalhe exibem o CHAMP', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    novoNegocio($x, ['titulo' => 'Quente A', 'temperatura' => 'quente']);
    novoNegocio($x, ['titulo' => 'Fervendo B'] + champ('confirmado', 'confirmado', 'confirmado', 'confirmado'));
    $ctl = new NegocioController();

    $_GET = [];
    $todos = $ctl->kanban()->corpo;
    contem('Quente A', $todos);
    contem('Fervendo B', $todos);
    contem('text-temp-fervendo', $todos);
    contem('CHAMP 8/8', $todos);

    $_GET = ['temperatura' => 'fervendo'];
    $filtrado = $ctl->kanban()->corpo;
    contem('Fervendo B', $filtrado);
    verdadeiro(!str_contains($filtrado, 'Quente A'), 'só fervendo');

    $_GET = ['temperatura' => 'lixo'];
    contem('Quente A', $ctl->kanban()->corpo, 'valor inválido é ignorado');
    $_GET = [];

    // Detalhe do negócio: cartão CHAMP com pontuação, dimensões e temperatura colorida.
    $id = (int) Repositorios::negocios()->listar(['busca' => 'Fervendo B'])['linhas'][0]['id'];
    $detalhe = $ctl->mostrar(['id' => (string) $id])->corpo;
    contem('Qualificação CHAMP', $detalhe);
    contem('Pontuação 8 de 8', $detalhe);
    contem('text-temp-fervendo', $detalhe);
});

// ---- Agentes ----------------------------------------------------------------------------------

teste('agentes: qualificador v2 (CHAMP) e resumidor v2 são válidos e não gravam temperatura', function () {
    bancoComSeed();
    foreach (['qualificador', 'resumidor-reuniao'] as $slug) {
        $def = json_decode((string) file_get_contents(dirname(__DIR__) . "/library/{$slug}.agent.json"), true);
        $v = AgenteDefinicao::validar($def);
        verdadeiro($v['ok'], $slug . ': ' . implode('; ', $v['erros']));
        igual(2, $def['versao']);
        verdadeiro(!in_array('temperatura', $def['campos_gravaveis'], true), "{$slug} não grava temperatura (o servidor calcula)");
        foreach (Champ::DIMENSOES as $d) {
            verdadeiro(in_array($d, $def['campos_gravaveis'], true), "{$slug} grava {$d}");
        }
    }
    $sistema = AgentRunner::sistema(AgenteDefinicao::validar(json_decode((string) file_get_contents(dirname(__DIR__) . '/library/qualificador.agent.json'), true))['definicao']);
    contem('confirmado|parcial|nao_identificado', $sistema, 'o contrato de campos lista os valores do CHAMP');
});

teste('agentes: qualificador grava CHAMP (com aprovação), o servidor deriva a temperatura ao aprovar', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $r = $x->salvarAgente((string) file_get_contents(dirname(__DIR__) . '/library/qualificador.agent.json'));
    verdadeiro($r->ok, $r->mensagem);
    $agente = (new AgenteRepository())->porSlug('qualificador');
    $empresa = novaEmpresa($x);
    $negocio = novoNegocio($x, ['empresa_id' => $empresa]);

    iaResponde(['acoes' => [
        ['acao' => 'atualizar', 'entidade' => 'negocios', 'dados' => champ('confirmado', 'confirmado', 'confirmado', 'confirmado') + ['champ_resumo' => 'D: x; A: y; M: z; P: w']],
    ], 'confianca' => 0.9, 'resumo' => 'Lead completo em CHAMP.']);
    $res = (new AgentRunner())->executar($agente, $negocio);
    igual(1, count($res['pendentes']), 'aprovação "escritas": fica na fila');
    igual(null, Repositorios::negocios()->encontrar($negocio)['temperatura']);

    $runner = new AgentRunner();
    $decisao = $runner->decidir((int) $res['pendentes'][0]['id'], true);
    verdadeiro($decisao['ok'], $decisao['mensagem']);
    $n = Repositorios::negocios()->encontrar($negocio);
    igual('fervendo', $n['temperatura']);
    igual(8, (int) $n['champ_pontos']);
    igual(0, (new AcaoPendenteRepository())->contarPendentes());
});

teste('agentes: tentar gravar temperatura direto no qualificador v2 é recusado (o campo não está na whitelist)', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $x->salvarAgente((string) file_get_contents(dirname(__DIR__) . '/library/qualificador.agent.json'));
    $agente = (new AgenteRepository())->porSlug('qualificador');
    $negocio = novoNegocio($x, ['empresa_id' => novaEmpresa($x)]);
    iaResponde(['acoes' => [['acao' => 'atualizar', 'entidade' => 'negocios', 'dados' => ['temperatura' => 'fervendo']]], 'confianca' => 0.95, 'resumo' => 'x']);
    $res = (new AgentRunner())->executar($agente, $negocio);
    igual([], $res['pendentes']);
    verdadeiro($res['recusadas'] !== [], 'recusada pela whitelist');
    igual(null, Repositorios::negocios()->encontrar($negocio)['temperatura']);
});

// ---- Migração ---------------------------------------------------------------------------------

teste('migração 0009: recria negocios preservando dados, vínculos e índices; aceita fervendo; runner religa as chaves estrangeiras', function () {
    $pdo = DB::conectar(':memory:');
    $repo = new MigracaoRepository($pdo);
    $repo->garantirTabela();
    $arquivos = glob(Config::obter('caminhos.migracoes') . '/*.sql') ?: [];
    sort($arquivos);
    $nova = null;
    foreach ($arquivos as $arquivo) {
        if (str_contains($arquivo, '0009_')) {
            $nova = $arquivo;
            break;
        }
        $repo->aplicar(basename($arquivo), (string) file_get_contents($arquivo));
    }
    verdadeiro($nova !== null, '0009 existe');

    $pdo->exec("INSERT INTO empresas (nome_fantasia, criado_em, atualizado_em) VALUES ('Padaria', '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
    $pdo->exec("INSERT INTO negocios (titulo, codigo, empresa_id, temperatura, valor_estimado, campos_extras, criado_em, atualizado_em) VALUES ('Site', 'NEG-2026-0001', 1, 'quente', 800000, '{\"a\":1}', '2026-09-01 10:00:00', '2026-09-02 10:00:00')");
    $pdo->exec("INSERT INTO atividades (tipo, assunto, data_hora, negocio_id, criado_em, atualizado_em) VALUES ('nota', 'Oi', '2026-09-01 10:00:00', 1, '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
    $pdo->exec("INSERT INTO tarefas (titulo, negocio_id, criado_em, atualizado_em) VALUES ('Ligar', 1, '2026-09-01 10:00:00', '2026-09-01 10:00:00')");

    $repo->aplicar(basename($nova), (string) file_get_contents($nova));

    $n = $pdo->query('SELECT * FROM negocios WHERE id = 1')->fetch();
    igual('Site', $n['titulo']);
    igual('NEG-2026-0001', $n['codigo']);
    igual(800000, (int) $n['valor_estimado']);
    igual('quente', $n['temperatura']);
    igual('{"a":1}', $n['campos_extras']);
    igual(null, $n['champ_pontos']);
    igual(1, (int) $pdo->query('SELECT COUNT(*) FROM atividades WHERE negocio_id = 1')->fetchColumn());
    igual(1, (int) $pdo->query('SELECT COUNT(*) FROM tarefas WHERE negocio_id = 1')->fetchColumn());
    igual([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    igual(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'chaves estrangeiras religadas');

    $pdo->exec("UPDATE negocios SET temperatura = 'fervendo' WHERE id = 1");
    dispara(PDOException::class, fn () => $pdo->exec("UPDATE negocios SET temperatura = 'escaldante'"));
    dispara(PDOException::class, fn () => $pdo->exec("UPDATE negocios SET champ_desafios = 'talvez'"));
    $indices = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'negocios'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['idx_negocios_empresa', 'idx_negocios_etapa', 'idx_negocios_status', 'idx_negocios_temperatura'] as $i) {
        verdadeiro(in_array($i, $indices, true), "índice {$i}");
    }
    dispara(PDOException::class, fn () => $pdo->exec("INSERT INTO atividades (tipo, data_hora, negocio_id, criado_em, atualizado_em) VALUES ('nota', 'x', 999, 'x', 'x')"), );
});

teste('migração com @fk-off: violação de chave estrangeira desfaz tudo e as chaves voltam a ficar ligadas', function () {
    $pdo = DB::conectar(':memory:');
    $repo = new MigracaoRepository($pdo);
    $repo->garantirTabela();
    $pdo->exec('CREATE TABLE pai (id INTEGER PRIMARY KEY); CREATE TABLE filho (id INTEGER PRIMARY KEY, pai_id INTEGER REFERENCES pai (id));');
    $sql = "-- @fk-off\nINSERT INTO filho (pai_id) VALUES (42);";
    dispara(RuntimeException::class, fn () => $repo->aplicar('9999_quebrada.sql', $sql));
    igual(0, (int) $pdo->query('SELECT COUNT(*) FROM filho')->fetchColumn(), 'rollback');
    igual([], $repo->aplicadas(), 'não registrada');
    igual(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
});
