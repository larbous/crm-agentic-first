<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Repositories\AcaoPendenteRepository;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\IaSaudeRepository;
use App\Repositories\Repositorios;
use App\Services\AI\AgenteDefinicao;
use App\Services\AI\AgentRunner;
use App\Services\AI\Client;
use App\Services\AI\Disjuntor;
use App\Services\AI\IaErro;
use App\Services\AI\ProvedorGemini;
use App\Services\AI\SquadRunner;

// Fase 10 — multi-provedor com failover, disjuntor, Gemini, limiar de confiança e teto por hora.

const HAIKU = 'claude-haiku-4-5-20251001';

/** Transporte da Anthropic: devolve status (200 = texto "ok da anthropic"); conta as chamadas em $GLOBALS['__n_anthropic']. */
function anthropicResponde(int $status, string $texto = 'ok da anthropic'): void
{
    $GLOBALS['__n_anthropic'] = 0;
    Client::definirTransporte(static function (array $req) use ($status, $texto): array {
        $GLOBALS['__n_anthropic']++;
        return [
            'status' => $status,
            'corpo' => $status === 200
                ? json_encode(['content' => [['type' => 'text', 'text' => $texto]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5], 'stop_reason' => 'end_turn'])
                : json_encode(['error' => ['message' => 'falha simulada']]),
            'erro' => $status === 0 ? 'timeout simulado' : null,
        ];
    });
}

/** Transporte do Gemini: guarda a última requisição em $GLOBALS['__req_gemini'] e conta chamadas em __n_gemini. */
function geminiResponde(int $status = 200, array $candidato = ['content' => ['parts' => [['text' => 'ok do gemini']]], 'finishReason' => 'STOP'], string $erro = 'falha simulada'): void
{
    $GLOBALS['__n_gemini'] = 0;
    ProvedorGemini::definirTransporte(static function (array $req) use ($status, $candidato, $erro): array {
        $GLOBALS['__n_gemini']++;
        $GLOBALS['__req_gemini'] = $req;
        return [
            'status' => $status,
            'corpo' => $status === 200
                ? json_encode(['candidates' => [$candidato], 'usageMetadata' => ['promptTokenCount' => 30, 'candidatesTokenCount' => 7, 'thoughtsTokenCount' => 2]])
                : json_encode(['error' => ['message' => $erro]]),
            'erro' => $status === 0 ? 'timeout simulado' : null,
        ];
    });
}

function iaLimpar(): void
{
    Client::definirTransporte(null);
    ProvedorGemini::definirTransporte(null);
}

/** Executa $fn e sempre desfaz os transportes falsos. */
function comIa(callable $fn): void
{
    try {
        $fn();
    } finally {
        iaLimpar();
    }
}

function chamarIa(): \App\Services\AI\RespostaIA
{
    return (new Client())->chamar(HAIKU, 'sistema', 'pergunta', [], 200);
}

// ---- Failover ---------------------------------------------------------------------------------

teste('failover: Anthropic com 5xx (após 1 retentativa) passa para o Gemini e registra provedor, modelo e tentativas', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(503);
        geminiResponde();
        $r = chamarIa();
        igual('ok do gemini', $r->texto);
        igual('gemini', $r->provedor);
        igual(ProvedorGemini::MODELO_RAPIDO, $r->modelo, 'Haiku vira o modelo rápido do Gemini');
        igual(2, $GLOBALS['__n_anthropic'], 'uma retentativa antes de trocar');
        igual(1, $GLOBALS['__n_gemini']);
        igual(30, $r->tokensEntrada);
        igual(9, $r->tokensSaida, 'saída + raciocínio');
        $x = (new ExecucaoRepository())->encontrar($r->execucaoId);
        igual('concluida', $x['status']);
        igual('gemini', $x['provedor']);
        igual(3, (int) $x['tentativas']);
        igual(ProvedorGemini::MODELO_RAPIDO, $x['modelo']);
    });
});

teste('failover: timeout na Anthropic troca de provedor sem repetir a chamada', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(0);
        geminiResponde();
        $r = chamarIa();
        igual('gemini', $r->provedor);
        igual(1, $GLOBALS['__n_anthropic'], 'timeout não é repetido');
    });
});

teste('failover: 429 também troca; modelo de redação (Sonnet) vira o modelo de redação do Gemini, configurável', function () {
    bancoComSeed();
    (new ConfiguracaoRepository())->definir('ia.modelo_gemini_redacao', 'gemini-3-pro-teste');
    comIa(function () {
        anthropicResponde(429);
        geminiResponde();
        $r = (new Client())->chamar('claude-sonnet-5', 'sistema', 'texto', [], 200);
        igual('gemini', $r->provedor);
        igual('gemini-3-pro-teste', $r->modelo);
        igual('gemini-3-pro-teste', $GLOBALS['__req_gemini']['modelo']);
    });
});

teste('failover: 401/403 e 4xx de requisição NÃO trocam de provedor (erro de configuração aparece ao operador)', function () {
    bancoComSeed();
    comIa(function () {
        foreach ([401, 403, 400] as $status) {
            anthropicResponde($status);
            geminiResponde();
            try {
                chamarIa();
                throw new FalhaDeAsserção("HTTP {$status} deveria falhar");
            } catch (IaErro $e) {
                igual(0, $GLOBALS['__n_gemini'], "HTTP {$status} não chama o Gemini");
                verdadeiro(!str_contains($e->getMessage(), 'sk-'));
            }
        }
        anthropicResponde(401);
        try {
            chamarIa();
        } catch (IaErro $e) {
            contem('chave', $e->getMessage());
            contem('anthropic', $e->getMessage());
        }
        $x = DB::conexao()->query('SELECT * FROM execucoes ORDER BY id DESC LIMIT 1')->fetch();
        igual('erro', $x['status']);
        igual('anthropic', $x['provedor']);
    });
});

teste('failover: os dois provedores fora → erro claro, ambos registrados na execução', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(0);
        geminiResponde(500);
        try {
            chamarIa();
            throw new FalhaDeAsserção('deveria falhar');
        } catch (IaErro $e) {
            contem('não respondeu', $e->getMessage() . ' não respondeu'); // mensagem do último provedor (Gemini 5xx = indisponível)
        }
        $x = DB::conexao()->query('SELECT * FROM execucoes ORDER BY id DESC LIMIT 1')->fetch();
        igual('erro', $x['status']);
        contem('anthropic', (string) $x['erro']);
        contem('gemini', (string) $x['erro']);
    });
});

teste('failover: sem Gemini configurado o comportamento é o de sempre (erro, sem trocar)', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(500);
        dispara(IaErro::class, fn () => chamarIa());
        igual(2, $GLOBALS['__n_anthropic']);
    });
});

teste('failover: busca na web só com Anthropic (o Gemini fica de fora e não recebe a chamada)', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(0);
        geminiResponde();
        dispara(IaErro::class, fn () => (new Client())->chamar('claude-sonnet-5', 's', 'u', [], 200, ['web_search' => true]));
        igual(0, $GLOBALS['__n_gemini']);

        // Só o Gemini configurado + busca na web: recusa sem chamar ninguém.
        Client::definirTransporte(null);
        Config::definir(configNeutra());
        try {
            (new Client())->chamar('claude-sonnet-5', 's', 'u', [], 200, ['web_search' => true]);
            throw new FalhaDeAsserção('deveria falhar');
        } catch (IaErro $e) {
            contem('busca na web', $e->getMessage());
        } finally {
            Config::definir(configNeutra());
        }
        igual(0, $GLOBALS['__n_gemini']);
    });
});

teste('failover: ordem dos provedores vem de ia.provedores', function () {
    bancoComSeed();
    (new ConfiguracaoRepository())->definir('ia.provedores', 'gemini,anthropic');
    comIa(function () {
        anthropicResponde(200);
        geminiResponde();
        igual('gemini', chamarIa()->provedor);
        igual(0, $GLOBALS['__n_anthropic']);
    });
});

// ---- Disjuntor --------------------------------------------------------------------------------

teste('disjuntor: 3 falhas seguidas abrem; a chamada seguinte vai direto ao Gemini sem tocar a Anthropic', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(0);
        geminiResponde();
        for ($i = 0; $i < 3; $i++) {
            igual('gemini', chamarIa()->provedor);
        }
        igual(3, $GLOBALS['__n_anthropic']);
        verdadeiro((new Disjuntor())->aberto('anthropic'), 'disjuntor aberto');

        chamarIa();
        igual(3, $GLOBALS['__n_anthropic'], 'aberto: a Anthropic nem é tentada');
        verdadeiro(!(new Disjuntor())->aberto('gemini'));
    });
});

teste('disjuntor: passado o prazo testa de novo; sucesso zera, falha reabre na hora', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(0);
        geminiResponde();
        for ($i = 0; $i < 3; $i++) {
            chamarIa();
        }
        $vencer = static fn () => DB::conexao()->exec("UPDATE ia_saude_provedor SET aberto_ate = '2000-01-01 00:00:00' WHERE provedor = 'anthropic'");

        $vencer();
        verdadeiro(!(new Disjuntor())->aberto('anthropic'), 'prazo vencido = meio aberto');
        chamarIa(); // testa a Anthropic (ainda fora) → falha → reabre
        igual(4, $GLOBALS['__n_anthropic']);
        verdadeiro((new Disjuntor())->aberto('anthropic'), 'falha no meio aberto reabre');

        $vencer();
        anthropicResponde(200);
        igual('anthropic', chamarIa()->provedor);
        $s = (new IaSaudeRepository())->obter('anthropic');
        igual(0, $s['falhas_seguidas']);
        igual(null, $s['aberto_ate']);
    });
});

teste('disjuntor: falhas fora da janela de 5 min não se acumulam; erro de configuração não conta', function () {
    bancoComSeed();
    $d = new Disjuntor();
    $d->registrarFalha('anthropic', 'a');
    $d->registrarFalha('anthropic', 'b');
    DB::conexao()->exec("UPDATE ia_saude_provedor SET primeira_falha_em = '2000-01-01 00:00:00'");
    $d->registrarFalha('anthropic', 'c');
    verdadeiro(!$d->aberto('anthropic'), 'janela velha reinicia a contagem');
    igual(1, (new IaSaudeRepository())->obter('anthropic')['falhas_seguidas']);

    comIa(function () {
        DB::conexao()->exec('DELETE FROM ia_saude_provedor');
        geminiResponde();
        for ($i = 0; $i < 4; $i++) {
            anthropicResponde(401);
            dispara(IaErro::class, fn () => chamarIa());
        }
        verdadeiro(!(new Disjuntor())->aberto('anthropic'), '401 não abre o disjuntor');
    });
});

// ---- Gemini -----------------------------------------------------------------------------------

teste('gemini: monta a requisição (sistema separado, sem raciocínio no Flash) e não põe a chave no corpo', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(0);
        geminiResponde();
        (new ConfiguracaoRepository())->definir('ia.modelo_gemini_rapido', 'gemini-2.5-flash');
        (new Client())->chamar(HAIKU, 'REGRAS DO SISTEMA', 'dados do usuário', [], 321, ['temperatura' => 0.3]);
        $req = $GLOBALS['__req_gemini'];
        igual('REGRAS DO SISTEMA', $req['corpo']['systemInstruction']['parts'][0]['text']);
        igual('dados do usuário', $req['corpo']['contents'][0]['parts'][0]['text']);
        igual(321, $req['corpo']['generationConfig']['maxOutputTokens']);
        igual(0.3, $req['corpo']['generationConfig']['temperature']);
        igual(0, $req['corpo']['generationConfig']['thinkingConfig']['thinkingBudget']);
        verdadeiro(!str_contains(json_encode($req['corpo']), (string) $req['chave']) || $req['chave'] === '', 'chave só no cabeçalho');
    });
});

teste('gemini: modelos 3.x recebem folga para o raciocínio e não levam thinkingBudget; 402 (créditos) aciona o failover', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(402);
        geminiResponde();
        $r = (new Client())->chamar('claude-sonnet-5', 's', 'u', [], 500);
        igual('gemini', $r->provedor);
        igual(ProvedorGemini::MODELO_REDACAO, $GLOBALS['__req_gemini']['modelo']);
        igual(500 + ProvedorGemini::FOLGA_RACIOCINIO, $GLOBALS['__req_gemini']['corpo']['generationConfig']['maxOutputTokens']);
        verdadeiro(!isset($GLOBALS['__req_gemini']['corpo']['generationConfig']['thinkingConfig']));
        igual(1, $GLOBALS['__n_anthropic'], '402 não é repetido');
    });
});

teste('gemini: MAX_TOKENS vira parada max_tokens; resposta bloqueada (sem texto) não dispara failover nem repetição', function () {
    bancoComSeed();
    comIa(function () {
        anthropicResponde(0);
        geminiResponde(200, ['content' => ['parts' => [['text' => 'cortad']]], 'finishReason' => 'MAX_TOKENS']);
        igual('max_tokens', chamarIa()->parada);

        geminiResponde(200, ['finishReason' => 'SAFETY']);
        dispara(IaErro::class, fn () => chamarIa());
        igual(1, $GLOBALS['__n_gemini'], 'sem retentativa em resposta bloqueada');
    });
});

teste('gemini: chave inválida (400 "API key not valid") é tratada como erro de configuração', function () {
    bancoComSeed();
    comIa(function () {
        (new ConfiguracaoRepository())->definir('ia.provedores', 'gemini');
        geminiResponde(400, [], 'API key not valid. Please pass a valid API key.');
        try {
            chamarIa();
            throw new FalhaDeAsserção('deveria falhar');
        } catch (IaErro $e) {
            contem('chave', $e->getMessage());
            contem('gemini', $e->getMessage());
        }
        verdadeiro(!(new Disjuntor())->aberto('gemini'));
    });
});

// ---- Limiar de confiança ---------------------------------------------------------------------

/** Agente de aprovação "nunca" com uma ação; devolve o resultado do runner para a confiança informada. */
function rodarComConfianca(mixed $confianca, array $defExtra = []): array
{
    static $n = 0;
    $empresa = empresaDeTeste(['nome_fantasia' => 'Empresa ' . ++$n, 'cnpj' => null]);
    $agente = agenteNoBanco(['aprovacao' => 'nunca'] + $defExtra);
    $saida = ['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'padaria.com.br']]], 'resumo' => 'x'];
    if ($confianca !== null) {
        $saida['confianca'] = $confianca;
    }
    // Sem confiança: manda o JSON como texto para o auxiliar não injetar o valor padrão.
    iaResponde($confianca === null ? json_encode($saida) : $saida);
    $r = (new AgentRunner())->executar($agente, $empresa);
    $r['empresa'] = $empresa;
    return $r;
}

teste('confiança: acima do corte (0,7) aplica na hora mesmo sem aprovação; a confiança fica registrada na execução', function () {
    bancoComSeed();
    $r = rodarComConfianca(0.9);
    igual(1, count($r['aplicadas']));
    igual([], $r['pendentes']);
    igual('padaria.com.br', Repositorios::empresas()->encontrar($r['empresa'])['site']);
    igual(0.9, (float) (new ExecucaoRepository())->encontrar($r['execucao_id'])['confianca']);
});

teste('confiança: abaixo do corte vai para aprovação com o motivo, mesmo com aprovacao "nunca"; nada é gravado', function () {
    bancoComSeed();
    $r = rodarComConfianca(0.55);
    igual([], $r['aplicadas']);
    igual(1, count($r['pendentes']));
    contem('0,55', $r['pendentes'][0]['motivo']);
    contem('0,70', $r['pendentes'][0]['motivo']);
    igual(null, Repositorios::empresas()->encontrar($r['empresa'])['site']);
    igual('aguardando_aprovacao', (new ExecucaoRepository())->encontrar($r['execucao_id'])['status']);
    $p = (new AcaoPendenteRepository())->pendentes();
    contem('abaixo do mínimo', (string) $p[0]['motivo']);
});

teste('confiança: sem o campo (ou inválido) conta como baixa; vírgula decimal e limites 0–1 são aceitos', function () {
    bancoComSeed();
    $sem = rodarComConfianca(null);
    igual(1, count($sem['pendentes']));
    contem('não informou', $sem['pendentes'][0]['motivo']);
    $lixo = rodarComConfianca('alta');
    igual(1, count($lixo['pendentes']));
    $virgula = rodarComConfianca('0,85');
    igual(1, count($virgula['aplicadas']));
    igual(1, count(rodarComConfianca(7)['aplicadas']), 'acima de 1 é limitado a 1');
});

teste('confiança: corte por agente (confianca_minima) e global (ia.confianca_minima); o do agente vence', function () {
    bancoComSeed();
    igual(1, count(rodarComConfianca(0.5, ['slug' => 'ag-tolerante', 'confianca_minima' => 0.4])['aplicadas']), 'corte do agente 0,4');
    igual(1, count(rodarComConfianca(0.85, ['slug' => 'ag-rigoroso', 'confianca_minima' => 0.95])['pendentes']), 'corte do agente 0,95');

    (new ConfiguracaoRepository())->definir('ia.confianca_minima', '0.9');
    igual(1, count(rodarComConfianca(0.85, ['slug' => 'ag-global'])['pendentes']), 'corte global 0,9');
    igual(1, count(rodarComConfianca(0.85, ['slug' => 'ag-global2', 'confianca_minima' => 0.5])['aplicadas']), 'agente sobrepõe o global');
});

teste('confiança: aprovação "escritas"/"sempre" continua indo para a fila, sem motivo de confiança', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'escritas']);
    iaResponde(['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'a.com.br']]], 'confianca' => 0.2, 'resumo' => 'x']);
    $r = (new AgentRunner())->executar($agente, $empresa);
    igual(1, count($r['pendentes']));
    igual(null, $r['pendentes'][0]['motivo'], 'a configuração do agente já exigia aprovação');
});

teste('confiança: simulação mostra a ação como "não direta" com o motivo', function () {
    bancoComSeed();
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde(['acoes' => [['acao' => 'atualizar', 'entidade' => 'empresas', 'dados' => ['site' => 'a.com.br']]], 'confianca' => 0.3, 'resumo' => 'x']);
    $r = (new AgentRunner())->executar($agente, $empresa, null, true);
    igual(false, $r['previas'][0]['direto']);
    contem('0,30', $r['previas'][0]['motivo']);
});

teste('confiança: o prompt de sistema pede o campo "confianca"; a definição valida confianca_minima', function () {
    bancoComSeed();
    contem('"confianca"', AgentRunner::sistema(defAgente()));
    $ok = AgenteDefinicao::validar(defAgente(['confianca_minima' => 0.8]));
    verdadeiro($ok['ok']);
    igual(0.8, $ok['definicao']['confianca_minima']);
    verdadeiro(!array_key_exists('confianca_minima', AgenteDefinicao::validar(defAgente())['definicao']), 'ausente continua ausente (usa o padrão do sistema)');
    foreach ([1.5, -0.1, 'alto', true] as $ruim) {
        verdadeiro(!AgenteDefinicao::validar(defAgente(['confianca_minima' => $ruim]))['ok'], 'inválido: ' . json_encode($ruim));
    }
});

// ---- Teto por hora ---------------------------------------------------------------------------

teste('teto por hora: agente barrado em qualquer origem (aqui, execução manual) e configurável em ia.limite_hora', function () {
    bancoComSeed();
    (new ConfiguracaoRepository())->definir('ia.limite_hora', '3');
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde(['acoes' => [], 'confianca' => 0.9, 'resumo' => 'ok']);
    $runner = new AgentRunner();
    for ($i = 0; $i < 3; $i++) {
        igual(true, $runner->executar($agente, $empresa)['ok']);
    }
    $chamadas = count($GLOBALS['__ia_requisicoes']);
    try {
        $runner->executar($agente, $empresa);
        throw new FalhaDeAsserção('a 4ª execução deveria ser barrada');
    } catch (InvalidArgumentException $e) {
        contem('limite de 3 execuções por hora', $e->getMessage());
    }
    igual($chamadas, count($GLOBALS['__ia_requisicoes']), 'barrado sem chamar a IA');
});

teste('teto por hora: a execução tirada da fila não conta contra si mesma; a linha da fila é reaproveitada', function () {
    bancoComSeed();
    (new ConfiguracaoRepository())->definir('ia.limite_hora', '2');
    $empresa = empresaDeTeste();
    $agente = agenteNoBanco(['aprovacao' => 'nunca']);
    iaResponde(['acoes' => [], 'confianca' => 0.9, 'resumo' => 'ok']);
    $repo = new ExecucaoRepository();
    $ids = [];
    for ($i = 0; $i < 2; $i++) {
        $ids[] = $repo->enfileirar(['agente_id' => (int) $agente['id'], 'entidade' => 'empresas', 'registro_id' => $empresa]);
    }
    foreach ($ids as $id) {
        igual(true, (new AgentRunner())->executar($agente, $empresa, null, false, ['execucao_id' => $id])['ok'], 'as 2 enfileiradas rodam');
    }
});

teste('teto por hora: squad barrado ao enfileirar; etapas de squad não estouram o teto do agente', function () {
    bancoComSeed();
    iaPorAgente([]);
    agenteDeSquad('ag-a');
    $squad = squadNoBanco();
    $empresa = empresaDeTeste();
    (new ConfiguracaoRepository())->definir('ia.limite_hora', '2');
    $runner = new SquadRunner();
    $runner->enfileirar($squad, $empresa, null, 'humano');
    $runner->enfileirar($squad, $empresa, null, 'humano');
    try {
        $runner->enfileirar($squad, $empresa, null, 'humano');
        throw new FalhaDeAsserção('o 3º deveria ser barrado');
    } catch (InvalidArgumentException $e) {
        contem('limite de 2 execuções por hora', $e->getMessage());
    }
});
