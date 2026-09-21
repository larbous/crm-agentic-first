<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ChatRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\AI\Client;
use App\Services\AI\CommandRouter;
use App\Services\AI\ContextBuilder;
use App\Services\AI\ContratoRoteador;
use App\Services\ChatComandos;
use App\Services\ChatService;
use App\Services\ConsultaChat;

/** Transporte de IA falso: devolve $saida (array → JSON) como se fosse o modelo. Guarda a última requisição. */
function iaResponde(array|string $saida, int $status = 200): void
{
    // Saída de agente (tem "acoes") sem confiança declarada contaria como baixa: os testes assumem confiança alta, salvo se informarem.
    if (is_array($saida) && array_key_exists('acoes', $saida) && !array_key_exists('confianca', $saida)) {
        $saida['confianca'] = 0.95;
    }
    $GLOBALS['__ia_requisicoes'] = [];
    Client::definirTransporte(static function (array $req) use ($saida, $status): array {
        $GLOBALS['__ia_requisicoes'][] = $req;
        $texto = is_array($saida) ? json_encode($saida, JSON_UNESCAPED_UNICODE) : $saida;
        return [
            'status' => $status,
            'corpo'  => $status === 200
                ? json_encode(['content' => [['type' => 'text', 'text' => $texto]], 'usage' => ['input_tokens' => 120, 'output_tokens' => 40, 'cache_read_input_tokens' => 300]])
                : json_encode(['error' => ['message' => 'falha simulada']]),
            'erro'   => null,
        ];
    });
}

function chatComSeed(): ChatService
{
    bancoComSeed();
    iaResponde(['tipo' => 'indefinido', 'pergunta' => '?']);
    return new ChatService();
}

function contarAuditoria(): int
{
    return (int) DB::conexao()->query('SELECT COUNT(*) FROM log_auditoria')->fetchColumn();
}

function ultimoPayload(array $r): array
{
    return $r['resposta']['payload'];
}

// ---- Contrato do roteador (whitelist) -------------------------------------------------

teste('contrato: aceita criar negócio e converte enums por rótulo', function () {
    $v = ContratoRoteador::validar([
        'tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'negocios',
        'dados' => ['titulo' => 'Site', 'valor_estimado' => 8000, 'temperatura' => 'Quente', 'origem' => 'Instagram'],
        'ref' => ['empresa' => 'Padaria Central', 'contato' => 'Ana'],
    ]);
    verdadeiro($v['ok'], 'deveria validar');
    igual('quente', $v['plano']['dados']['temperatura']);
    igual('Padaria Central', $v['plano']['ref']['empresa']);
});

teste('contrato: recusa campo fora da whitelist, ids vindos da IA e campos controlados pelo servidor', function () {
    foreach ([
        ['dados' => ['titulo' => 'x', 'senha' => '1']],
        ['dados' => ['titulo' => 'x', 'empresa_id' => 3]],
        ['dados' => ['titulo' => 'x', 'codigo' => 'NEG-2026-9999']],
        ['dados' => ['titulo' => ['a']]],
    ] as $extra) {
        $v = ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'negocios'] + $extra);
        verdadeiro($v['ok'] === false, 'deveria recusar: ' . json_encode($extra));
    }
});

teste('contrato: "dados": {} (lista vazia no PHP) vale como sem dados', function () {
    $v = ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'nota', 'dados' => [], 'ref' => ['empresa' => 'X']]);
    verdadeiro($v['ok'], json_encode($v));
    verdadeiro(ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'mover_etapa', 'dados' => []])['ok'] === false, 'mover_etapa exige etapa');
});

teste('contrato: recusa ação, entidade e tipo desconhecidos e SQL na entidade', function () {
    foreach ([
        ['tipo' => 'acao', 'acao' => 'apagar', 'entidade' => 'empresas'],
        ['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'usuarios', 'dados' => ['nome' => 'x']],
        ['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'empresas; DROP TABLE empresas'],
        ['tipo' => 'sql', 'consulta' => 'SELECT 1'],
        ['tipo' => 'agente', 'slug' => 'Bad Slug'],
        'texto solto',
    ] as $saida) {
        verdadeiro(ContratoRoteador::validar($saida)['ok'] === false, 'deveria recusar: ' . json_encode($saida));
    }
});

teste('contrato: nota, tarefa e concluir fixam a entidade; nota aceita "texto"', function () {
    $v = ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'nota', 'entidade' => 'empresas', 'dados' => ['texto' => 'ligou'], 'ref' => ['empresa' => 'X']]);
    verdadeiro($v['ok']);
    igual('atividades', $v['plano']['entidade']);
    igual('ligou', $v['plano']['dados']['descricao']);
    igual('tarefas', ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'concluir', 'ref' => ['tarefa' => 'x']])['plano']['entidade']);
});

teste('contrato: consultar valida campos, operadores e tipos', function () {
    $ok = ContratoRoteador::validar([
        'tipo' => 'acao', 'acao' => 'consultar', 'entidade' => 'negocios',
        'filtro' => [['etapa', '=', 'Proposta'], ['valor_estimado', '>', 5000], ['previsao_fechamento', 'entre', ['2026-09-01', '2026-09-30']], ['proximo_passo_em', 'vazio']],
        'ordem' => 'previsao_fechamento asc', 'limite' => 20,
    ]);
    verdadeiro($ok['ok'], json_encode($ok));
    igual(500000, $ok['plano']['filtro'][1][2], 'reais viram centavos');
    foreach ([
        [['senha_hash', '=', 'x']],
        [['etapa', '>', 'x']],
        [['valor_estimado', 'contem', '5']],
        [['valor_estimado', '=', 'abc']],
        [['previsao_fechamento', '=', '31/02/2026']],
        [['etapa', 'LIKE', 'x']],
        [['status', '=', 'inexistente']],
    ] as $filtro) {
        $v = ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'consultar', 'entidade' => 'negocios', 'filtro' => $filtro]);
        verdadeiro($v['ok'] === false, 'deveria recusar: ' . json_encode($filtro));
    }
    verdadeiro(ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'consultar', 'entidade' => 'usuarios'])['ok'] === false);
    verdadeiro(ContratoRoteador::validar(['tipo' => 'acao', 'acao' => 'consultar', 'entidade' => 'negocios', 'ordem' => 'id; DROP TABLE x'])['ok'] === false);
});

teste('roteador: extrai JSON de cerca de markdown e o prompt é fixo', function () {
    igual(['tipo' => 'desfazer'], CommandRouter::extrairJson("```json\n{\"tipo\":\"desfazer\"}\n```"));
    igual(CommandRouter::prompt(), CommandRouter::prompt());
    contem('mover_etapa', CommandRouter::prompt());
    contem('valor_estimado', CommandRouter::prompt());
});

// ---- Consulta -------------------------------------------------------------------------

teste('consulta: filtra por etapa e valor, ordena e trata valor malicioso como texto', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    novoNegocio($x, ['titulo' => 'Site caro', 'empresa_id' => $emp, 'etapa_id' => etapaId('Proposta'), 'valor_estimado' => '8.000,00']);
    novoNegocio($x, ['titulo' => 'Site barato', 'empresa_id' => $emp, 'etapa_id' => etapaId('Proposta'), 'valor_estimado' => '1.000,00']);
    novoNegocio($x, ['titulo' => 'Outro', 'empresa_id' => $emp, 'etapa_id' => etapaId('Novo lead'), 'valor_estimado' => '9.000,00']);

    $plano = ConsultaChat::validar('negocios', ['filtro' => [['etapa', '=', 'proposta'], ['valor_estimado', '>', 5000]], 'ordem' => 'valor_estimado desc']);
    verdadeiro(is_array($plano), 'plano válido');
    $r = ConsultaChat::executar('negocios', $plano);
    igual(1, $r['total']);
    igual('Site caro', $r['linhas'][0]['celulas'][0]);
    igual('R$ 8.000,00', $r['linhas'][0]['celulas'][3]);

    $malicioso = ConsultaChat::validar('negocios', ['filtro' => [['titulo', '=', "x' OR 1=1 --"]]]);
    igual(0, ConsultaChat::executar('negocios', $malicioso)['total']);
    $contem = ConsultaChat::validar('negocios', ['filtro' => [['titulo', 'contem', 'SITE']], 'ordem' => 'titulo asc']);
    igual(2, ConsultaChat::executar('negocios', $contem)['total']);
});

// ---- Comandos "/" ---------------------------------------------------------------------

teste('comandos: /nota grava atividade no registro da tela; sem contexto avisa', function () {
    $chat = chatComSeed();
    $emp = novaEmpresa(new ActionExecutor());
    $r = $chat->enviar('/nota Ana pediu prazo maior', "/empresas/{$emp}");
    contem('✓ Nota registrada', $r['resposta']['conteudo']);
    $a = Repositorios::atividades()->encontrar(1);
    igual('nota', $a['tipo']);
    igual($emp, (int) $a['empresa_id']);
    igual('Ana pediu prazo maior', $a['descricao']);
    igual('humano', $a['criado_por']);
    $sem = $chat->enviar('/nota sem contexto nenhum', null);
    // ultima_ref (a empresa) ainda vale como contexto
    contem('✓ Nota registrada', $sem['resposta']['conteudo']);

    $vazio = chatComSeed();
    $erro = $vazio->enviar('/nota solta', null);
    contem('✗', $erro['resposta']['conteudo']);
});

teste('comandos: /tarefa interpreta data e hora no final e vincula ao registro', function () {
    $chat = chatComSeed();
    $emp = novaEmpresa(new ActionExecutor());
    $ano = date('Y') + 1;
    $r = $chat->enviar("/tarefa Enviar proposta 05/03/{$ano} 14:30", "/empresas/{$emp}");
    contem('✓ Tarefa "Enviar proposta" criada', $r['resposta']['conteudo']);
    $t = Repositorios::tarefas()->encontrar(1);
    igual("{$ano}-03-05 14:30:00", $t['vencimento']);
    igual($emp, (int) $t['empresa_id']);

    $chat->enviar('/tarefa Ligar amanhã cedo 09:00', null);
    igual(hoje() . ' 09:00:00', Repositorios::tarefas()->encontrar(2)['vencimento']);

    contem('Data inválida', $chat->enviar('/tarefa X 31/02', null)['resposta']['conteudo']);
});

teste('comandos: /concluir, /desfazer, /buscar, /ajuda e comando desconhecido', function () {
    $chat = chatComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x, 'Padaria Central');
    $t = $x->criar('tarefas', ['titulo' => 'Ligar']);

    contem('concluída', $chat->enviar("/concluir {$t->id}", null)['resposta']['conteudo']);
    igual('concluida', Repositorios::tarefas()->encontrar((int) $t->id)['status']);

    $r = $chat->enviar('/desfazer', null);
    contem('Ação desfeita', $r['resposta']['conteudo']);
    igual('pendente', Repositorios::tarefas()->encontrar((int) $t->id)['status']);

    $b = $chat->enviar('/buscar padaria', null);
    igual('busca', ultimoPayload($b)['tipo']);
    igual("/empresas/{$emp}", ultimoPayload($b)['grupos'][0]['itens'][0]['link']);

    contem('/nota', $chat->enviar('/ajuda', null)['resposta']['conteudo']);
    contem('Comando desconhecido', $chat->enviar('/xyz', null)['resposta']['conteudo']);
    igual([], (array) ($GLOBALS['__ia_requisicoes'] ?? []), 'comandos não chamam a IA');
});

// ---- Linguagem natural ----------------------------------------------------------------

teste('IA: cria negócio resolvendo empresa e contato por busca, com origem ia e execução registrada', function () {
    $chat = chatComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x, 'Padaria Central');
    $ana = $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $emp]);
    iaResponde([
        'tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'negocios',
        'dados' => ['titulo' => 'Site Padaria Central', 'valor_estimado' => 8000],
        'ref' => ['empresa' => 'Padaria Central', 'contato' => 'Ana'],
    ]);

    $r = $chat->enviar('cria negócio de 8 mil pro site da Padaria Central, contato Ana', null);
    igual('✓ Negócio "Site Padaria Central" criado (R$ 8.000,00)', $r['resposta']['conteudo']);
    $p = ultimoPayload($r);
    igual('acao', $p['tipo']);
    contem('/negocios/', $p['link']);

    $n = Repositorios::negocios()->encontrar(1);
    igual($emp, (int) $n['empresa_id']);
    igual((int) $ana->id, (int) $n['contato_principal_id']);
    igual(800000, (int) $n['valor_estimado']);
    igual('ia', $n['criado_por']);

    $log = (new AuditoriaRepository())->encontrar((int) $p['log_id']);
    igual('ia', $log['origem']);
    $ex = (new ExecucaoRepository())->encontrar((int) $log['execucao_id']);
    igual('concluida', $ex['status']);
    igual('claude-haiku-4-5-20251001', $ex['modelo']);
    igual(420, (int) $ex['tokens_entrada']);
    igual(40, (int) $ex['tokens_saida']);

    // Uma chamada por mensagem, prompt em bloco com cache_control, sem histórico no corpo.
    $req = $GLOBALS['__ia_requisicoes'];
    igual(1, count($req));
    igual('ephemeral', $req[0]['corpo']['system'][0]['cache_control']['type']);
    igual(1, count($req[0]['corpo']['messages']));
    $entrada = json_decode($req[0]['corpo']['messages'][0]['content'], true);
    igual(['tela', 'ultima_ref', 'hoje', 'mensagem'], array_keys($entrada));

    // Última referência passa a ser o negócio criado.
    igual('negocios', (new ChatRepository())->ultimaRef()['entidade']);
});

teste('IA: nome ambíguo mostra botões e a escolha completa a ação sem nova chamada', function () {
    $chat = chatComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x, 'Padaria Central');
    $a = $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $emp]);
    $b = $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Paula']);
    iaResponde(['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'negocios', 'dados' => ['titulo' => 'Site'], 'ref' => ['contato' => 'Ana']]);

    $r = $chat->enviar('cria negócio Site para a Ana', null);
    $p = ultimoPayload($r);
    igual('escolha', $p['tipo']);
    igual(2, count($p['opcoes']));
    igual(0, Repositorios::negocios()->listar()['total'], 'nada gravado antes da escolha');
    igual(1, count($GLOBALS['__ia_requisicoes']));

    $indice = $p['opcoes'][0]['id'] === (int) $b->id ? 0 : 1;
    $r2 = $chat->acionar((int) $r['resposta']['id'], 'escolher', $indice);
    contem('✓ Negócio "Site" criado', $r2['resposta']['conteudo']);
    igual(1, count($GLOBALS['__ia_requisicoes']), 'sem nova chamada à IA');
    igual($p['opcoes'][$indice]['id'], (int) Repositorios::negocios()->encontrar(1)['contato_principal_id']);
    igual('resolvida', $r2['atualizada']['payload']['estado']);

    dispara(InvalidArgumentException::class, fn () => $chat->acionar((int) $r['resposta']['id'], 'escolher', 0));
});

teste('IA: referência inexistente oferece criar; ao aceitar cria e conclui a ação (cada escrita auditada)', function () {
    $chat = chatComSeed();
    iaResponde(['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'negocios', 'dados' => ['titulo' => 'Site'], 'ref' => ['empresa' => 'Loja Nova']]);

    $r = $chat->enviar('negócio Site para Loja Nova', null);
    igual('criar_ref', ultimoPayload($r)['tipo']);
    igual(0, Repositorios::empresas()->listar()['total']);

    $r2 = $chat->acionar((int) $r['resposta']['id'], 'criar', null);
    contem('✓ Empresa "Loja Nova" criada', $r2['resposta']['conteudo']);
    contem('✓ Negócio "Site" criado', $r2['resposta']['conteudo']);
    igual('Loja Nova', Repositorios::empresas()->encontrar(1)['nome_fantasia']);
    igual(1, (int) Repositorios::negocios()->encontrar(1)['empresa_id']);
    igual(2, contarAuditoria());
    $origens = DB::conexao()->query('SELECT DISTINCT origem FROM log_auditoria')->fetchAll(PDO::FETCH_COLUMN);
    igual(['ia'], $origens);
});

teste('IA: arquivar sempre pede confirmação; cancelar não altera; confirmar arquiva e o botão Desfazer restaura', function () {
    $chat = chatComSeed();
    $emp = novaEmpresa(new ActionExecutor(), 'Padaria Central');
    iaResponde(['tipo' => 'acao', 'acao' => 'arquivar', 'entidade' => 'empresas', 'ref' => ['empresa' => 'Padaria Central']]);

    $r = $chat->enviar('arquiva a Padaria Central', null);
    igual('confirmar', ultimoPayload($r)['tipo']);
    verdadeiro(Repositorios::empresas()->existe($emp), 'ainda ativa');
    $auditoriaAntes = contarAuditoria();

    $c = $chat->acionar((int) $r['resposta']['id'], 'cancelar', null);
    contem('Nada foi alterado', $c['resposta']['conteudo']);
    verdadeiro(Repositorios::empresas()->existe($emp));
    igual($auditoriaAntes, contarAuditoria());
    dispara(InvalidArgumentException::class, fn () => $chat->acionar((int) $r['resposta']['id'], 'confirmar', null));

    $r = $chat->enviar('arquiva a Padaria Central', null);
    $ok = $chat->acionar((int) $r['resposta']['id'], 'confirmar', null);
    contem('✓ Empresa "Padaria Central" arquivada', $ok['resposta']['conteudo']);
    verdadeiro(!Repositorios::empresas()->existe($emp));

    $u = $chat->acionar((int) $ok['resposta']['id'], 'desfazer', null);
    contem('Ação desfeita', $u['resposta']['conteudo']);
    verdadeiro(Repositorios::empresas()->existe($emp));
    verdadeiro($u['atualizada']['payload']['desfeito']);
    dispara(InvalidArgumentException::class, fn () => $chat->acionar((int) $ok['resposta']['id'], 'desfazer', null));
});

teste('IA: saída inválida não grava nada e responde "Não entendi"', function () {
    $chat = chatComSeed();
    novaEmpresa(new ActionExecutor());
    $antes = contarAuditoria();
    foreach ([
        ['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'empresas', 'dados' => ['nome_fantasia' => 'X', 'cnpj_hack' => '1']],
        ['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'usuarios', 'dados' => ['nome' => 'x']],
        'não sou JSON',
        ['tipo' => 'acao', 'acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['status' => 'cliente'], 'ref' => ['empresa' => 1, 'tabela' => 'x']],
    ] as $saida) {
        iaResponde($saida);
        $r = $chat->enviar('faça algo', null);
        igual('Não entendi. Tente reformular ou use /ajuda.', $r['resposta']['conteudo']);
    }
    igual($antes, contarAuditoria());
    $ex = DB::conexao()->query('SELECT erro FROM execucoes ORDER BY id DESC LIMIT 1')->fetchColumn();
    contem('saída recusada', (string) $ex);
});

teste('IA: falha da API vira mensagem ao operador e execução com status erro', function () {
    $chat = chatComSeed();
    iaResponde('', 401);
    $r = $chat->enviar('cria empresa X', null);
    contem('chave da API', $r['resposta']['conteudo']);
    $ex = DB::conexao()->query('SELECT status, erro FROM execucoes ORDER BY id DESC LIMIT 1')->fetch();
    igual('erro', $ex['status']);
    contem('HTTP 401', $ex['erro']);
    verdadeiro(!str_contains(json_encode($r), 'sk-'), 'sem vazar chave');

    // Sem transporte falso e sem chave (mesmo que config.local.php tenha uma): nunca chama a rede.
    Client::definirTransporte(null);
    Config::definir(configNeutra());
    try {
        $sem = $chat->enviar('cria empresa X', null);
    } finally {
        Config::definir(configNeutra());
    }
    contem('não está configurada', $sem['resposta']['conteudo']);
});

teste('IA: mover etapa por nome, ganho exige valor e usa o registro da tela por padrão', function () {
    $chat = chatComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    $n = novoNegocio($x, ['empresa_id' => $emp]);

    iaResponde(['tipo' => 'acao', 'acao' => 'mover_etapa', 'entidade' => 'negocios', 'dados' => ['etapa' => 'proposta']]);
    $r = $chat->enviar('move pra proposta', "/negocios/{$n}");
    contem('movido para "Proposta"', $r['resposta']['conteudo']);
    igual(etapaId('Proposta'), (int) Repositorios::negocios()->encontrar($n)['etapa_id']);

    iaResponde(['tipo' => 'acao', 'acao' => 'mover_etapa', 'dados' => ['etapa' => 'Ganho']]);
    $sem = $chat->enviar('ganhei', "/negocios/{$n}");
    contem('valor fechado', $sem['resposta']['conteudo']);
    iaResponde(['tipo' => 'acao', 'acao' => 'mover_etapa', 'dados' => ['etapa' => 'Ganho', 'valor_fechado' => 7500]]);
    $com = $chat->enviar('ganhei por 7500', "/negocios/{$n}");
    contem('ganho: R$ 7.500,00', $com['resposta']['conteudo']);

    iaResponde(['tipo' => 'acao', 'acao' => 'mover_etapa', 'dados' => ['etapa' => 'Inexistente']]);
    contem('Etapas:', $chat->enviar('move', "/negocios/{$n}")['resposta']['conteudo']);
});

teste('IA: sem citar registro usa a tela; nota e tarefa vinculam ao contexto', function () {
    $chat = chatComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x, 'Padaria Central');

    iaResponde(['tipo' => 'acao', 'acao' => 'criar', 'entidade' => 'negocios', 'dados' => ['titulo' => 'Site']]);
    $chat->enviar('cria negócio Site', "/empresas/{$emp}");
    igual($emp, (int) Repositorios::negocios()->encontrar(1)['empresa_id']);

    iaResponde(['tipo' => 'acao', 'acao' => 'nota', 'dados' => ['descricao' => 'cliente quer prazo menor']]);
    $r = $chat->enviar('anota que o cliente quer prazo menor', "/empresas/{$emp}");
    contem('Nota registrada em "Padaria Central"', $r['resposta']['conteudo']);

    iaResponde(['tipo' => 'acao', 'acao' => 'tarefa', 'dados' => ['titulo' => 'Ligar', 'vencimento' => '2026-09-25', 'prioridade' => 'Alta']]);
    $t = $chat->enviar('tarefa ligar sexta, prioridade alta', "/empresas/{$emp}");
    contem('vence 25/09/2026', $t['resposta']['conteudo']);
    $tarefa = Repositorios::tarefas()->encontrar(1);
    igual($emp, (int) $tarefa['empresa_id']);
    igual('alta', $tarefa['prioridade']);
});

teste('IA: consultar devolve tabela no chat sem escrever nada', function () {
    $chat = chatComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    novoNegocio($x, ['empresa_id' => $emp, 'etapa_id' => etapaId('Proposta'), 'valor_estimado' => '9.000,00']);
    $antes = contarAuditoria();

    iaResponde(['tipo' => 'acao', 'acao' => 'consultar', 'entidade' => 'negocios', 'filtro' => [['etapa', '=', 'Proposta'], ['valor_estimado', '>', 5000]], 'ordem' => 'previsao_fechamento asc', 'limite' => 20]);
    $r = $chat->enviar('quais negócios em proposta acima de 5 mil?', null);
    $p = ultimoPayload($r);
    igual('consulta', $p['tipo']);
    igual(1, $p['total']);
    igual(['Negócio', 'Empresa', 'Etapa', 'Valor estimado', 'Previsão'], $p['colunas']);
    igual($antes, contarAuditoria());

    $html = chat_mensagem($r['resposta']);
    contem('href="/negocios/1"', $html);
    contem('<table', $html);
});

teste('chat: HTML das mensagens é escapado e history guarda operador e sistema', function () {
    $chat = chatComSeed();
    $r = $chat->enviar('/nota <script>alert(1)</script>', null);
    $html = chat_mensagem($r['operador']) . chat_mensagem($r['resposta']);
    verdadeiro(!str_contains($html, '<script>'), 'script escapado');
    igual(2, count($chat->historico()));
    dispara(InvalidArgumentException::class, fn () => $chat->enviar('   ', null));
    dispara(InvalidArgumentException::class, fn () => $chat->enviar(str_repeat('a', 2001), null));
});

teste('contexto: tela é lida do banco pelo caminho e ignora rotas sem registro', function () {
    bancoComSeed();
    $emp = novaEmpresa(new ActionExecutor(), 'Padaria Central');
    igual(['entidade' => 'empresas', 'id' => $emp, 'nome' => 'Padaria Central'], ContextBuilder::tela("/empresas/{$emp}"));
    igual(['entidade' => 'empresas', 'id' => $emp, 'nome' => 'Padaria Central'], ContextBuilder::tela("/empresas/{$emp}/editar"));
    igual(null, ContextBuilder::tela('/empresas/999'));
    igual(null, ContextBuilder::tela('/tarefas'));
    igual(null, ContextBuilder::tela(null));
});

teste('IA: negócio citado pelo nome da empresa é encontrado (ganho/perda "do cliente X")', function () {
    $chat = chatComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x, 'Clínica Sorriso');
    $n = novoNegocio($x, ['titulo' => 'Site institucional', 'empresa_id' => $emp]);
    iaResponde(['tipo' => 'acao', 'acao' => 'mover_etapa', 'ref' => ['negocio' => 'Clinica Sorriso'], 'dados' => ['etapa' => 'Perdido', 'motivo_perda' => 'Preço']]);
    $r = $chat->enviar('perdi o negócio da Clínica Sorriso por preço', null);
    contem('movido para "Perdido"', $r['resposta']['conteudo']);
    igual('perdido', Repositorios::negocios()->encontrar($n)['status']);
});

teste('chat: só respostas que gravam ou desfazem marcam "alterou" (a tela recarrega)', function () {
    $chat = chatComSeed();
    $emp = novaEmpresa(new ActionExecutor());
    $nota = $chat->enviar('/nota anotação', "/empresas/{$emp}");
    verdadeiro($nota['resposta']['payload']['alterou'] === true, 'nota altera');
    verdadeiro(!isset($chat->enviar('/ajuda', null)['resposta']['payload']['alterou']), 'ajuda não altera');
    verdadeiro(!isset($chat->enviar('/buscar x', null)['resposta']['payload']['alterou']), 'busca não altera');
    $u = $chat->acionar((int) $nota['resposta']['id'], 'desfazer', null);
    verdadeiro($u['resposta']['payload']['alterou'] === true, 'desfazer altera');
    verdadeiro(!isset($chat->enviar('/xyz', null)['resposta']['payload']['alterou']), 'erro não altera');
});

