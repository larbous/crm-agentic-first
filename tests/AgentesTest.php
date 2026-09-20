<?php

declare(strict_types=1);

use App\Core\DB;
use App\Repositories\AcaoPendenteRepository;
use App\Repositories\AgenteRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\AI\AcaoAgente;
use App\Services\AI\AgenteDefinicao;
use App\Services\AI\AgentRunner;
use App\Services\AI\Client;
use App\Services\AI\ContextBuilder;
use App\Services\ChatService;

/** Definição válida de agente para os testes; $mudar sobrescreve chaves. */
function defAgente(array $mudar = []): array
{
    return $mudar + [
        'slug' => 'pesquisador-teste', 'nome' => 'Pesquisador de teste', 'descricao' => 'Agente de teste', 'versao' => 1,
        'modelo' => 'claude-haiku-4-5-20251001', 'max_tokens' => 800, 'web_search' => false, 'entrada' => 'empresas',
        'contexto' => ['nome_fantasia', 'site', 'segmento'], 'contexto_relacionado' => ['atividades' => 3],
        'prompt' => 'Você pesquisa empresas brasileiras e devolve JSON com as ações.',
        'acoes_permitidas' => ['atualizar', 'nota', 'tarefa'], 'campos_gravaveis' => ['site', 'segmento', 'instagram'],
        'aprovacao' => 'escritas', 'gatilho' => ['tipo' => 'manual'],
    ];
}

/** Cria o agente no banco (via ActionExecutor) e devolve a linha. */
function agenteNoBanco(array $mudar = []): array
{
    $def = defAgente($mudar);
    $r = (new ActionExecutor())->salvarAgente($def);
    verdadeiro($r->ok, 'agente deveria salvar: ' . $r->mensagem);
    return (new AgenteRepository())->encontrar((int) $r->id);
}

function empresaDeTeste(array $extra = []): int
{
    $r = (new ActionExecutor())->criar('empresas', $extra + ['nome_fantasia' => 'Padaria Central', 'cnpj' => '11.222.333/0001-81', 'segmento' => 'Alimentação'], 'humano');
    verdadeiro($r->ok, 'empresa: ' . $r->mensagem);
    return (int) $r->id;
}

function linhasAuditoria(string $entidade, ?string $acao = null): array
{
    $st = DB::conexao()->prepare('SELECT * FROM log_auditoria WHERE entidade = :e' . ($acao !== null ? ' AND acao = :a' : '') . ' ORDER BY id');
    $st->execute($acao !== null ? ['e' => $entidade, 'a' => $acao] : ['e' => $entidade]);
    return $st->fetchAll();
}

// ---- Definição do agente ---------------------------------------------------------------------

teste('agentes: toda a biblioteca inicial (/library/*.agent.json) é válida e só usa ações e campos da whitelist', function () {
    bancoComSeed();
    $arquivos = glob(dirname(__DIR__) . '/library/*.agent.json') ?: [];
    igual(9, count($arquivos), 'a biblioteca do SPEC §6.2 tem 9 agentes');
    $x = new ActionExecutor();
    foreach ($arquivos as $arquivo) {
        $r = $x->salvarAgente((string) file_get_contents($arquivo));
        verdadeiro($r->ok, basename($arquivo) . ': ' . $r->mensagem);
    }
    igual(9, count((new AgenteRepository())->todos()));
    $pesquisador = (new AgenteRepository())->porSlug('pesquisador');
    verdadeiro($pesquisador['def']['web_search'], 'o pesquisador usa busca na web');
    igual('claude-sonnet-5', (new AgenteRepository())->porSlug('montador-proposta')['def']['modelo']);
});

teste('agentes: definição inválida é recusada com mensagens claras', function () {
    bancoComSeed();
    $casos = [
        'chave desconhecida' => defAgente(['acoes_permitida' => ['nota']]),
        'slug inválido' => defAgente(['slug' => 'Meu Agente']),
        'ação arquivar' => defAgente(['acoes_permitidas' => ['arquivar']]),
        'ação desfazer' => defAgente(['acoes_permitidas' => ['desfazer']]),
        'campo gravável inexistente' => defAgente(['campos_gravaveis' => ['senha']]),
        'id como campo gravável' => defAgente(['campos_gravaveis' => ['empresa_id']]),
        'atualizar sem campos' => defAgente(['campos_gravaveis' => []]),
        'contexto inexistente' => defAgente(['contexto' => ['nao_existe']]),
        'contexto sensível' => defAgente(['entrada' => 'propostas', 'contexto' => ['token_publico']]),
        'entrada inválida' => defAgente(['entrada' => 'usuarios']),
        'aprovação inválida' => defAgente(['aprovacao' => 'talvez']),
        'max_tokens absurdo' => defAgente(['max_tokens' => 999999]),
        'modelo estranho' => defAgente(['modelo' => 'gpt-4']),
        'relacionado inexistente' => defAgente(['contexto_relacionado' => ['usuarios' => 3]]),
        'gatilho evento desconhecido' => defAgente(['gatilho' => ['tipo' => 'evento', 'evento' => 'algo.aconteceu']]),
        'cron inválido' => defAgente(['gatilho' => ['tipo' => 'agendado', 'cron' => 'toda segunda']]),
        'mover_etapa sem etapa' => defAgente(['acoes_permitidas' => ['mover_etapa'], 'campos_gravaveis' => ['site']]),
        'prompt curto' => defAgente(['prompt' => 'oi']),
    ];
    foreach ($casos as $rotulo => $def) {
        $v = AgenteDefinicao::validar($def);
        verdadeiro($v['ok'] === false, "deveria recusar: {$rotulo}");
        verdadeiro($v['erros'] !== []);
    }
    verdadeiro(AgenteDefinicao::validar('não é json')['ok'] === false);
    verdadeiro(AgenteDefinicao::validar('[1,2]')['ok'] === false);
    verdadeiro(AgenteDefinicao::validar(defAgente(['gatilho' => ['tipo' => 'evento', 'evento' => 'negocio.ganho']]))['ok'], 'evento conhecido é aceito');
    verdadeiro(AgenteDefinicao::validar(defAgente(['gatilho' => ['tipo' => 'agendado', 'cron' => '0 8 * * 1']]))['ok'], 'cron de 5 campos é aceito');
});

teste('agentes: salvar cria versões, ignora salvamento sem mudança, restaura e não deixa mudar o slug', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $a = agenteNoBanco();
    $id = (int) $a['id'];
    igual(1, (int) $a['versao']);

    igual('Nada a alterar.', $x->salvarAgente(defAgente(), $id)->mensagem);
    igual(1, count((new AgenteRepository())->versoes($id)));

    $r = $x->salvarAgente(defAgente(['nome' => 'Outro nome']), $id);
    verdadeiro($r->ok);
    $a = (new AgenteRepository())->encontrar($id);
    igual(2, (int) $a['versao']);
    igual(2, $a['def']['versao'], 'a definição guarda o número da versão');
    igual('Outro nome', $a['nome']);

    // Importar um slug existente vira nova versão do mesmo agente (SPEC §6.1)
    $r = $x->salvarAgente(defAgente(['descricao' => 'Importado']));
    verdadeiro($r->ok);
    igual($id, $r->id);
    igual(3, (int) (new AgenteRepository())->encontrar($id)['versao']);
    igual(1, count((new AgenteRepository())->todos()));

    // Restaurar a versão 1 cria a versão 4 com o conteúdo antigo
    $r = $x->restaurarVersaoAgente($id, 1);
    verdadeiro($r->ok);
    $a = (new AgenteRepository())->encontrar($id);
    igual(4, (int) $a['versao']);
    igual('Pesquisador de teste', $a['nome']);
    igual(4, count((new AgenteRepository())->versoes($id)));

    verdadeiro(!$x->salvarAgente(defAgente(['slug' => 'outro-slug']), $id)->ok, 'editar não muda o slug');
    verdadeiro(!$x->restaurarVersaoAgente($id, 99)->ok);

    verdadeiro($x->definirAgenteAtivo($id, false)->ok);
    igual(0, (int) (new AgenteRepository())->encontrar($id)['ativo']);
});

// ---- Contexto enviado à IA -------------------------------------------------------------------

teste('contexto: só os campos permitidos vão para a IA, sem ids, com dinheiro em reais e nomes no lugar de fks', function () {
    bancoComSeed();
    $empresa = empresaDeTeste(['site' => 'padaria.com.br', 'instagram' => '@padaria']);
    $x = new ActionExecutor();
    $x->criar('atividades', ['tipo' => 'nota', 'descricao' => 'Ligou pedindo orçamento', 'empresa_id' => $empresa], 'humano');
    $neg = $x->criar('negocios', ['titulo' => 'Site novo', 'empresa_id' => $empresa, 'valor_estimado' => '8.000,00', 'origem_id' => 1], 'humano');
    verdadeiro($neg->ok, $neg->mensagem);

    $ctx = new ContextBuilder();
    $json = $ctx->paraAgente(defAgente(), 'empresas', $empresa, null, '2026-09-21');
    $d = json_decode($json, true);
    igual('padaria.com.br', $d['registro']['site']);
    igual('Padaria Central', $d['registro']['nome_fantasia']);
    verdadeiro(!isset($d['registro']['cnpj']), 'cnpj não foi pedido no contexto');
    verdadeiro(!isset($d['registro']['instagram']), 'instagram não está em "contexto"');
    verdadeiro(!str_contains($json, '"id"'), 'nenhum id vai para a IA');
    igual('2026-09-21', $d['hoje']);
    verdadeiro(in_array('Ligou pedindo orçamento', array_column($d['relacionado']['atividades'], 'descricao'), true), 'histórico do registro');

    $def = defAgente(['entrada' => 'negocios', 'contexto' => ['titulo', 'valor_estimado', 'origem_id'], 'contexto_relacionado' => ['empresa' => 1]]);
    $d = json_decode($ctx->paraAgente($def, 'negocios', (int) $neg->id, 'notas da reunião'), true);
    igual(8000, $d['registro']['valor_estimado'], 'dinheiro em reais');
    igual('Indicação', $d['registro']['origem'], 'fk vira o nome');
    igual('Padaria Central', $d['relacionado']['empresa']['nome_fantasia']);
    igual('notas da reunião', $d['entrada']);
});

// ---- Validação das ações do agente -----------------------------------------------------------

teste('ações do agente: whitelist de ações, campos e alcance; a IA nunca informa ids', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $def = defAgente();
    $cadeia = AcaoAgente::cadeia('empresas', $empresa);

    $ok = AcaoAgente::validar(['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'padaria.com.br']], $def, $cadeia);
    verdadeiro($ok['ok'], 'atualizar campo permitido');
    igual($empresa, $ok['plano']['servidor']['registro_id'], 'o servidor define o registro');

    foreach ([
        'campo fora de campos_gravaveis' => ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['cnpj' => '1']],
        'id vindo da IA' => ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['empresa_id' => 9]],
        'campo controlado pelo servidor' => ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['status' => 'cliente']],
        'ação fora da whitelist' => ['acao' => 'arquivar', 'entidade' => 'empresas'],
        'ação não permitida ao agente' => ['acao' => 'criar', 'entidade' => 'negocios', 'dados' => ['titulo' => 'x']],
        'entidade fora do alcance' => ['acao' => 'atualizar', 'entidade' => 'contratos', 'dados' => ['titulo' => 'x']],
        'valor não escalar' => ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => ['a']]],
        'sem campos' => ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => []],
        'não é objeto' => 'atualizar tudo',
    ] as $rotulo => $bruta) {
        $v = AcaoAgente::validar($bruta, $def, $cadeia);
        verdadeiro($v['ok'] === false, "deveria recusar: {$rotulo}");
    }

    // nota e tarefa têm campos fixos (independem de campos_gravaveis)
    $nota = AcaoAgente::validar(['acao' => 'nota', 'dados' => ['descricao' => 'Resumo', 'tipo' => 'reuniao']], $def, $cadeia);
    verdadeiro($nota['ok']);
    igual($empresa, $nota['plano']['servidor']['dados']['empresa_id'], 'a nota é vinculada ao alvo');
    verdadeiro(!AcaoAgente::validar(['acao' => 'nota', 'dados' => ['descricao' => 'x', 'tipo' => 'sistema']], $def, $cadeia)['ok'], 'tipo de nota restrito');
    verdadeiro(!AcaoAgente::validar(['acao' => 'tarefa', 'dados' => ['titulo' => 'x', 'empresa_id' => 3]], $def, $cadeia)['ok'], 'tarefa sem ids');
    verdadeiro(!AcaoAgente::validar(['acao' => 'nota', 'dados' => []], $def, $cadeia)['ok'], 'nota exige descrição');
});

teste('ações do agente: alcance pelo alvo (negócio → empresa) e nomes viram ids no servidor', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $empresa = empresaDeTeste();
    $neg = (int) $x->criar('negocios', ['titulo' => 'Site novo', 'empresa_id' => $empresa], 'humano')->id;
    $x->criar('servicos', ['nome' => 'Site institucional', 'categoria' => 'site', 'unidade' => 'projeto', 'preco_base' => '6.500,00', 'recorrente' => 0, 'ativo' => 1], 'humano');
    $x->criar('servicos', ['nome' => 'Hospedagem', 'categoria' => 'hospedagem', 'unidade' => 'mes', 'preco_base' => '90,00', 'recorrente' => 1, 'ativo' => 1], 'humano');
    $cadeia = AcaoAgente::cadeia('negocios', $neg);
    igual($empresa, $cadeia['empresas']);
    igual($neg, $cadeia['negocios']);

    $def = defAgente([
        'entrada' => 'negocios', 'acoes_permitidas' => ['atualizar', 'criar', 'mover_etapa'],
        'campos_gravaveis' => ['classificacao', 'temperatura', 'origem', 'etapa', 'titulo', 'itens', 'apresentacao'],
    ]);
    $v = AcaoAgente::validar(['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['classificacao' => 'A']], $def, $cadeia);
    verdadeiro($v['ok']);
    igual($empresa, $v['plano']['servidor']['registro_id']);
    $v = AcaoAgente::validar(['acao' => 'atualizar', 'entidade' => 'negocios', 'dados' => ['temperatura' => 'Quente', 'origem' => 'indicacao']], $def, $cadeia);
    verdadeiro($v['ok'], json_encode($v));
    igual('quente', $v['plano']['servidor']['dados']['temperatura'], 'enum por rótulo');
    igual(1, $v['plano']['servidor']['dados']['origem_id'], 'origem por nome (sem acento/caixa)');
    verdadeiro(!AcaoAgente::validar(['acao' => 'atualizar', 'entidade' => 'negocios', 'dados' => ['origem' => 'Marte']], $def, $cadeia)['ok']);
    verdadeiro(!AcaoAgente::validar(['acao' => 'atualizar', 'entidade' => 'contatos', 'dados' => ['titulo' => 'x']], $def, $cadeia)['ok'], 'negócio sem contato principal');

    $v = AcaoAgente::validar(['acao' => 'mover_etapa', 'dados' => ['etapa' => 'qualificado']], $def, $cadeia);
    verdadeiro($v['ok'], json_encode($v));
    igual(etapaId('Qualificado'), $v['plano']['servidor']['dados']['etapa_id']);
    verdadeiro(!AcaoAgente::validar(['acao' => 'mover_etapa', 'dados' => ['etapa' => 'Nenhuma']], $def, $cadeia)['ok']);

    // criar proposta: vínculos vêm do alvo; itens resolvem o catálogo e preenchem preço e unidade
    $v = AcaoAgente::validar(['acao' => 'criar', 'entidade' => 'propostas', 'dados' => [
        'titulo' => 'Site institucional', 'itens' => [['servico' => 'site institucional'], ['servico' => 'Hospedagem', 'quantidade' => 12]],
    ]], $def, $cadeia);
    verdadeiro($v['ok'], json_encode($v));
    $s = $v['plano']['servidor']['dados'];
    igual($empresa, $s['empresa_id']);
    igual($neg, $s['negocio_id']);
    igual(6500, $s['itens'][0]['valor_unitario'], 'preço base do catálogo, em reais');
    igual(true, $s['itens'][1]['servico_id'] > 0);
    verdadeiro(!AcaoAgente::validar(['acao' => 'criar', 'entidade' => 'propostas', 'dados' => ['titulo' => 'x', 'itens' => [['servico' => 'Inexistente']]]], $def, $cadeia)['ok'], 'serviço fora do catálogo');
    verdadeiro(!AcaoAgente::validar(['acao' => 'criar', 'entidade' => 'propostas', 'dados' => ['apresentacao' => 'sem título']], $def, $cadeia)['ok'], 'criar exige o título');
});

// ---- Execução: aprovação, gravação direta, simulação -----------------------------------------

teste('runner: com aprovação "escritas" a ação vai para acoes_pendentes e nada é gravado até aprovar', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco();
    iaResponde(['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'padaria.com.br', 'instagram' => '@padaria']]], 'resumo' => 'Achei o site.']);

    $r = (new AgentRunner())->executar($agente, $empresa);
    verdadeiro($r['ok']);
    igual('Achei o site.', $r['resumo']);
    igual(1, count($r['pendentes']));
    igual([], $r['aplicadas']);
    igual(null, Repositorios::empresas()->encontrar($empresa)['site'], 'nada gravado antes da aprovação');
    igual([], linhasAuditoria('empresas', 'atualizar'));

    $exec = (new ExecucaoRepository())->encontrar($r['execucao_id']);
    igual('aguardando_aprovacao', $exec['status']);
    igual((int) $agente['id'], (int) $exec['agente_id']);
    igual('empresas', $exec['entidade']);
    igual(420, (int) $exec['tokens_entrada'], 'tokens de entrada: 120 novos + 300 lidos do cache');
    igual(40, (int) $exec['tokens_saida']);
});

teste('runner: aprovar aplica pelo ActionExecutor com origem do agente e vínculo à execução; rejeitar descarta', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco();
    iaResponde(['acoes' => [
        ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'padaria.com.br']],
        ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['segmento' => 'Padaria e confeitaria']],
    ], 'resumo' => 'ok']);
    $runner = new AgentRunner();
    $r = $runner->executar($agente, $empresa);
    [$p1, $p2] = $r['pendentes'];

    verdadeiro($runner->decidir($p1['id'], true)['ok']);
    igual('padaria.com.br', Repositorios::empresas()->encontrar($empresa)['site']);
    $log = linhasAuditoria('empresas', 'atualizar');
    igual(1, count($log));
    igual('agente:pesquisador-teste', $log[0]['origem']);
    igual($r['execucao_id'], (int) $log[0]['execucao_id']);
    igual('aguardando_aprovacao', (new ExecucaoRepository())->encontrar($r['execucao_id'])['status'], 'ainda há uma pendente');

    verdadeiro($runner->decidir($p2['id'], false)['ok']);
    igual('Alimentação', Repositorios::empresas()->encontrar($empresa)['segmento'], 'rejeitada não altera');
    igual('concluida', (new ExecucaoRepository())->encontrar($r['execucao_id'])['status'], 'sem pendentes a execução conclui');
    igual(0, (new AcaoPendenteRepository())->contarPendentes());

    verdadeiro(!$runner->decidir($p1['id'], true)['ok'], 'não decide duas vezes');

    // o desfazer da auditoria reverte a ação do agente
    verdadeiro((new ActionExecutor())->desfazer((int) $log[0]['id'], 'humano')->ok);
    igual(null, Repositorios::empresas()->encontrar($empresa)['site']);
});

teste('runner: aprovar falha com clareza quando o registro mudou (a ação continua pendente)', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco();
    iaResponde(['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'padaria.com.br']]], 'resumo' => 'x']);
    $r = (new AgentRunner())->executar($agente, $empresa);
    (new ActionExecutor())->arquivar('empresas', $empresa, 'humano');

    $d = (new AgentRunner())->decidir($r['pendentes'][0]['id'], true);
    verdadeiro(!$d['ok']);
    $p = (new AcaoPendenteRepository())->encontrar($r['pendentes'][0]['id']);
    igual('pendente', $p['status']);
    verdadeiro($p['erro'] !== null && $p['erro'] !== '');
});

teste('runner: aprovação "nunca" grava na hora, com origem agente:<slug>', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde(['acoes' => [
        ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'padaria.com.br']],
        ['acao' => 'tarefa', 'dados' => ['titulo' => 'Ligar amanhã', 'vencimento' => '2026-09-22', 'prioridade' => 'alta']],
    ], 'resumo' => 'feito']);
    $r = (new AgentRunner())->executar($agente, $empresa);
    igual(2, count($r['aplicadas']));
    igual([], $r['pendentes']);
    igual('padaria.com.br', Repositorios::empresas()->encontrar($empresa)['site']);
    $tarefa = DB::conexao()->query("SELECT * FROM tarefas WHERE titulo = 'Ligar amanhã'")->fetch();
    igual($empresa, (int) $tarefa['empresa_id']);
    igual('agente:pesquisador-teste', $tarefa['criado_por']);
    igual('concluida', (new ExecucaoRepository())->encontrar($r['execucao_id'])['status']);
});

teste('runner: ação recusada pela validação é ignorada e registrada; as válidas seguem', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde(['acoes' => [
        ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['cnpj' => '00.000.000/0000-00']],
        ['acao' => 'arquivar', 'entidade' => 'empresas'],
        ['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['instagram' => '@padaria']],
    ], 'resumo' => 'x']);
    $r = (new AgentRunner())->executar($agente, $empresa);
    igual(1, count($r['aplicadas']));
    igual(2, count($r['recusadas']));
    igual('Alimentação', Repositorios::empresas()->encontrar($empresa)['segmento']);
    igual('11222333000181', Repositorios::empresas()->encontrar($empresa)['cnpj'], 'cnpj intacto');
    verdadeiro((new ExecucaoRepository())->encontrar($r['execucao_id'])['erro'] !== null, 'o motivo fica em execucoes.erro');
});

teste('runner: o texto do agente vira nota no registro (direto em "escritas", pendente em "sempre")', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    iaResponde(['acoes' => [], 'resumo' => 'Rascunho pronto', 'texto' => "Oi, Ana!\nPassando para saber do orçamento."]);

    $direto = agenteNoBanco(['slug' => 'redator-a', 'acoes_permitidas' => ['nota'], 'campos_gravaveis' => []]);
    $r = (new AgentRunner())->executar($direto, $empresa);
    igual(1, count($r['aplicadas']));
    $nota = DB::conexao()->query("SELECT * FROM atividades WHERE tipo = 'nota'")->fetch();
    contem('orçamento', (string) $nota['descricao']);
    igual($empresa, (int) $nota['empresa_id']);
    igual('agente:redator-a', $nota['criado_por']);

    $sempre = agenteNoBanco(['slug' => 'redator-b', 'acoes_permitidas' => ['nota'], 'campos_gravaveis' => [], 'aprovacao' => 'sempre']);
    $r = (new AgentRunner())->executar($sempre, $empresa);
    igual(1, count($r['pendentes']));
    igual(1, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'nota'")->fetchColumn(), 'a segunda nota ainda não existe');
    verdadeiro((new AgentRunner())->decidir($r['pendentes'][0]['id'], true)['ok']);
    igual(2, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'nota'")->fetchColumn());
});

teste('runner: simulação mostra o que seria feito (com antes/depois) e não grava nada', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde(['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['segmento' => 'Padaria']]], 'resumo' => 'x', 'texto' => 'Relatório']);

    $r = (new AgentRunner())->executar($agente, $empresa, null, true);
    verdadeiro($r['simulacao']);
    igual([], $r['aplicadas']);
    igual([], $r['pendentes']);
    igual(2, count($r['previas']), 'a ação e a nota do texto');
    igual('Alimentação', $r['previas'][0]['antes']['segmento']);
    igual('Padaria', $r['previas'][0]['depois']['segmento']);
    igual('Alimentação', Repositorios::empresas()->encontrar($empresa)['segmento']);
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM atividades')->fetchColumn());
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM acoes_pendentes')->fetchColumn());
    $exec = (new ExecucaoRepository())->encontrar($r['execucao_id']);
    igual(1, (int) $exec['simulacao']);
    verdadeiro((int) $exec['tokens_entrada'] > 0, 'a simulação também registra tokens');
});

teste('runner: saída inválida da IA vira erro registrado e nada é gravado', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde('não consegui pesquisar, desculpe');
    $r = (new AgentRunner())->executar($agente, $empresa);
    verdadeiro($r['ok'] === false);
    $exec = (new ExecucaoRepository())->encontrar($r['execucao_id']);
    igual('erro', $exec['status']);
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM log_auditoria WHERE origem LIKE \'agente:%\'')->fetchColumn());

    iaResponde(['acoes' => 'atualizar tudo', 'resumo' => 'x']);
    verdadeiro((new AgentRunner())->executar($agente, $empresa)['ok'] === false, 'acoes precisa ser uma lista');
});

teste('runner: agente desativado, registro inexistente e entidade errada não chamam a IA', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco();
    iaResponde(['acoes' => [], 'resumo' => 'x']);
    $runner = new AgentRunner();

    (new ActionExecutor())->definirAgenteAtivo((int) $agente['id'], false);
    $inativo = (new AgenteRepository())->encontrar((int) $agente['id']);
    dispara(InvalidArgumentException::class, fn () => $runner->executar($inativo, $empresa));
    verdadeiro($runner->executar($inativo, $empresa, null, true)['ok'], 'simulação é permitida com o agente desativado');

    $GLOBALS['__ia_requisicoes'] = [];
    $ativo = (new AgenteRepository())->encontrar((int) $agente['id']);
    $ativo['ativo'] = 1;
    dispara(InvalidArgumentException::class, fn () => $runner->executar($ativo, 9999));
    dispara(InvalidArgumentException::class, fn () => $runner->executar($ativo, null));
    igual(0, count($GLOBALS['__ia_requisicoes']), 'nenhuma chamada à IA');
});

teste('runner: a requisição leva o prompt do agente + contrato, só o contexto permitido, cache e web_search quando pedido', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['web_search' => true, 'contexto' => ['nome_fantasia']]);
    iaResponde(['acoes' => [], 'resumo' => 'x']);
    (new AgentRunner())->executar($agente, $empresa);
    $corpo = $GLOBALS['__ia_requisicoes'][0]['corpo'];

    igual('claude-haiku-4-5-20251001', $corpo['model']);
    igual(800, $corpo['max_tokens']);
    contem('Você pesquisa empresas brasileiras', $corpo['system'][0]['text']);
    contem('Ações permitidas', $corpo['system'][0]['text']);
    contem('- site: texto', $corpo['system'][0]['text']);
    igual(['type' => 'ephemeral'], $corpo['system'][0]['cache_control']);
    verdadeiro(!array_key_exists('temperature', $corpo), 'agentes não fixam temperatura');
    igual('web_search', $corpo['tools'][0]['name']);
    $usuario = json_decode($corpo['messages'][0]['content'], true);
    igual(['nome_fantasia' => 'Padaria Central'], $usuario['registro']);
    verdadeiro(!str_contains($corpo['messages'][0]['content'], '11.222'), 'o CNPJ não foi enviado');

    // sem web_search não há ferramentas
    $sem = agenteNoBanco(['slug' => 'sem-web']);
    iaResponde(['acoes' => [], 'resumo' => 'x']);
    (new AgentRunner())->executar($sem, $empresa);
    verdadeiro(!isset($GLOBALS['__ia_requisicoes'][0]['corpo']['tools']));
});

// ---- Chat ------------------------------------------------------------------------------------

teste('chat: /agentes lista os ativos e @slug executa o agente sobre o registro citado, sem histórico', function () {
    $chat = chatComSeed();
    $empresa = empresaDeTeste();
    agenteNoBanco(['slug' => 'pesquisador', 'aprovacao' => 'nunca']);
    agenteNoBanco(['slug' => 'agente-inativo', 'nome' => 'Inativo']);
    (new ActionExecutor())->definirAgenteAtivo((int) (new AgenteRepository())->porSlug('agente-inativo')['id'], false);

    $r = $chat->enviar('/agentes', null);
    contem('@pesquisador', $r['resposta']['conteudo']);
    verdadeiro(!str_contains($r['resposta']['conteudo'], 'agente-inativo'));

    iaResponde(['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'padaria.com.br']]], 'resumo' => 'Site encontrado.']);
    $r = $chat->enviar('@pesquisador Padaria Central', null);
    $resposta = $r['resposta'];
    igual('agente', $resposta['payload']['tipo']);
    contem('Pesquisador de teste executado em "Padaria Central"', $resposta['conteudo']);
    contem('1 alteração(ões) aplicada(s)', $resposta['conteudo']);
    igual("/empresas/{$empresa}", $resposta['payload']['link']);
    verdadeiro($resposta['payload']['alterou']);
    igual('padaria.com.br', Repositorios::empresas()->encontrar($empresa)['site']);
    igual(1, count($GLOBALS['__ia_requisicoes']), 'uma chamada de IA (só o agente, sem roteador)');
    igual('Padaria Central', $chat->historico(5)[count($chat->historico(5)) - 1]['contexto']['ultima_ref']['nome']);
});

teste('chat: @slug usa a tela aberta, pede desambiguação sem chamar a IA e recusa agente inexistente', function () {
    $chat = chatComSeed();
    $a = empresaDeTeste(['nome_fantasia' => 'Padaria Central', 'cnpj' => null]);
    $b = empresaDeTeste(['nome_fantasia' => 'Padaria Central Filial', 'cnpj' => null]);
    agenteNoBanco(['slug' => 'pesquisador']);
    iaResponde(['acoes' => [], 'resumo' => 'ok']);

    $r = $chat->enviar('@pesquisador', "/empresas/{$b}");
    contem('Pesquisador de teste executado em "Padaria Central Filial"', $r['resposta']['conteudo']);

    $GLOBALS['__ia_requisicoes'] = [];
    $r = $chat->enviar('@pesquisador Padaria', null);
    igual('escolha', $r['resposta']['payload']['tipo']);
    igual(0, count($GLOBALS['__ia_requisicoes']), 'desambiguar não chama a IA');
    $r2 = $chat->acionar((int) $r['resposta']['id'], 'escolher', 0);
    contem('executado em', $r2['resposta']['conteudo']);
    igual(1, count($GLOBALS['__ia_requisicoes']));

    $r = $chat->enviar('@nao-existe Padaria', null);
    contem('Não encontrei o agente', $r['resposta']['conteudo']);
    $r = $chat->enviar('@pesquisador Empresa Que Não Existe', null);
    contem('Não encontrei empresa', $r['resposta']['conteudo']);
});

teste('chat: @slug sem registro citado, sem tela e sem última referência pergunta qual registro', function () {
    $chat = chatComSeed();
    empresaDeTeste();
    agenteNoBanco(['slug' => 'pesquisador']);
    iaResponde(['acoes' => [], 'resumo' => 'ok']);
    $r = $chat->enviar('@pesquisador', null);
    contem('Sobre qual empresa', $r['resposta']['conteudo']);
    igual(0, count($GLOBALS['__ia_requisicoes']));
});

teste('chat: com o registro aberto, @slug + texto sem correspondência vira texto de apoio do agente', function () {
    $chat = chatComSeed();
    $empresa = empresaDeTeste();
    agenteNoBanco(['slug' => 'pesquisador']);
    iaResponde(['acoes' => [], 'resumo' => 'ok']);
    $r = $chat->enviar('@pesquisador foque no Instagram deles', "/empresas/{$empresa}");
    contem('executado em "Padaria Central"', $r['resposta']['conteudo']);
    $usuario = json_decode($GLOBALS['__ia_requisicoes'][0]['corpo']['messages'][0]['content'], true);
    igual('foque no Instagram deles', $usuario['entrada']);
});

teste('chat: erro da IA em @slug vira mensagem clara e nada é gravado', function () {
    $chat = chatComSeed();
    empresaDeTeste();
    agenteNoBanco(['slug' => 'pesquisador', 'aprovacao' => 'nunca']);
    iaResponde('x', 500);
    $r = $chat->enviar('@pesquisador Padaria Central', null);
    igual('erro', $r['resposta']['payload']['tipo']);
    igual(0, (int) DB::conexao()->query("SELECT COUNT(*) FROM log_auditoria WHERE origem LIKE 'agente:%'")->fetchColumn());
});

// ---- Execuções -------------------------------------------------------------------------------

teste('execuções: totais do mês por modelo somam tokens e a listagem traz o nome do agente', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde(['acoes' => [], 'resumo' => 'x']);
    $runner = new AgentRunner();
    $runner->executar($agente, $empresa);
    $runner->executar($agente, $empresa, null, true);

    $repo = new ExecucaoRepository();
    $totais = $repo->totaisDoMes(date('Y-m'));
    igual(1, count($totais));
    igual('claude-haiku-4-5-20251001', $totais[0]['modelo']);
    igual(2, $totais[0]['execucoes']);
    igual(2 * 420, $totais[0]['tokens_entrada'], '120 novos + 300 do cache por chamada');
    igual(2 * 40, $totais[0]['tokens_saida']);

    $lista = $repo->listar(['tipo' => 'agentes']);
    igual(2, $lista['total']);
    igual('Pesquisador de teste', $lista['linhas'][0]['agente_nome']);
    igual(0, $repo->listar(['tipo' => 'roteador'])['total']);
});
