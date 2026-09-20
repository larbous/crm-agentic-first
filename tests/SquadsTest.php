<?php

declare(strict_types=1);

use App\Core\CronExpressao;
use App\Core\DB;
use App\Repositories\AgendamentoRepository;
use App\Repositories\AgenteRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\Repositorios;
use App\Repositories\SquadRepository;
use App\Services\ActionExecutor;
use App\Services\Agendador;
use App\Services\AI\AgentRunner;
use App\Services\AI\Client;
use App\Services\AI\Condicao;
use App\Services\AI\SquadDefinicao;
use App\Services\Events;
use App\Services\Gatilhos;
use App\Services\Rotinas;
use App\Services\Worker;

/**
 * IA falsa por agente: o prompt de cada agente de teste começa com "AGENTE:<slug>" e a resposta vem de $respostas[slug].
 * @param array<string,array> $respostas
 */
function iaPorAgente(array $respostas): void
{
    $GLOBALS['__chamadas_ia'] = [];
    Client::definirTransporte(static function (array $req) use ($respostas): array {
        $sistema = (string) $req['corpo']['system'][0]['text'];
        preg_match('/AGENTE:([a-z0-9-]+)/', $sistema, $m);
        $GLOBALS['__chamadas_ia'][] = ['slug' => $m[1] ?? '?', 'usuario' => (string) $req['corpo']['messages'][0]['content']];
        $saida = $respostas[$m[1] ?? ''] ?? ['acoes' => [], 'resumo' => 'sem resposta configurada'];
        return [
            'status' => 200,
            'corpo' => json_encode(['content' => [['type' => 'text', 'text' => json_encode($saida, JSON_UNESCAPED_UNICODE)]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]]),
            'erro' => null,
        ];
    });
}

function agenteDeSquad(string $slug, array $mudar = []): array
{
    return agenteNoBanco($mudar + [
        'slug' => $slug, 'nome' => "Agente {$slug}", 'prompt' => "AGENTE:{$slug} — faça o que o registro pede em JSON.",
        'acoes_permitidas' => [], 'campos_gravaveis' => [], 'aprovacao' => 'nunca', 'gatilho' => ['tipo' => 'manual'],
    ]);
}

function squadNoBanco(array $mudar = []): array
{
    $def = $mudar + [
        'slug' => 'sq-teste', 'nome' => 'Squad de teste', 'versao' => 1, 'entrada' => 'empresas',
        'etapas' => [['agente' => 'ag-a']], 'gatilho' => ['tipo' => 'manual'],
    ];
    $r = (new ActionExecutor())->salvarSquad($def);
    verdadeiro($r->ok, 'squad deveria salvar: ' . json_encode($r->erros));
    return (new SquadRepository())->encontrar((int) $r->id);
}

/** Linha de execução (cabeçalho ou etapa) por id. */
function execucao(int $id): array
{
    return (new ExecucaoRepository())->encontrar($id);
}

function estadoDoSquad(int $id): array
{
    return (array) json_decode((string) execucao($id)['saida'], true);
}

// ---- Cron ------------------------------------------------------------------------------------

teste('cron: próximo horário de expressões comuns e recusa de expressões inválidas', function () {
    $t = new DateTimeImmutable('2026-09-20 10:30:15'); // domingo
    $prox = static fn (string $e): string => CronExpressao::analisar($e)->proximo($t)->format('Y-m-d H:i');
    igual('2026-09-21 08:00', $prox('0 8 * * 1'), 'segunda 08:00');
    igual('2026-09-20 10:45', $prox('*/15 * * * *'), 'a cada 15 min');
    igual('2026-10-01 08:00', $prox('0 8 1 * *'), 'dia 1');
    igual('2026-09-27 10:30', $prox('30 10 * * 7'), 'domingo como 7, estritamente depois');
    igual('2026-09-21 09:00', $prox('0 9 * * 1-5'), 'dias úteis');
    igual('2028-02-29 00:00', $prox('0 0 29 2 *'), 'ano bissexto');
    igual('2026-09-21 09:00', $prox('0 9 15 * 1'), 'dia do mês OU dia da semana quando os dois são restritos');
    foreach (['61 * * * *', '* * *', '*/0 * * * *', '5-1 * * * *', 'a b c d e', '0 24 * * *'] as $ruim) {
        verdadeiro(CronExpressao::analisar($ruim) === null, "deveria recusar: {$ruim}");
    }
    verdadeiro(CronExpressao::analisar('0 8 * * 1')->combina(new DateTimeImmutable('2026-09-21 08:00:59')));
});

// ---- Condições -------------------------------------------------------------------------------

teste('condicao: operadores, listas, números, datas e variável ausente', function () {
    $vars = ['origem' => 'formulario', 'empresa.classificacao' => 'A', 'negocio.valor_estimado' => 8000, 'triagem.status' => null, 'x.data' => '2026-09-20'];
    $ok = static fn (string $e): bool => Condicao::verdadeira($e, static fn (string $v) => $vars[$v] ?? null);
    verdadeiro($ok('origem == formulario'));
    verdadeiro($ok('origem == "Formulario"'), 'sem diferenciar maiúsculas');
    verdadeiro(!$ok('origem != formulario'));
    verdadeiro($ok('empresa.classificacao in [A,B]'));
    verdadeiro(!$ok('empresa.classificacao in [B, "C"]'));
    verdadeiro($ok('negocio.valor_estimado > 5000'));
    verdadeiro($ok('negocio.valor_estimado >= 8000'));
    verdadeiro(!$ok('negocio.valor_estimado < 8000'));
    verdadeiro($ok('x.data < 2026-10-01'), 'datas ISO');
    verdadeiro(!$ok('triagem.status == spam'), 'ausente não é igual');
    verdadeiro($ok('triagem.status != spam'), 'ausente é diferente');
    verdadeiro(!$ok('origem > abc'), 'texto não tem ordem');
    verdadeiro(!$ok('isto não é condição'), 'expressão inválida = falso');
    verdadeiro(is_string(Condicao::analisar('a in b')), 'in exige lista');
    verdadeiro(is_string(Condicao::analisar('a == [1,2]')), 'lista só com in');
});

// ---- Definição -------------------------------------------------------------------------------

teste('squads: a biblioteca inicial (/library/*.squad.json) é válida e cita só agentes da biblioteca', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    foreach (glob(dirname(__DIR__) . '/library/*.agent.json') ?: [] as $arq) {
        verdadeiro($x->salvarAgente((string) file_get_contents($arq))->ok, basename($arq));
    }
    $arquivos = glob(dirname(__DIR__) . '/library/*.squad.json') ?: [];
    igual(6, count($arquivos), 'a biblioteca do SPEC §7.2 tem 6 squads');
    foreach ($arquivos as $arq) {
        $r = $x->salvarSquad((string) file_get_contents($arq));
        verdadeiro($r->ok, basename($arq) . ': ' . json_encode($r->erros, JSON_UNESCAPED_UNICODE));
    }
    igual(6, count((new SquadRepository())->todos()));
    $agendas = (new AgendamentoRepository())->todos();
    igual(1, count($agendas), 'só revisao-semanal é agendado');
    igual('0 8 * * 1', $agendas[0]['cron']);
});

teste('squads: definição inválida é recusada com mensagens claras', function () {
    bancoComSeed();
    agenteDeSquad('ag-a');
    $ruins = [
        'agente inexistente' => ['etapas' => [['agente' => 'nao-existe']]],
        'sem etapas' => ['etapas' => []],
        'agente e acao' => ['etapas' => [['agente' => 'ag-a', 'acao' => 'tarefa']]],
        'acao desconhecida' => ['etapas' => [['acao' => 'arquivar']]],
        'tarefa sem titulo' => ['etapas' => [['acao' => 'tarefa', 'dados' => ['prioridade' => 'alta']]]],
        'chave desconhecida' => ['etapas' => [['agente' => 'ag-a', 'condicoes' => 'x']]],
        'condicao sem sentido' => ['etapas' => [['agente' => 'ag-a', 'condicao' => 'vai chover']]],
        'condicao com campo inexistente' => ['etapas' => [['agente' => 'ag-a', 'condicao' => 'empresa.nao_existe == 1']]],
        'usa_saida_de de etapa posterior' => ['etapas' => [['agente' => 'ag-a', 'usa_saida_de' => ['ag-a']]]],
        'parar_se de quem não é etapa' => ['parar_se' => 'fantasma.status == x'],
        'agenda com registro' => ['gatilho' => ['tipo' => 'agendado', 'cron' => '0 8 * * 1']],
        'converter sem registro' => ['entrada' => 'nenhuma', 'etapas' => [['acao' => 'converter_cliente']]],
        'chave do squad desconhecida' => ['extra' => 1],
    ];
    foreach ($ruins as $rotulo => $mudar) {
        $v = SquadDefinicao::validar($mudar + ['slug' => 'sq-x', 'nome' => 'X', 'entrada' => 'empresas', 'etapas' => [['agente' => 'ag-a']]]);
        verdadeiro($v['ok'] === false && $v['erros'] !== [], "deveria recusar: {$rotulo}");
    }
    verdadeiro(SquadDefinicao::validar('não é json')['ok'] === false);
    verdadeiro(SquadDefinicao::validar(['slug' => 'sq-x', 'nome' => 'X', 'entrada' => 'empresas', 'etapas' => [['agente' => 'ag-a', 'condicao' => 'origem == formulario']], 'parar_se' => 'ag-a.status == spam'])['ok']);
});

teste('squads: salvar cria versão, importar slug existente versiona e agenda é sincronizada', function () {
    bancoComSeed();
    agenteDeSquad('ag-a', ['entrada' => 'nenhuma', 'contexto' => []]);
    $s = squadNoBanco(['entrada' => 'nenhuma', 'gatilho' => ['tipo' => 'agendado', 'cron' => '0 8 * * 1']]);
    igual(1, (int) $s['versao']);
    igual(1, count((new AgendamentoRepository())->todos()));
    $x = new ActionExecutor();
    igual('Nada a alterar.', $x->salvarSquad($s['def'], (int) $s['id'])->mensagem);
    $novo = $s['def'];
    $novo['gatilho'] = ['tipo' => 'agendado', 'cron' => '30 9 * * *'];
    verdadeiro($x->salvarSquad($novo, (int) $s['id'])->ok);
    igual(2, (int) (new SquadRepository())->encontrar((int) $s['id'])['versao']);
    igual('30 9 * * *', (new AgendamentoRepository())->todos()[0]['cron'], 'cron atualizado');
    verdadeiro($x->definirSquadAtivo((int) $s['id'], false)->ok);
    igual(0, count((new AgendamentoRepository())->todos()), 'squad desativado perde a agenda');
    verdadeiro(!$x->salvarSquad(['slug' => 'outro'] + $novo, (int) $s['id'])->ok, 'slug não muda na edição');
    igual(2, count((new SquadRepository())->versoes((int) $s['id'])));
});

// ---- Gatilhos --------------------------------------------------------------------------------

teste('gatilhos: evento enfileira squad e agente, sem rodar IA na requisição', function () {
    bancoComSeed();
    Gatilhos::registrar();
    iaPorAgente([]);
    agenteDeSquad('ag-a');
    agenteDeSquad('ag-evento', ['gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.criada']]);
    $squad = squadNoBanco(['gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.criada']]);
    $empresa = empresaDeTeste();

    igual(0, count($GLOBALS['__chamadas_ia']), 'nenhuma chamada de IA na hora do evento');
    $fila = (new ExecucaoRepository())->daFila(10);
    igual(2, count($fila));
    $porTipo = array_column($fila, null, 'agente_id');
    $agente = (new AgenteRepository())->porSlug('ag-evento');
    igual($empresa, (int) $porTipo[(int) $agente['id']]['registro_id']);
    igual('empresas', $porTipo[(int) $agente['id']]['entidade']);
    $cab = $porTipo[''] ?? array_values(array_filter($fila, static fn ($l) => $l['agente_id'] === null))[0];
    igual((int) $squad['id'], (int) $cab['squad_id']);
    igual($empresa, (int) $cab['registro_id']);
    igual('humano', json_decode($cab['entrada'], true)['origem']);
    igual('pendente', estadoDoSquad((int) $cab['id'])['etapas'][0]['estado']);

    // Deduplicação: outro evento para o mesmo registro enquanto o primeiro está na fila não enfileira de novo.
    Events::disparar('empresa.criada', ['entidade' => 'empresas', 'id' => $empresa, 'origem' => 'humano', 'registro' => []]);
    igual(2, count((new ExecucaoRepository())->daFila(10)));
    Events::limpar();
});

teste('gatilhos: não re-dispara o agente/squad que causou o evento, nem estoura o teto por hora', function () {
    bancoComSeed();
    iaPorAgente([]);
    agenteDeSquad('ag-a');
    $agente = agenteDeSquad('ag-evento', ['gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.atualizada']]);
    $squad = squadNoBanco(['gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.atualizada']]);
    $empresa = empresaDeTeste();
    $fila = static fn (): int => count((new ExecucaoRepository())->daFila(100));

    $payload = static fn (string $origem): array => ['evento' => 'empresa.atualizada', 'entidade' => 'empresas', 'id' => $empresa, 'origem' => $origem, 'registro' => []];
    Gatilhos::tratar($payload('agente:ag-evento'));
    igual(1, $fila(), 'origem = o próprio agente: só o squad dispara (o squad não usa esse agente)');
    DB::conexao()->exec('DELETE FROM execucoes');
    Gatilhos::tratar($payload('agente:ag-a'));
    igual(1, $fila(), 'origem = agente de etapa do squad: só o agente dispara');
    DB::conexao()->exec('DELETE FROM execucoes');
    Gatilhos::tratar($payload('agente:sq-teste'));
    igual(1, $fila(), 'origem = slug do squad (ação fixa): só o agente dispara');
    DB::conexao()->exec('DELETE FROM execucoes');

    for ($i = 1; $i <= Gatilhos::LIMITE_POR_HORA + 5; $i++) {
        $outra = empresaDeTeste(['nome_fantasia' => "Empresa {$i}", 'cnpj' => null]);
        Gatilhos::tratar(['evento' => 'empresa.atualizada', 'entidade' => 'empresas', 'id' => $outra, 'origem' => 'humano', 'registro' => []]);
    }
    igual(Gatilhos::LIMITE_POR_HORA, (new ExecucaoRepository())->iniciadasDesde('agente', (int) $agente['id'], date('Y-m-d H:i:s', strtotime('-1 hour'))), 'teto por hora do agente');
    igual(Gatilhos::LIMITE_POR_HORA, (new ExecucaoRepository())->iniciadasDesde('squad', (int) $squad['id'], date('Y-m-d H:i:s', strtotime('-1 hour'))), 'teto por hora do squad');
});

teste('gatilhos: o registro-alvo vem do próprio registro, de um vínculo ou da cadeia', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [$emp, $cid, $neg] = negocioComContato($x);
    $tarefa = (int) $x->criar('tarefas', ['titulo' => 'T', 'negocio_id' => $neg])->id;
    igual($neg, Gatilhos::alvo(['entidade' => 'negocios', 'id' => $neg], 'negocios'));
    igual($emp, Gatilhos::alvo(['entidade' => 'negocios', 'id' => $neg, 'registro' => Repositorios::negocios()->encontrar($neg)], 'empresas'));
    igual($neg, Gatilhos::alvo(['entidade' => 'tarefas', 'id' => $tarefa, 'registro' => Repositorios::tarefas()->encontrar($tarefa)], 'negocios'));
    igual(null, Gatilhos::alvo(['entidade' => 'negocios', 'id' => $neg], 'nenhuma'));
    igual(false, Gatilhos::alvo(['entidade' => 'empresas', 'id' => $emp], 'contratos'), 'evento sem contrato não dispara agente de contrato');
});

// ---- Squad ponta a ponta ---------------------------------------------------------------------

teste('squad: etapas em sequência com condição, usa_saida_de e parar_se; tokens por etapa', function () {
    bancoComSeed();
    agenteDeSquad('ag-a');
    agenteDeSquad('ag-b');
    agenteDeSquad('ag-c');
    iaPorAgente([
        'ag-a' => ['acoes' => [], 'resumo' => 'Achei o site da padaria', 'status' => 'lead'],
        'ag-b' => ['acoes' => [], 'resumo' => 'Qualificada'],
        'ag-c' => ['acoes' => [], 'resumo' => 'não deveria rodar'],
    ]);
    $squad = squadNoBanco(['etapas' => [
        ['agente' => 'ag-a', 'condicao' => 'origem == formulario'],
        ['agente' => 'ag-a'],
        ['agente' => 'ag-b', 'usa_saida_de' => ['ag-a']],
        ['agente' => 'ag-c', 'condicao' => 'empresa.status == cliente'],
    ], 'parar_se' => 'ag-b.resumo == Qualificada']);
    $empresa = empresaDeTeste();
    $id = (new App\Services\AI\SquadRunner())->enfileirar($squad, $empresa, 'Instrução extra', 'humano');
    igual('fila', execucao($id)['status']);

    $r = (new Worker())->rodada();
    igual(1, $r['processadas']);
    $cab = execucao($id);
    igual('concluida', $cab['status']);
    $estado = estadoDoSquad($id);
    igual(['pulada', 'concluida', 'concluida', 'nao_executada'], array_column($estado['etapas'], 'estado'));
    contem('origem == formulario', $estado['etapas'][0]['motivo']);
    contem('parar_se', (string) $estado['parada']);

    igual(['ag-a', 'ag-b'], array_column($GLOBALS['__chamadas_ia'], 'slug'), 'só 2 chamadas de IA');
    contem('Instrução extra', $GLOBALS['__chamadas_ia'][0]['usuario']);
    contem('ag-a: Achei o site da padaria (status: lead)', $GLOBALS['__chamadas_ia'][1]['usuario']);

    $etapas = (new ExecucaoRepository())->etapasDoSquad($id);
    igual(2, count($etapas));
    igual([2, 3], array_map('intval', array_column($etapas, 'etapa_ordem')));
    igual(['concluida', 'concluida'], array_column($etapas, 'status'));
    igual(['entrada' => 200, 'saida' => 40], (new ExecucaoRepository())->tokensDoSquad($id));
    igual($id, (int) $etapas[0]['squad_execucao_id']);
    // O cabeçalho não conta como chamada de IA nos totais do mês.
    igual(2, array_sum(array_column((new ExecucaoRepository())->totaisDoMes(date('Y-m')), 'execucoes')));
});

teste('squad: ações fixas (tarefa e converter_cliente) gravam com origem do squad e vínculo ao registro', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [$emp, $cid, $neg] = negocioComContato($x);
    $squad = squadNoBanco(['slug' => 'sq-acoes', 'entrada' => 'negocios', 'etapas' => [
        ['acao' => 'converter_cliente'],
        ['acao' => 'tarefa', 'dados' => ['titulo' => 'Onboarding', 'prioridade' => 'alta', 'vencimento_em_dias' => 2]],
    ]]);
    $id = (new App\Services\AI\SquadRunner())->enfileirar($squad, $neg);
    (new Worker())->rodada();

    igual('concluida', execucao($id)['status']);
    igual('cliente', Repositorios::empresas()->encontrar($emp)['status']);
    $t = DB::conexao()->query("SELECT * FROM tarefas WHERE titulo = 'Onboarding'")->fetch();
    igual($neg, (int) $t['negocio_id']);
    igual($emp, (int) $t['empresa_id']);
    igual('agente:sq-acoes', $t['criado_por']);
    igual(date('Y-m-d', strtotime('+2 days')), $t['vencimento']);
    $auditoria = DB::conexao()->query("SELECT COUNT(*) FROM log_auditoria WHERE origem = 'agente:sq-acoes' AND execucao_id = {$id}")->fetchColumn();
    igual(2, (int) $auditoria, 'auditoria vinculada à execução do squad');
});

teste('squad: etapa com ação pendente pausa o squad e a decisão o devolve para a fila', function () {
    bancoComSeed();
    agenteDeSquad('ag-a', ['acoes_permitidas' => ['atualizar'], 'campos_gravaveis' => ['segmento'], 'aprovacao' => 'escritas']);
    agenteDeSquad('ag-b');
    iaPorAgente([
        'ag-a' => ['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['segmento' => 'Padaria']]], 'resumo' => 'Ajustei o segmento'],
        'ag-b' => ['acoes' => [], 'resumo' => 'Depois da aprovação'],
    ]);
    $squad = squadNoBanco(['etapas' => [['agente' => 'ag-a'], ['agente' => 'ag-b', 'condicao' => 'empresa.segmento == Padaria']]]);
    $empresa = empresaDeTeste(['segmento' => 'Outro']);
    $id = (new App\Services\AI\SquadRunner())->enfileirar($squad, $empresa);

    (new Worker())->rodada();
    igual('aguardando_aprovacao', execucao($id)['status']);
    igual(['aguardando_aprovacao', 'pendente'], array_column(estadoDoSquad($id)['etapas'], 'estado'));
    igual(0, (new Worker())->rodada()['processadas'], 'aguardando aprovação não é reprocessado');
    igual('Outro', Repositorios::empresas()->encontrar($empresa)['segmento'], 'nada gravado ainda');

    $pendentes = DB::conexao()->query("SELECT id FROM acoes_pendentes WHERE status = 'pendente'")->fetchAll(PDO::FETCH_COLUMN);
    igual(1, count($pendentes));
    verdadeiro((new AgentRunner())->decidir((int) $pendentes[0], true)['ok']);
    igual('fila', execucao($id)['status'], 'decisão completa devolve o squad para a fila');

    (new Worker())->rodada();
    igual('concluida', execucao($id)['status']);
    igual(['concluida', 'concluida'], array_column(estadoDoSquad($id)['etapas'], 'estado'), 'a condição enxerga o que foi aprovado');
    igual('Padaria', Repositorios::empresas()->encontrar($empresa)['segmento']);
});

teste('squad: falha de IA em uma etapa encerra o squad com erro; cancelar tira da fila', function () {
    bancoComSeed();
    agenteDeSquad('ag-a');
    Client::definirTransporte(static fn (): array => ['status' => 500, 'corpo' => '{}', 'erro' => null]);
    $squad = squadNoBanco();
    $empresa = empresaDeTeste();
    $runner = new App\Services\AI\SquadRunner();
    $id = $runner->enfileirar($squad, $empresa);
    $r = (new Worker())->rodada();
    igual(1, $r['erros']);
    igual('erro', execucao($id)['status']);
    contem('Etapa 1', (string) execucao($id)['erro']);
    igual('erro', estadoDoSquad($id)['etapas'][0]['estado']);

    $outra = $runner->enfileirar($squad, $empresa);
    verdadeiro((new ExecucaoRepository())->cancelarSquad($outra));
    igual('cancelada', execucao($outra)['status']);
    igual(0, (new Worker())->rodada()['processadas'], 'cancelada não roda');
    verdadeiro(!(new ExecucaoRepository())->cancelarSquad($id), 'não cancela o que já terminou');
    dispara(InvalidArgumentException::class, fn () => $runner->enfileirar($squad, 999999));
});

// ---- Worker: agentes, agenda e recuperação ---------------------------------------------------

teste('worker: agente por evento roda pela fila reaproveitando a linha da execução', function () {
    bancoComSeed();
    Gatilhos::registrar();
    agenteDeSquad('ag-evento', ['gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.criada'], 'acoes_permitidas' => ['nota']]);
    iaPorAgente(['ag-evento' => ['acoes' => [['acao' => 'nota', 'dados' => ['assunto' => 'Oi', 'descricao' => 'Nota automática']]], 'resumo' => 'ok']]);
    $empresa = empresaDeTeste();
    $fila = (new ExecucaoRepository())->daFila(5);
    igual(1, count($fila));

    $r = (new Worker())->rodada();
    igual(1, $r['processadas']);
    $linha = execucao((int) $fila[0]['id']);
    igual('concluida', $linha['status']);
    igual(100, (int) $linha['tokens_entrada']);
    igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM execucoes')->fetchColumn(), 'sem linha duplicada');
    $nota = DB::conexao()->query("SELECT * FROM atividades WHERE tipo = 'nota'")->fetch();
    igual($empresa, (int) $nota['empresa_id']);
    igual('agente:ag-evento', $nota['criado_por']);
    Events::limpar();
});

teste('worker: agendamento vencido enfileira uma vez e calcula o próximo horário', function () {
    bancoComSeed();
    agenteDeSquad('ag-a', ['entrada' => 'nenhuma', 'contexto' => []]);
    squadNoBanco(['entrada' => 'nenhuma', 'gatilho' => ['tipo' => 'agendado', 'cron' => '0 8 * * 1']]);
    $repo = new AgendamentoRepository();
    $a = $repo->todos()[0];
    DB::conexao()->exec("UPDATE agendamentos_execucao SET proximo_run_em = '2026-09-14 08:00:00'");
    $agora = new DateTimeImmutable('2026-09-20 12:00:00'); // uma agenda perdida
    igual(1, Agendador::disparar($agora));
    igual(0, Agendador::disparar($agora), 'não dispara de novo');
    $depois = $repo->todos()[0];
    igual('2026-09-21 08:00:00', $depois['proximo_run_em']);
    igual('2026-09-20 12:00:00', $depois['ultimo_run_em']);
    $fila = (new ExecucaoRepository())->daFila(5);
    igual(1, count($fila));
    igual('sistema', json_decode($fila[0]['entrada'], true)['origem']);
});

teste('worker: recupera execuções interrompidas', function () {
    bancoComSeed();
    $x = new ExecucaoRepository();
    $velha = $x->iniciar(['agente_id' => 1, 'status' => 'rodando', 'iniciado_em' => date('Y-m-d H:i:s', strtotime('-1 hour'))]);
    $recente = $x->iniciar(['agente_id' => 1, 'status' => 'rodando']);
    $cabecalho = $x->iniciar(['squad_id' => 1, 'status' => 'rodando']);
    $r = (new Worker())->rodada();
    igual(2, $r['interrompidas']);
    igual('erro', execucao($velha)['status']);
    igual('erro', execucao($cabecalho)['status']);
    igual('rodando', execucao($recente)['status']);
});

// ---- Rotinas ---------------------------------------------------------------------------------

teste('rotinas: próxima ocorrência de tarefas recorrentes (fim de mês, ano bissexto, atraso)', function () {
    igual('2026-09-21', Rotinas::proximaOcorrencia('2026-09-20', 'diaria', '2026-09-01'));
    igual('2026-09-27', Rotinas::proximaOcorrencia('2026-09-20', 'semanal', '2026-09-01'));
    igual('2026-02-28', Rotinas::proximaOcorrencia('2026-01-31', 'mensal', '2026-01-01'), '31/01 → 28/02');
    igual('2026-03-31', Rotinas::proximaOcorrencia('2026-01-31', 'mensal', '2026-03-01'), 'volta ao dia 31 (a série parte da data-base)');
    igual('2027-02-28', Rotinas::proximaOcorrencia('2026-02-28', 'anual', '2026-01-01'));
    igual('2025-02-28', Rotinas::proximaOcorrencia('2024-02-29', 'anual', '2024-01-01'), '29/02 → 28/02');
    igual('2026-09-22', Rotinas::proximaOcorrencia('2026-01-01', 'diaria', '2026-09-22'), 'atraso longo: primeira data futura');
});

teste('rotinas: tarefa recorrente concluída gera a próxima uma única vez; vencida avisa uma única vez', function () {
    bancoComSeed();
    $eventos = [];
    capturarEventos($eventos);
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    $t = (int) $x->criar('tarefas', ['titulo' => 'Relatório mensal', 'recorrencia' => 'mensal', 'vencimento' => date('Y-m-d', strtotime('+3 days')) . ' 09:30', 'empresa_id' => $emp, 'prioridade' => 'alta'])->id;
    $vencida = (int) $x->criar('tarefas', ['titulo' => 'Ligar', 'vencimento' => date('Y-m-d', strtotime('-1 day'))])->id;
    $hojeDepois = (int) $x->criar('tarefas', ['titulo' => 'Hoje mais tarde', 'vencimento' => hoje()])->id;
    $x->concluirTarefa($t);
    $eventos = [];

    $r = (new Rotinas())->executar();
    igual(1, $r['tarefas_recorrentes']);
    igual(1, $r['tarefas_vencidas'], 'só a de ontem; vencimento de hoje só vence amanhã');
    igual(['tarefa.vencida'], array_values(array_filter($eventos, static fn ($e) => $e === 'tarefa.vencida')));

    $proxima = DB::conexao()->query("SELECT * FROM tarefas WHERE titulo = 'Relatório mensal' AND id <> {$t}")->fetch();
    verdadeiro($proxima !== false);
    igual(date('Y-m-d', strtotime('+3 days') + 0) < date('Y-m-d') ? '' : substr($proxima['vencimento'], 10), ' 09:30:00', 'mantém a hora');
    igual('pendente', $proxima['status']);
    igual('mensal', $proxima['recorrencia']);
    igual($emp, (int) $proxima['empresa_id']);
    igual('sistema', $proxima['criado_por']);
    verdadeiro($proxima['vencimento'] > date('Y-m-d', strtotime('+3 days')), 'um mês depois da data-base');

    $r2 = (new Rotinas())->executar();
    igual(0, $r2['tarefas_recorrentes'] + $r2['tarefas_vencidas'], 'nada se repete');
    // Vencimento alterado depois do aviso avisa de novo.
    $x->atualizar('tarefas', $vencida, ['vencimento' => date('Y-m-d', strtotime('-2 days'))]);
    igual(1, (new Rotinas())->executar()['tarefas_vencidas']);
    Events::limpar();
});

teste('rotinas: proposta expira, contrato avisa que vence e depois vence', function () {
    bancoComSeed();
    $eventos = [];
    capturarEventos($eventos);
    $x = new ActionExecutor();
    [$emp, $cid, $neg] = negocioComContato($x);
    $p = novaProposta($x, $neg);
    verdadeiro($x->enviarProposta($p)->ok);
    DB::conexao()->exec("UPDATE propostas SET validade = '" . date('Y-m-d', strtotime('-1 day')) . "' WHERE id = {$p}");

    $c1 = (int) $x->criar('contratos', ['titulo' => 'Vencendo', 'recorrencia' => 'unica', 'empresa_id' => $emp, 'negocio_id' => $neg])->id;
    $c2 = (int) $x->criar('contratos', ['titulo' => 'Vencido', 'recorrencia' => 'unica', 'empresa_id' => $emp, 'negocio_id' => $neg])->id;
    $c3 = (int) $x->criar('contratos', ['titulo' => 'Longe', 'recorrencia' => 'unica', 'empresa_id' => $emp])->id;
    $fim = static fn (string $d): string => date('Y-m-d', strtotime($d));
    DB::conexao()->exec("UPDATE contratos SET status = 'ativo', data_fim = '{$fim('+10 days')}', aviso_renovacao_dias = 30 WHERE id = {$c1}");
    DB::conexao()->exec("UPDATE contratos SET status = 'ativo', data_fim = '{$fim('-1 day')}' WHERE id = {$c2}");
    DB::conexao()->exec("UPDATE contratos SET status = 'ativo', data_fim = '{$fim('+90 days')}', aviso_renovacao_dias = 30 WHERE id = {$c3}");
    $eventos = [];

    $r = (new Rotinas())->executar();
    igual(1, $r['propostas_expiradas']);
    igual(1, $r['contratos_vencendo']);
    igual(1, $r['contratos_vencidos']);
    igual('expirada', Repositorios::propostas()->encontrar($p)['status']);
    igual('vencido', Repositorios::contratos()->encontrar($c2)['status']);
    igual('ativo', Repositorios::contratos()->encontrar($c1)['status']);
    igual('ativo', Repositorios::contratos()->encontrar($c3)['status']);
    sort($eventos);
    igual(['contrato.vencendo', 'contrato.vencido'], $eventos);
    igual(1, count(linhasAuditoria('propostas', 'expirar_proposta')));
    igual('sistema', linhasAuditoria('contratos', 'vencer_contrato')[0]['origem']);

    $eventos = [];
    $r2 = (new Rotinas())->executar();
    igual(0, array_sum($r2), 'segunda rodada não repete nada');
    igual([], $eventos);
    Events::limpar();
});

// ---- Chat ------------------------------------------------------------------------------------

teste('chat: #slug enfileira o squad (registro por nome, id ou tela) e /squads lista os ativos', function () {
    bancoComSeed();
    agenteDeSquad('ag-a');
    squadNoBanco();
    $empresa = empresaDeTeste();
    $chat = new App\Services\ChatService();

    $r = $chat->enviar('#sq-teste Padaria Central', null);
    $p = (array) $r['resposta']['payload'];
    igual('squad', $p['tipo']);
    contem('na fila', $r['resposta']['conteudo']);
    $cab = execucao((int) $p['execucao_id']);
    igual('fila', $cab['status']);
    igual($empresa, (int) $cab['registro_id']);
    igual('ia', json_decode($cab['entrada'], true)['origem']);
    igual('/squads/execucoes/' . $cab['id'], $p['link']);

    contem('Não encontrei o squad', $chat->enviar('#nao-existe x', null)['resposta']['conteudo']);
    contem('Não encontrei empresa', $chat->enviar('#sq-teste Fantasma Ltda', null)['resposta']['conteudo']);
    contem('#sq-teste', $chat->enviar('/squads', null)['resposta']['conteudo']);
    (new ActionExecutor())->definirSquadAtivo((int) (new SquadRepository())->porSlug('sq-teste')['id'], false);
    contem('Não encontrei o squad', $chat->enviar('#sq-teste Padaria Central', null)['resposta']['conteudo'], 'desativado');
});
