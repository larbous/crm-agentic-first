<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ConversaRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\MensagemRepository;
use App\Services\ActionExecutor;
use App\Services\AI\Client;
use App\Services\AI\IaCreditos;
use App\Services\AI\ProvedorGemini;
use App\Services\AI\Transcritor;
use App\Services\Canais\Meta;
use App\Services\Rotinas;

// Fase 15 — transcrição de áudio (Gemini), alerta de créditos, resumo ao resolver e cadência de follow-up.

/** Roda $fn com o Gemini configurado (chave falsa) e canais da Meta; desfaz transportes e chaves no fim. */
function comGemini(callable $fn, bool $anthropic = false): void
{
    Config::definir(array_replace_recursive(configNeutra(), [
        'gemini' => ['api_key' => 'chave-falsa-gemini'], 'anthropic' => ['api_key' => $anthropic ? 'chave-falsa-anthropic' : ''], 'canais' => CANAIS_META,
    ]));
    try {
        $fn();
    } finally {
        Config::definir(configNeutra());
        Meta::definirTransporte(null);
        ProvedorGemini::definirTransporte(null);
        Client::definirTransporte(null);
    }
}

/** Meta falsa: metadados da mídia (GET no id) e o arquivo (GET na URL). Conta os downloads em $GLOBALS['__n_midia']. */
function metaComAudio(string $mime = 'audio/ogg; codecs=opus', int $statusMetadados = 200): void
{
    $GLOBALS['__n_midia'] = 0;
    Meta::definirTransporte(static function (array $req) use ($mime, $statusMetadados): array {
        if (str_contains($req['url'], 'graph.facebook.com')) {
            return ['status' => $statusMetadados, 'corpo' => json_encode($statusMetadados === 200 ? ['url' => 'https://cdn.falso/audio.ogg', 'mime_type' => $mime, 'file_size' => 12] : ['error' => ['message' => 'Media expired']]), 'erro' => null];
        }
        $GLOBALS['__n_midia']++;
        return ['status' => 200, 'corpo' => 'BYTES-DE-AUDIO', 'erro' => null];
    });
}

/** Gemini falso para transcrição: devolve $json (texto do candidato) ou o status de erro. Guarda a requisição e conta as chamadas. */
function geminiTranscreve(string $json = '', int $status = 200, string $erro = 'falha'): void
{
    $GLOBALS['__n_gemini'] = 0;
    $json = $json !== '' ? $json : json_encode(['transcricao' => 'Oi, queria um orçamento de site.', 'intencao' => 'orcamento', 'sentimento' => 'positivo']);
    ProvedorGemini::definirTransporte(static function (array $req) use ($json, $status, $erro): array {
        $GLOBALS['__n_gemini']++;
        $GLOBALS['__req_gemini'] = $req;
        return [
            'status' => $status,
            'corpo' => $status === 200
                ? json_encode(['candidates' => [['content' => ['parts' => [['text' => $json]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 90, 'candidatesTokenCount' => 20]])
                : json_encode(['error' => ['message' => $erro]]),
            'erro' => null,
        ];
    });
}

/** Cliente com WhatsApp cadastrado que manda um áudio. @return array{0:int,1:int} [contato, mensagem] */
function receberAudio(string $idMsg = 'wamid.AUDIO1', bool $ligado = true): array
{
    $contato = $ligado ? clienteCaixa()[1] : 0;
    $r = chamarWebhook(payloadWhatsApp('5511988887777', $idMsg, ['type' => 'audio', 'audio' => ['id' => 'MID9', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true], 'text' => null]));
    igual(200, $r->status);
    return [$contato, (int) (new MensagemRepository())->porIdExterno('wa:' . $idMsg)['id']];
}

function mensagemPorId(int $id): array
{
    return (new MensagemRepository())->encontrar($id);
}

// ---- Transcrição ---------------------------------------------------------------------------------

teste('transcrição: o áudio recebido entra pendente; o worker baixa a mídia, chama o Gemini com o áudio anexo e grava texto, intenção e sentimento', function () {
    bancoComSeed();
    comGemini(function () {
        [, $id] = receberAudio();
        igual('pendente', mensagemPorId($id)['transcricao_status']);
        igual(null, (new MensagemRepository())->porIdExterno('wa:wamid.AUDIO1')['transcricao']);

        metaComAudio();
        geminiTranscreve();
        $r = (new Rotinas())->executar();
        igual(1, $r['audios_transcritos']);
        igual(1, $GLOBALS['__n_midia'], 'a mídia foi baixada uma vez');
        igual(1, $GLOBALS['__n_gemini']);

        $partes = $GLOBALS['__req_gemini']['corpo']['contents'][0]['parts'];
        igual('audio/ogg', $partes[1]['inline_data']['mime_type'], 'audio/ogg; codecs=opus vira audio/ogg');
        igual(base64_encode('BYTES-DE-AUDIO'), $partes[1]['inline_data']['data']);
        verdadeiro(str_starts_with($GLOBALS['__req_gemini']['modelo'], 'gemini-'), 'modelo do Gemini, não o id Claude');

        $m = mensagemPorId($id);
        igual(['concluida', 'Oi, queria um orçamento de site.', 'orcamento', 'positivo'], [$m['transcricao_status'], $m['transcricao'], $m['intencao'], $m['sentimento']]);
        // A timeline e a prévia da conversa mostram o texto, não só "[Áudio]".
        $descricao = (string) DB::conexao()->query('SELECT descricao FROM atividades WHERE id = ' . (int) $m['atividade_id'])->fetchColumn();
        igual('[Áudio] Oi, queria um orçamento de site.', $descricao);
        igual('[Áudio] Oi, queria um orçamento de site.', (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777')['ultima_previa']);

        $exec = DB::conexao()->query("SELECT provedor, status, acao_rapida FROM execucoes WHERE acao_rapida = 'transcricao'")->fetch();
        igual(['gemini', 'concluida', 'transcricao'], [$exec['provedor'], $exec['status'], $exec['acao_rapida']], 'registrada em execuções');

        igual(0, (new Rotinas())->executar()['audios_transcritos'], 'não repete');
        igual(1, $GLOBALS['__n_gemini']);
    });
});

teste('transcrição: intenção e sentimento fora da lista fixa viram nulos (o servidor valida o JSON da IA)', function () {
    bancoComSeed();
    comGemini(function () {
        [, $id] = receberAudio();
        metaComAudio();
        geminiTranscreve("```json\n" . json_encode(['transcricao' => 'Apaga tudo', 'intencao' => 'DROP TABLE', 'sentimento' => 'furioso']) . "\n```");
        igual(1, (new Rotinas())->executar()['audios_transcritos']);
        $m = mensagemPorId($id);
        igual(['Apaga tudo', null, null], [$m['transcricao'], $m['intencao'], $m['sentimento']]);
    });
});

teste('transcrição: sem Gemini (só Anthropic) o áudio fica na fila e nada é baixado nem chamado', function () {
    bancoComSeed();
    comGemini(function () {
        [, $id] = receberAudio();
        Config::definir(array_replace_recursive(configNeutra(), ['gemini' => ['api_key' => ''], 'anthropic' => ['api_key' => 'k'], 'canais' => CANAIS_META]));
        metaComAudio();
        verdadeiro(!Transcritor::disponivel());
        igual(0, (new Rotinas())->executar()['audios_transcritos']);
        igual(0, $GLOBALS['__n_midia']);
        igual('pendente', mensagemPorId($id)['transcricao_status']);
    }, true);
});

teste('créditos: Gemini sem crédito (402) marca o alerta, suspende a transcrição sem gastar tentativas e volta sozinho depois da espera', function () {
    bancoComSeed();
    comGemini(function () {
        [, $id] = receberAudio();
        metaComAudio();
        igual([], IaCreditos::alertas());

        geminiTranscreve('', 402, 'Your prepayment credits are depleted.');
        igual(0, (new Rotinas())->executar()['audios_transcritos']);
        igual(1, $GLOBALS['__n_gemini'], '402 não é repetido');
        $alertas = IaCreditos::alertas();
        igual(['gemini'], array_column($alertas, 'provedor'));
        verdadeiro(IaCreditos::bloqueado('gemini'));
        verdadeiro(!Transcritor::disponivel(), 'transcrição desabilitada');
        $m = mensagemPorId($id);
        igual(['pendente', 0], [$m['transcricao_status'], (int) $m['transcricao_tentativas']], 'falta de crédito não gasta tentativa');

        // Enquanto bloqueado, nada é chamado: a mídia nem é baixada.
        igual(0, (new Rotinas())->executar()['audios_transcritos']);
        igual(1, $GLOBALS['__n_gemini']);

        // A espera acabou: uma sondagem; o crédito voltou → transcreve e limpa o alerta.
        (new ConfiguracaoRepository())->definir('ia.sem_credito.gemini', json_encode(['desde' => agora(), 'ate' => date('Y-m-d H:i:s', time() - 5)]));
        verdadeiro(!IaCreditos::bloqueado('gemini'));
        verdadeiro(IaCreditos::alertas() !== [], 'o alerta continua até dar certo');
        geminiTranscreve();
        igual(1, (new Rotinas())->executar()['audios_transcritos']);
        igual([], IaCreditos::alertas());
        igual('concluida', mensagemPorId($id)['transcricao_status']);
    });
});

teste('créditos: a sondagem que ainda falha reabre a espera; "já recarreguei" (limpar) libera na hora', function () {
    bancoComSeed();
    comGemini(function () {
        [, $id] = receberAudio();
        metaComAudio();
        $antigo = date('Y-m-d H:i:s', time() - 3600);
        (new ConfiguracaoRepository())->definir('ia.sem_credito.gemini', json_encode(['desde' => $antigo, 'ate' => date('Y-m-d H:i:s', time() - 5)]));
        geminiTranscreve('', 429, 'Your prepayment credits are depleted. Please go to AI Studio.');
        igual(0, (new Rotinas())->executar()['audios_transcritos']);
        verdadeiro(IaCreditos::bloqueado('gemini'), 'espera recomeça');
        igual($antigo, IaCreditos::alertas()[0]['desde'], 'a data de início do problema é mantida');
        igual(0, (int) mensagemPorId($id)['transcricao_tentativas']);

        IaCreditos::limpar('gemini');
        verdadeiro(Transcritor::disponivel());
        igual([], IaCreditos::alertas());
    });
});

teste('créditos: o alerta aparece no topo do layout e o botão "Já recarreguei" limpa', function () {
    bancoComSeed();
    IaCreditos::marcar('gemini');
    $html = App\Core\View::pagina('paginas/nao_encontrado', ['titulo' => 'x'], 404)->corpo;
    contem('data-alerta-creditos="gemini"', $html);
    contem('Sem créditos no Google Gemini', $html);
    contem('transcrição de áudios', $html);
    contem('/ia/creditos/gemini/reativar', $html);
    IaCreditos::limpar('gemini');
    verdadeiro(!str_contains(App\Core\View::pagina('paginas/nao_encontrado', ['titulo' => 'x'], 404)->corpo, 'data-alerta-creditos'), 'sem crédito voltou = sem alerta');
});

teste('créditos: Anthropic com "credit balance is too low" (400) troca para o Gemini e marca só a Anthropic', function () {
    bancoComSeed();
    comGemini(function () {
        Client::definirTransporte(static fn (array $req): array => ['status' => 400, 'corpo' => json_encode(['error' => ['message' => 'Your credit balance is too low to access the Anthropic API.']]), 'erro' => null]);
        geminiTranscreve('texto do gemini');
        $r = (new Client())->chamar('claude-haiku-4-5-20251001', 's', 'u', [], 100);
        igual('gemini', $r->provedor);
        igual(['anthropic'], array_column(IaCreditos::alertas(), 'provedor'));
        // A próxima chamada nem toca a Anthropic (vai para o fim da fila).
        $GLOBALS['__n_anth'] = 0;
        Client::definirTransporte(static function (array $req): array {
            $GLOBALS['__n_anth']++;
            return ['status' => 400, 'corpo' => '{}', 'erro' => null];
        });
        igual('gemini', (new Client())->chamar('claude-haiku-4-5-20251001', 's', 'u', [], 100)->provedor);
        igual(0, $GLOBALS['__n_anth']);
    }, true);
});

teste('transcrição: mídia expirada (404 na Meta) e formato não suportado desistem na hora; falha de rede tenta 3 vezes', function () {
    bancoComSeed();
    comGemini(function () {
        [, $a] = receberAudio('wamid.A');
        metaComAudio('audio/ogg', 404);
        geminiTranscreve();
        (new Rotinas())->executar();
        $m = mensagemPorId($a);
        igual('falhou', $m['transcricao_status']);
        contem('expired', (string) $m['transcricao_erro']);
        igual(0, $GLOBALS['__n_gemini'], 'sem mídia não chama a IA');

        [, $b] = receberAudio('wamid.B');
        metaComAudio('audio/amr');
        (new Rotinas())->executar();
        igual('falhou', mensagemPorId($b)['transcricao_status']);
        contem('não suportado', (string) mensagemPorId($b)['transcricao_erro']);

        [, $c] = receberAudio('wamid.C');
        metaComAudio();
        geminiTranscreve('', 500);
        (new Rotinas())->executar();
        igual(['pendente', 1], [mensagemPorId($c)['transcricao_status'], (int) mensagemPorId($c)['transcricao_tentativas']], 'tenta de novo depois');
        (new Rotinas())->executar();
        (new Rotinas())->executar();
        igual(['falhou', 3], [mensagemPorId($c)['transcricao_status'], (int) mensagemPorId($c)['transcricao_tentativas']]);
    });
});

teste('transcrição: áudio de Instagram baixa pela URL do anexo, sem token', function () {
    bancoComSeed();
    comGemini(function () {
        $payload = ['object' => 'instagram', 'entry' => [['id' => '999888', 'messaging' => [[
            'sender' => ['id' => 'IG42'], 'timestamp' => time() * 1000, 'message' => ['mid' => 'igaud1', 'attachments' => [['type' => 'audio', 'payload' => ['url' => 'https://cdn.instagram.falso/a.mp4']]]],
        ]]]]];
        igual(200, chamarWebhook($payload)->status);
        $id = (int) (new MensagemRepository())->porIdExterno('ig:igaud1')['id'];
        igual('pendente', mensagemPorId($id)['transcricao_status']);

        $visto = [];
        Meta::definirTransporte(static function (array $req) use (&$visto): array {
            $visto[] = [$req['url'], $req['token']];
            return ['status' => 200, 'corpo' => "OggS\x00\x02" . str_repeat("\0", 20) . "\x01\x13OpusHead\x01\x01\x00\x00", 'erro' => null];
        });
        geminiTranscreve();
        igual(1, (new Rotinas())->executar()['audios_transcritos']);
        igual([['https://cdn.instagram.falso/a.mp4', '']], $visto);
        igual('audio/ogg', $GLOBALS['__req_gemini']['corpo']['contents'][0]['parts'][1]['inline_data']['mime_type'], 'tipo identificado pelo conteúdo');
    });
});

teste('transcrição: a conversa ainda sem contato guarda o texto e, ao vincular, a timeline já recebe a transcrição', function () {
    bancoComSeed();
    comGemini(function () {
        [, $id] = receberAudio('wamid.SEMCONTATO', false);
        metaComAudio();
        geminiTranscreve();
        igual(1, (new Rotinas())->executar()['audios_transcritos']);
        igual(null, mensagemPorId($id)['atividade_id']);

        [, $contato] = clienteCaixa();
        $conversa = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        $r = (new ActionExecutor())->vincularConversa((int) $conversa['id'], $contato);
        verdadeiro($r->ok, $r->mensagem);
        $descricao = (string) DB::conexao()->query('SELECT descricao FROM atividades WHERE id = ' . (int) mensagemPorId($id)['atividade_id'])->fetchColumn();
        igual('[Áudio] Oi, queria um orçamento de site.', $descricao);
    });
});

teste('tela: a bolha do áudio mostra a transcrição, a intenção e o sentimento; pendente sem crédito explica o motivo', function () {
    bancoComSeed();
    comGemini(function () {
        [, $id] = receberAudio();
        $m = mensagemPorId($id) + ['canal' => 'whatsapp'];
        contem('Transcrevendo', bolha_mensagem($m));
        IaCreditos::marcar('gemini');
        contem('aguardando', bolha_mensagem($m));
        IaCreditos::limpar('gemini');

        metaComAudio();
        geminiTranscreve();
        (new Rotinas())->executar();
        $html = bolha_mensagem(mensagemPorId($id));
        contem('Oi, queria um orçamento de site.', $html);
        contem('Pedido de orçamento', $html);
        contem('Positivo', $html);
    });
});

// ---- Resumo ao resolver ---------------------------------------------------------------------------

teste('resumo: resolver a conversa enfileira; o worker gera o resumo, guarda na conversa e cria a nota na timeline (origem ia)', function () {
    bancoComSeed();
    comGemini(function () {
        [, $contato] = clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.R1', ['text' => ['body' => 'Preciso de um site novo para a padaria']]));
        (new ActionExecutor())->receberMensagem(['canal' => 'whatsapp', 'identificador' => '5511988887777', 'id_externo' => 'wa:R2', 'tipo' => 'texto', 'texto' => 'Pode ser até sexta?', 'data_hora' => agora()]);
        $conversa = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        $id = (int) $conversa['id'];

        $x = new ActionExecutor();
        igual(0, (int) $conversa['resumo_pendente']);
        verdadeiro($x->mudarStatusConversa($id, 'resolvida')->ok);
        igual(1, (int) (new ConversaRepository())->encontrar($id)['resumo_pendente']);

        $recebido = '';
        Client::definirTransporte(static function (array $req) use (&$recebido): array {
            $recebido = json_encode($req);
            return ['status' => 200, 'corpo' => json_encode(['content' => [['type' => 'text', 'text' => "- Cliente pediu site novo.\n- Próximo passo: enviar proposta."]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 5], 'stop_reason' => 'end_turn']), 'erro' => null];
        });
        Config::definir(array_replace_recursive(configNeutra(), ['anthropic' => ['api_key' => 'k'], 'canais' => CANAIS_META]));
        igual(1, (new Rotinas())->executar()['conversas_resumidas']);
        contem('site novo para a padaria', $recebido);

        $c = (new ConversaRepository())->encontrar($id);
        igual(0, (int) $c['resumo_pendente']);
        contem('Próximo passo', (string) $c['resumo']);
        $nota = DB::conexao()->query("SELECT tipo, assunto, descricao, contato_id, criado_por FROM atividades WHERE tipo = 'nota'")->fetch();
        igual('nota', $nota['tipo']);
        contem('Resumo do atendimento', (string) $nota['assunto']);
        contem('Próximo passo', (string) $nota['descricao']);
        igual([$contato, 'ia'], [(int) $nota['contato_id'], $nota['criado_por']]);

        // A tela da conversa mostra o resumo.
        $tela = (new \App\Controllers\CaixaController())->mostrar(['id' => $id])->corpo;
        contem('Resumo do atendimento', $tela);
        contem('Próximo passo: enviar proposta.', $tela);

        // Resolver de novo sem mensagens novas não pede outro resumo.
        $x->mudarStatusConversa($id, 'aberta');
        $x->mudarStatusConversa($id, 'resolvida');
        igual(0, (int) (new ConversaRepository())->encontrar($id)['resumo_pendente']);
        igual(0, (new Rotinas())->executar()['conversas_resumidas']);
    });
});

teste('resumo: conversa de uma mensagem só não gera resumo; falha da IA tenta 3 vezes e sai da fila', function () {
    bancoComSeed();
    comGemini(function () {
        clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.U1'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        (new ActionExecutor())->mudarStatusConversa((int) $c['id'], 'resolvida');
        igual(0, (int) (new ConversaRepository())->encontrar((int) $c['id'])['resumo_pendente'], 'só uma mensagem: nada a resumir');

        (new ActionExecutor())->receberMensagem(['canal' => 'whatsapp', 'identificador' => '5511988887777', 'id_externo' => 'wa:U2', 'tipo' => 'texto', 'texto' => 'Oi?', 'data_hora' => agora()]);
        (new ActionExecutor())->mudarStatusConversa((int) $c['id'], 'resolvida');
        igual(1, (int) (new ConversaRepository())->encontrar((int) $c['id'])['resumo_pendente']);

        Config::definir(array_replace_recursive(configNeutra(), ['anthropic' => ['api_key' => 'k'], 'canais' => CANAIS_META]));
        Client::definirTransporte(static fn (array $req): array => ['status' => 401, 'corpo' => '{}', 'erro' => null]);
        (new Rotinas())->executar();
        igual(1, (int) (new ConversaRepository())->encontrar((int) $c['id'])['resumo_tentativas']);
        (new Rotinas())->executar();
        (new Rotinas())->executar();
        igual([0, 3], [(int) (new ConversaRepository())->encontrar((int) $c['id'])['resumo_pendente'], (int) (new ConversaRepository())->encontrar((int) $c['id'])['resumo_tentativas']]);
    });
});

// ---- Cadência de follow-up ---------------------------------------------------------------------------

/** Instala o agente de follow-up da biblioteca e cria um negócio aberto cuja última interação foi há $dias dias. @return array{0:int,1:int} [negócio, empresa] */
function negocioParado(int $dias, string $direcao = 'saida'): array
{
    $x = new ActionExecutor();
    $r = $x->salvarAgente((string) file_get_contents(dirname(__DIR__) . '/library/redator-followup.agent.json'));
    verdadeiro($r->ok, $r->mensagem);
    $empresa = (int) $x->criar('empresas', ['nome_fantasia' => 'Padaria Sol'])->id;
    $neg = $x->criar('negocios', ['titulo' => 'Site da padaria', 'empresa_id' => $empresa], 'humano');
    verdadeiro($neg->ok, json_encode($neg->erros));
    $quando = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
    DB::conexao()->exec("UPDATE negocios SET criado_em = '{$quando}' WHERE id = " . (int) $neg->id);
    $a = $x->criar('atividades', ['tipo' => 'whatsapp', 'direcao' => $direcao, 'assunto' => 'Conversa', 'negocio_id' => (int) $neg->id, 'empresa_id' => $empresa, 'data_hora' => $quando]);
    verdadeiro($a->ok, json_encode($a->erros));
    return [(int) $neg->id, $empresa];
}

function filaFollowup(): array
{
    return DB::conexao()->query("SELECT registro_id, entrada FROM execucoes WHERE status = 'fila' AND entidade = 'negocios' ORDER BY id")->fetchAll();
}

teste('follow-up: negócio calado há 3+ dias ganha uma execução do redator-followup, uma por passo da cadência', function () {
    bancoComSeed();
    [$neg] = negocioParado(2);
    igual(0, (new Rotinas())->executar()['followups'], '2 dias ainda não é o primeiro passo');

    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-4 days')) . "'");
    igual(1, (new Rotinas())->executar()['followups']);
    igual([$neg], array_map(static fn (array $l): int => (int) $l['registro_id'], filaFollowup()));
    igual(0, (new Rotinas())->executar()['followups'], 'o passo de 3 dias não se repete');

    // Segue calado: o passo de 7 dias dispara uma vez (a fila anterior é limpa para o teste).
    DB::conexao()->exec("DELETE FROM execucoes");
    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-8 days')) . "'");
    igual(1, (new Rotinas())->executar()['followups']);
    igual(0, (new Rotinas())->executar()['followups']);
});

teste('follow-up: não acompanha quando a última mensagem é do cliente, nem negócio ganho, nem abandonado, nem com o agente desativado', function () {
    bancoComSeed();
    [$neg] = negocioParado(10, 'entrada');
    igual(0, (new Rotinas())->executar()['followups'], 'a bola está com o operador');

    DB::conexao()->exec("UPDATE atividades SET direcao = 'saida'");
    DB::conexao()->exec('UPDATE negocios SET status = \'ganho\'');
    igual(0, (new Rotinas())->executar()['followups'], 'negócio fechado');

    DB::conexao()->exec('UPDATE negocios SET status = \'aberto\'');
    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-90 days')) . "'");
    igual(0, (new Rotinas())->executar()['followups'], 'parado há 90 dias é tido como abandonado');

    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-10 days')) . "'");
    DB::conexao()->exec('UPDATE agentes SET ativo = 0');
    igual(0, (new Rotinas())->executar()['followups'], 'agente desativado desliga a cadência');
    DB::conexao()->exec('UPDATE agentes SET ativo = 1');
    igual(1, (new Rotinas())->executar()['followups']);
});

teste('follow-up: a nota do próprio rascunho não conta como interação e a cadência é configurável', function () {
    bancoComSeed();
    [$neg, $empresa] = negocioParado(10);
    (new ConfiguracaoRepository())->definir('followup.cadencia_dias', '20,30');
    igual(0, (new Rotinas())->executar()['followups'], '10 dias < 20');

    (new ConfiguracaoRepository())->definir('followup.cadencia_dias', '5');
    igual(1, (new Rotinas())->executar()['followups']);
    // O agente grava uma nota agora: o silêncio continua sendo de 10 dias, e o mesmo silêncio não é acompanhado duas vezes.
    (new ActionExecutor())->criar('atividades', ['tipo' => 'nota', 'assunto' => 'Rascunho', 'negocio_id' => $neg, 'empresa_id' => $empresa, 'data_hora' => agora()], 'agente:redator-followup');
    DB::conexao()->exec('DELETE FROM execucoes');
    igual(0, (new Rotinas())->executar()['followups']);
});
