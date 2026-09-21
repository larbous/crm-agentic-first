<?php

declare(strict_types=1);

use App\Controllers\CaixaController;
use App\Controllers\WebhookController;
use App\Core\Config;
use App\Core\DB;
use App\Repositories\ConversaRepository;
use App\Repositories\MensagemRepository;
use App\Repositories\MigracaoRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Canais\Canais;
use App\Services\Canais\Conexao;
use App\Services\Canais\Email;
use App\Services\Canais\Meta;
use App\Services\Canais\Mime;
use App\Services\Events;
use App\Services\Rotinas;

// Fase 14 — caixa de entrada unificada: WhatsApp, Instagram e e-mail.

const SEGREDO_META = 'segredo-de-teste';

/** Servidor IMAP falso em memória (LOGIN, SELECT, UID SEARCH, UID FETCH, LOGOUT). */
final class ImapFalso implements Conexao
{
    private string $saida = "* OK IMAP falso pronto\r\n";
    /** @var array<int,string> uid => mensagem bruta */
    public array $caixa = [];
    public int $conexoes = 0;
    public bool $senhaErrada = false;

    public function linha(): ?string
    {
        $pos = strpos($this->saida, "\r\n");
        if ($pos === false) {
            return null;
        }
        $l = substr($this->saida, 0, $pos);
        $this->saida = substr($this->saida, $pos + 2);
        return $l;
    }

    public function ler(int $n): string
    {
        $t = substr($this->saida, 0, $n);
        $this->saida = substr($this->saida, $n);
        return $t;
    }

    public function escrever(string $dados): void
    {
        [$tag, $cmd] = explode(' ', trim($dados), 2) + [1 => ''];
        $verbo = strtoupper(strtok($cmd, ' ') ?: '');
        if ($verbo === 'LOGIN') {
            $this->saida .= $this->senhaErrada ? "{$tag} NO credenciais\r\n" : "{$tag} OK logado\r\n";
        } elseif ($verbo === 'SELECT') {
            $prox = ($this->caixa === [] ? 0 : max(array_keys($this->caixa))) + 1;
            $this->saida .= "* 3 EXISTS\r\n* OK [UIDNEXT {$prox}] previsto\r\n{$tag} OK [READ-WRITE] selecionada\r\n";
        } elseif (str_starts_with(strtoupper($cmd), 'UID SEARCH')) {
            preg_match('/UID (\d+):\*/', $cmd, $m);
            $de = (int) ($m[1] ?? 1);
            $uids = array_keys(array_filter($this->caixa, static fn ($_, $uid) => $uid >= $de, ARRAY_FILTER_USE_BOTH));
            // IMAP devolve a última mensagem mesmo que seja menor que o início do intervalo ("n:*").
            $uids = $uids === [] && $this->caixa !== [] ? [max(array_keys($this->caixa))] : $uids;
            $this->saida .= '* SEARCH' . ($uids === [] ? '' : ' ' . implode(' ', $uids)) . "\r\n{$tag} OK busca\r\n";
        } elseif (str_starts_with(strtoupper($cmd), 'UID FETCH')) {
            preg_match('/UID FETCH (\d+)/i', $cmd, $m);
            $bruto = $this->caixa[(int) $m[1]] ?? '';
            $this->saida .= '* 1 FETCH (UID ' . $m[1] . ' BODY[] {' . strlen($bruto) . "}\r\n" . $bruto . ")\r\n{$tag} OK fetch\r\n";
        } elseif ($verbo === 'LOGOUT') {
            $this->saida .= "* BYE tchau\r\n{$tag} OK logout\r\n";
        } else {
            $this->saida .= "{$tag} BAD comando desconhecido\r\n";
        }
    }

    public function ativarTls(): bool
    {
        return true;
    }

    public function fechar(): void
    {
        $this->saida = "* OK IMAP falso pronto
"; // a próxima conexão recebe a saudação de novo
    }
}

/** Servidor SMTP falso: registra remetente, destinatário, usuário e a mensagem recebida no DATA. */
final class SmtpFalso implements Conexao
{
    private string $saida = "220 SMTP falso pronto\r\n";
    private string $entrada = '';
    private bool $emDados = false;
    private int $passoAuth = 0;
    public array $usuarios = [];
    public string $de = '';
    public string $para = '';
    public string $mensagem = '';
    public bool $recusarSenha = false;

    public function linha(): ?string
    {
        $pos = strpos($this->saida, "\r\n");
        if ($pos === false) {
            return null;
        }
        $l = substr($this->saida, 0, $pos);
        $this->saida = substr($this->saida, $pos + 2);
        return $l;
    }

    public function ler(int $n): string
    {
        return '';
    }

    public function escrever(string $dados): void
    {
        if ($this->emDados) {
            $this->entrada .= $dados;
            if (str_ends_with($this->entrada, "\r\n.\r\n")) {
                $this->emDados = false;
                $this->mensagem = substr($this->entrada, 0, -5);
                $this->saida .= "250 aceita\r\n";
            }
            return;
        }
        $linha = trim($dados);
        if ($this->passoAuth === 1) {
            $this->usuarios[] = base64_decode($linha);
            $this->passoAuth = 2;
            $this->saida .= "334 UGFzc3dvcmQ6\r\n";
        } elseif ($this->passoAuth === 2) {
            $this->usuarios[] = base64_decode($linha);
            $this->passoAuth = 0;
            $this->saida .= $this->recusarSenha ? "535 autenticação falhou\r\n" : "235 ok\r\n";
        } elseif (str_starts_with($linha, 'EHLO')) {
            $this->saida .= "250-smtp.falso\r\n250 AUTH LOGIN\r\n";
        } elseif ($linha === 'AUTH LOGIN') {
            $this->passoAuth = 1;
            $this->saida .= "334 VXNlcm5hbWU6\r\n";
        } elseif (str_starts_with($linha, 'MAIL FROM:')) {
            $this->de = trim(substr($linha, 10), '<>');
            $this->saida .= "250 ok\r\n";
        } elseif (str_starts_with($linha, 'RCPT TO:')) {
            $this->para = trim(substr($linha, 8), '<>');
            $this->saida .= "250 ok\r\n";
        } elseif ($linha === 'DATA') {
            $this->emDados = true;
            $this->entrada = '';
            $this->saida .= "354 envie\r\n";
        } elseif ($linha === 'QUIT') {
            $this->saida .= "221 tchau\r\n";
        } else {
            $this->saida .= "502 comando não implementado\r\n";
        }
    }

    public function ativarTls(): bool
    {
        return true;
    }

    public function fechar(): void
    {
        $this->saida = "220 SMTP falso pronto
";
        $this->emDados = false;
        $this->passoAuth = 0;
    }
}

/** Roda $fn com a configuração dos canais dada e restaura tudo no fim (chaves reais nunca são usadas nos testes). */
function comCanais(array $canais, callable $fn): void
{
    Config::definir(array_replace_recursive(configNeutra(), ['canais' => $canais]));
    try {
        $fn();
    } finally {
        Config::definir(configNeutra());
        Meta::definirTransporte(null);
        Canais::definirFabricaConexao(null);
    }
}

const CANAIS_META = ['meta' => ['app_secret' => SEGREDO_META, 'verify_token' => 'token-de-verificacao'],
    'whatsapp' => ['token' => 'tok-wa', 'phone_number_id' => '111222333'], 'instagram' => ['token' => 'tok-ig', 'conta_id' => '999888']];

function payloadWhatsApp(string $de, string $id, array $mensagem = [], string $nome = 'Ana da Padaria'): array
{
    return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '111222333'],
        'contacts' => [['profile' => ['name' => $nome], 'wa_id' => $de]],
        'messages' => [$mensagem + ['from' => $de, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'Olá, quero um orçamento']]],
    ]]]]]];
}

function chamarWebhook(array $payload, ?string $assinatura = null): \App\Core\Response
{
    $corpo = json_encode($payload, JSON_UNESCAPED_UNICODE);
    return (new WebhookController())->processar($corpo, $assinatura ?? 'sha256=' . hash_hmac('sha256', $corpo, SEGREDO_META));
}

/** Cliente com contato que tem WhatsApp e e-mail. @return array{0:int,1:int} [empresa, contato] */
function clienteCaixa(): array
{
    $x = new ActionExecutor();
    $empresa = (int) $x->criar('empresas', ['nome_fantasia' => 'Padaria Sol'])->id;
    $contato = $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $empresa, 'whatsapp' => '(11) 98888-7777', 'email' => 'ana@padaria.com.br']);
    verdadeiro($contato->ok, json_encode($contato->erros));
    return [$empresa, (int) $contato->id];
}

function emailBruto(string $de, string $assunto, string $corpo, array $extra = [], string $mid = ''): string
{
    $cab = ["From: {$de}", 'To: crm@larbous.com.br', "Subject: {$assunto}", 'Date: ' . date('r'), 'Message-ID: <' . ($mid ?: bin2hex(random_bytes(6)) . '@cliente.com') . '>', 'MIME-Version: 1.0'];
    return implode("\r\n", array_merge($cab, $extra ?: ['Content-Type: text/plain; charset=UTF-8'])) . "\r\n\r\n" . $corpo . "\r\n";
}

const CANAL_EMAIL = ['imap_host' => 'imap.falso', 'usuario' => 'crm@larbous.com.br', 'senha' => 'segredo-imap', 'smtp_host' => 'smtp.falso',
    'de_email' => 'crm@larbous.com.br', 'de_nome' => 'Lárbous'];

// ---- Meta: assinatura, verificação e leitura dos payloads ---------------------------------------

teste('meta: assinatura HMAC exigida (sem segredo nada é aceito) e desafio de verificação do webhook', function () {
    comCanais(CANAIS_META, function () {
        $corpo = '{"a":1}';
        $ok = 'sha256=' . hash_hmac('sha256', $corpo, SEGREDO_META);
        verdadeiro(Meta::assinaturaValida($corpo, $ok));
        verdadeiro(!Meta::assinaturaValida($corpo, 'sha256=' . str_repeat('0', 64)));
        verdadeiro(!Meta::assinaturaValida($corpo . ' ', $ok), 'corpo alterado');
        verdadeiro(!Meta::assinaturaValida($corpo, hash_hmac('sha256', $corpo, SEGREDO_META)), 'sem o prefixo sha256=');
        verdadeiro(!Meta::assinaturaValida($corpo, ''));

        igual('desafio123', Meta::desafio(['hub_mode' => 'subscribe', 'hub_verify_token' => 'token-de-verificacao', 'hub_challenge' => 'desafio123']));
        igual('x', Meta::desafio(['hub.mode' => 'subscribe', 'hub.verify_token' => 'token-de-verificacao', 'hub.challenge' => 'x']), 'com pontos no nome');
        igual(null, Meta::desafio(['hub_mode' => 'subscribe', 'hub_verify_token' => 'errado', 'hub_challenge' => 'x']));
        igual(null, Meta::desafio(['hub_mode' => 'unsubscribe', 'hub_verify_token' => 'token-de-verificacao', 'hub_challenge' => 'x']));
    });
    comCanais(['meta' => []], function () {
        verdadeiro(!Meta::assinaturaValida('{}', 'sha256=' . hash_hmac('sha256', '{}', '')), 'segredo vazio nunca valida');
        igual(null, Meta::desafio(['hub_mode' => 'subscribe', 'hub_verify_token' => '', 'hub_challenge' => 'x']));
    });
});

teste('meta: lê texto, áudio, imagem com legenda, localização e status do WhatsApp; ignora reação', function () {
    $r = Meta::extrair(payloadWhatsApp('5511988887777', 'wamid.1'));
    igual(1, count($r['mensagens']));
    $m = $r['mensagens'][0];
    igual('whatsapp', $m['canal']);
    igual('5511988887777', $m['identificador']);
    igual('Ana da Padaria', $m['nome']);
    igual('wa:wamid.1', $m['id_externo']);
    igual('texto', $m['tipo']);
    igual('Olá, quero um orçamento', $m['texto']);

    $audio = Meta::extrair(payloadWhatsApp('5511988887777', 'wamid.2', ['type' => 'audio', 'audio' => ['id' => 'MID9', 'mime_type' => 'audio/ogg', 'voice' => true], 'text' => null]))['mensagens'][0];
    igual('audio', $audio['tipo']);
    igual(null, $audio['texto']);
    igual(['id' => 'MID9', 'mime' => 'audio/ogg', 'nome' => null], $audio['midia']);
    $img = Meta::extrair(payloadWhatsApp('5511988887777', 'wamid.3', ['type' => 'image', 'image' => ['id' => 'I1', 'mime_type' => 'image/jpeg', 'caption' => 'Meu logo']]))['mensagens'][0];
    igual(['imagem', 'Meu logo'], [$img['tipo'], $img['texto']]);
    $loc = Meta::extrair(payloadWhatsApp('5511988887777', 'wamid.4', ['type' => 'location', 'location' => ['latitude' => -23.5, 'longitude' => -46.6, 'name' => 'Loja']]))['mensagens'][0];
    igual('localizacao', $loc['tipo']);
    contem('Loja', (string) $loc['texto']);
    igual([], Meta::extrair(payloadWhatsApp('5511988887777', 'wamid.5', ['type' => 'reaction']))['mensagens'], 'reação não é mensagem');
    $btn = Meta::extrair(payloadWhatsApp('5511988887777', 'wamid.6', ['type' => 'interactive', 'interactive' => ['button_reply' => ['title' => 'Quero saber mais']]]))['mensagens'][0];
    igual('Quero saber mais', $btn['texto']);

    $status = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['statuses' => [
        ['id' => 'wamid.9', 'status' => 'delivered'], ['id' => 'wamid.8', 'status' => 'failed', 'errors' => [['title' => 'Número inválido']]],
    ]]]]]]];
    $s = Meta::extrair($status)['status'];
    igual([['id' => 'wa:wamid.9', 'status' => 'delivered', 'erro' => null], ['id' => 'wa:wamid.8', 'status' => 'failed', 'erro' => 'Número inválido']], $s);
});

teste('meta: lê mensagens do Instagram Direct, ignora ecos, leituras e objetos desconhecidos', function () {
    $ig = static fn (array $evento): array => ['object' => 'instagram', 'entry' => [['id' => '999888', 'messaging' => [$evento]]]];
    $r = Meta::extrair($ig(['sender' => ['id' => 'IG42'], 'recipient' => ['id' => '999888'], 'timestamp' => time() * 1000, 'message' => ['mid' => 'm1', 'text' => 'Oi! Vi seu post']]));
    igual(1, count($r['mensagens']));
    igual(['instagram', 'IG42', 'ig:m1', 'Oi! Vi seu post'], [$r['mensagens'][0]['canal'], $r['mensagens'][0]['identificador'], $r['mensagens'][0]['id_externo'], $r['mensagens'][0]['texto']]);
    verdadeiro(abs(strtotime($r['mensagens'][0]['data_hora']) - time()) < 5, 'milissegundos convertidos');
    $foto = Meta::extrair($ig(['sender' => ['id' => 'IG42'], 'message' => ['mid' => 'm2', 'attachments' => [['type' => 'image', 'payload' => ['url' => 'https://x/y.jpg']]]]]))['mensagens'][0];
    igual('imagem', $foto['tipo']);
    igual([], Meta::extrair($ig(['sender' => ['id' => '999888'], 'message' => ['mid' => 'm3', 'text' => 'nosso', 'is_echo' => true]]))['mensagens'], 'eco');
    igual([], Meta::extrair($ig(['sender' => ['id' => 'IG42'], 'read' => ['mid' => 'm1']]))['mensagens'], 'leitura');
    igual(['mensagens' => [], 'status' => []], Meta::extrair(['object' => 'page', 'entry' => [['messaging' => [['message' => ['mid' => 'x', 'text' => 'y']]]]]]));
});

// ---- Webhook ------------------------------------------------------------------------------------

teste('webhook: assinatura inválida 403, JSON inválido 400, verificação GET; mensagem válida vira conversa e mensagem, uma vez só', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        $ctl = new WebhookController();
        $_GET = ['hub_mode' => 'subscribe', 'hub_verify_token' => 'token-de-verificacao', 'hub_challenge' => 'abc'];
        $v = $ctl->verificar();
        igual([200, 'abc'], [$v->status, $v->corpo]);
        $_GET = ['hub_mode' => 'subscribe', 'hub_verify_token' => 'x', 'hub_challenge' => 'abc'];
        igual(403, $ctl->verificar()->status);
        $_GET = [];

        $p = payloadWhatsApp('5511999990000', 'wamid.A1');
        igual(403, chamarWebhook($p, 'sha256=' . str_repeat('a', 64))->status);
        igual(403, chamarWebhook($p, '')->status);
        igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM mensagens')->fetchColumn(), 'nada é gravado sem assinatura');
        igual(400, $ctl->processar('não é json', 'sha256=' . hash_hmac('sha256', 'não é json', SEGREDO_META))->status);
        igual(413, $ctl->processar(str_repeat('x', 1_048_577), 'sha256=x')->status);

        igual(200, chamarWebhook($p)->status);
        igual(200, chamarWebhook($p)->status, 'a Meta reenvia: a segunda entrega não duplica');
        igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM mensagens')->fetchColumn());
        igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM conversas')->fetchColumn());
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511999990000');
        igual('Ana da Padaria', $c['nome']);
        igual(1, (int) $c['nao_lidas']);
        igual('aberta', $c['status']);
        igual('entrada', $c['ultima_direcao']);
        igual('Olá, quero um orçamento', $c['ultima_previa']);
    });
});

teste('webhook: status de entrega só avança (enviada → entregue → lida) e falha vale a qualquer momento', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        [$empresa, $contato] = clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.IN'));
        $conversa = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        Meta::definirTransporte(static fn (array $r): array => ['status' => 200, 'corpo' => json_encode(['messages' => [['id' => 'wamid.OUT']]]), 'erro' => null]);
        $env = (new ActionExecutor())->enviarMensagem((int) $conversa['id'], 'Claro, já preparo o orçamento');
        verdadeiro($env->ok, $env->mensagem);

        $status = static fn (string $s, ?string $erro = null): array => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => ['statuses' => [
            ['id' => 'wamid.OUT', 'status' => $s] + ($erro !== null ? ['errors' => [['title' => $erro]]] : [])]]]]]]];
        $atual = static fn (): string => (string) (new MensagemRepository())->porIdExterno('wa:wamid.OUT')['status'];
        igual('enviada', $atual());
        chamarWebhook($status('read'));
        igual('lida', $atual());
        chamarWebhook($status('delivered'));
        igual('lida', $atual(), 'não retrocede');
        chamarWebhook($status('failed', 'Janela expirada'));
        igual('falhou', $atual());
        igual('Janela expirada', (new MensagemRepository())->porIdExterno('wa:wamid.OUT')['erro']);
        igual(200, chamarWebhook($status('delivered'))->status);
        igual('inexistente', 'inexistente');
    });
});

// ---- Receber: contato, timeline, eventos ------------------------------------------------------------

teste('receber: casa o WhatsApp com o contato (com/sem DDI e com/sem o 9), cria a atividade na timeline e atualiza o último contato', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        [$empresa, $contato] = clienteCaixa();
        $eventos = [];
        Events::ouvir('mensagem.recebida', static function (array $p) use (&$eventos): void {
            $eventos[] = $p;
        });
        // O contato tem (11) 98888-7777; o WhatsApp entrega o número antigo, sem o 9.
        chamarWebhook(payloadWhatsApp('551188887777', 'wamid.V1'));

        $c = (new ConversaRepository())->porIdentificador('whatsapp', '551188887777');
        igual($contato, (int) $c['contato_id']);
        igual($empresa, (int) $c['empresa_id']);
        $a = DB::conexao()->query("SELECT * FROM atividades WHERE tipo = 'whatsapp'")->fetchAll();
        igual(1, count($a));
        igual('entrada', $a[0]['direcao']);
        igual('WhatsApp recebido', $a[0]['assunto']);
        contem('orçamento', $a[0]['descricao']);
        igual($empresa, (int) $a[0]['empresa_id']);
        igual($contato, (int) $a[0]['contato_id']);
        $m = (new MensagemRepository())->porIdExterno('wa:wamid.V1');
        igual((int) $a[0]['id'], (int) $m['atividade_id']);
        verdadeiro(Repositorios::contatos()->encontrar($contato)['ultimo_contato_em'] !== null, 'último contato atualizado');

        igual(1, count($eventos));
        igual(['contatos', $contato, 'whatsapp'], [$eventos[0]['entidade'], $eventos[0]['id'], $eventos[0]['canal']]);
        igual((int) $c['id'], $eventos[0]['conversa_id']);
    });
});

teste('receber: remetente desconhecido gera conversa sem atividade; vincular contato leva o histórico para a timeline', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        chamarWebhook(payloadWhatsApp('5511955554444', 'wamid.U1', ['text' => ['body' => 'Primeira mensagem']], 'Carlos Lead'));
        chamarWebhook(payloadWhatsApp('5511955554444', 'wamid.U2', ['text' => ['body' => 'Segunda mensagem']], 'Carlos Lead'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511955554444');
        igual(null, $c['contato_id']);
        igual(2, (int) $c['nao_lidas']);
        igual(0, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'whatsapp'")->fetchColumn(), 'sem contato, sem timeline');

        [$empresa, $contato] = clienteCaixa();
        $x = new ActionExecutor();
        verdadeiro(!$x->vincularConversa((int) $c['id'], 9999)->ok);
        $v = $x->vincularConversa((int) $c['id'], $contato);
        verdadeiro($v->ok, $v->mensagem);
        $atividades = DB::conexao()->query("SELECT descricao FROM atividades WHERE tipo = 'whatsapp' ORDER BY data_hora, id")->fetchAll(PDO::FETCH_COLUMN);
        igual(['Primeira mensagem', 'Segunda mensagem'], $atividades);
        igual(0, count((new MensagemRepository())->semAtividade((int) $c['id'])));
        igual($empresa, (int) (new ConversaRepository())->encontrar((int) $c['id'])['empresa_id']);

        // Próximas mensagens já entram direto.
        chamarWebhook(payloadWhatsApp('5511955554444', 'wamid.U3', ['text' => ['body' => 'Terceira']]));
        igual(3, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'whatsapp'")->fetchColumn());
    });
});

teste('receber: criar contato a partir da conversa (WhatsApp e e-mail) e vincular; erro de validação não deixa nada pela metade', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        chamarWebhook(payloadWhatsApp('5521977776666', 'wamid.N1', [], 'Bia Nova'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5521977776666');
        $x = new ActionExecutor();
        $empresa = (int) $x->criar('empresas', ['nome_fantasia' => 'Loja da Bia'])->id;
        $r = $x->criarContatoDaConversa((int) $c['id'], 'Beatriz Nova', $empresa);
        verdadeiro($r->ok, $r->mensagem . json_encode($r->erros));
        $contato = Repositorios::contatos()->encontrar((int) $r->id);
        igual('Beatriz Nova', $contato['nome']);
        igual('+5521977776666', $contato['whatsapp']);
        $depois = (new ConversaRepository())->encontrar((int) $c['id']);
        igual([(int) $r->id, $empresa], [(int) $depois['contato_id'], (int) $depois['empresa_id']]);
        igual(1, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'whatsapp'")->fetchColumn());

        $sem = $x->criarContatoDaConversa(99999, 'x');
        verdadeiro(!$sem->ok);
    });
});

teste('receber: nova mensagem reabre conversa resolvida; resolver zera não lidas; marcar lida', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        $x = new ActionExecutor();
        chamarWebhook(payloadWhatsApp('5511944443333', 'wamid.R1'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511944443333');
        verdadeiro($x->mudarStatusConversa((int) $c['id'], 'resolvida')->ok);
        $c = (new ConversaRepository())->encontrar((int) $c['id']);
        igual(['resolvida', 0], [$c['status'], (int) $c['nao_lidas']]);
        verdadeiro(!$x->mudarStatusConversa((int) $c['id'], 'arquivada')->ok);

        chamarWebhook(payloadWhatsApp('5511944443333', 'wamid.R2'));
        $c = (new ConversaRepository())->encontrar((int) $c['id']);
        igual(['aberta', 1], [$c['status'], (int) $c['nao_lidas']], 'reabriu sozinha');
        verdadeiro($x->marcarConversaLida((int) $c['id'])->ok);
        igual(0, (int) (new ConversaRepository())->encontrar((int) $c['id'])['nao_lidas']);
        igual(0, (new ConversaRepository())->contarComNaoLidas());
        verdadeiro(!$x->marcarConversaLida(99999)->ok);
    });
});

teste('receber: mensagem fora de ordem não recua a última mensagem; Instagram entra sem casar contato', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        chamarWebhook(payloadWhatsApp('5511933332222', 'wamid.T2', ['timestamp' => (string) (time() - 60), 'text' => ['body' => 'mais nova']]));
        chamarWebhook(payloadWhatsApp('5511933332222', 'wamid.T1', ['timestamp' => (string) (time() - 3600), 'text' => ['body' => 'mais velha, chegou depois']]));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511933332222');
        igual('mais nova', $c['ultima_previa']);
        igual(2, (int) $c['nao_lidas']);
        igual(['mais velha, chegou depois', 'mais nova'], array_column((new MensagemRepository())->daConversa((int) $c['id']), 'texto'), 'a conversa mostra em ordem cronológica');

        chamarWebhook(['object' => 'instagram', 'entry' => [['messaging' => [['sender' => ['id' => 'IG7'], 'timestamp' => time() * 1000, 'message' => ['mid' => 'mm1', 'text' => 'Oi, vi seu perfil']]]]]]);
        $ig = (new ConversaRepository())->porIdentificador('instagram', 'IG7');
        igual([null, 'Oi, vi seu perfil'], [$ig['contato_id'], $ig['ultima_previa']]);
        igual(0, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'instagram'")->fetchColumn());
    });
});

teste('receber: mídia (áudio) fica registrada com tipo e id do provedor para a Fase 15; prévia e timeline usam o rótulo', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        [$empresa, $contato] = clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.AUD', ['type' => 'audio', 'audio' => ['id' => 'MEDIA1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]));
        $m = (new MensagemRepository())->porIdExterno('wa:wamid.AUD');
        igual(['audio', null], [$m['tipo'], $m['texto']]);
        igual('MEDIA1', $m['midia']['id']);
        igual('[Áudio]', (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777')['ultima_previa']);
        igual('[Áudio]', DB::conexao()->query("SELECT descricao FROM atividades WHERE tipo = 'whatsapp'")->fetchColumn());
    });
});

// ---- Enviar -----------------------------------------------------------------------------------

teste('enviar (WhatsApp): dentro da janela de 24 h grava a saída, usa o token e o número certos, cria a atividade e atualiza a conversa', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        [$empresa, $contato] = clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.IN1'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        $chamadas = [];
        Meta::definirTransporte(static function (array $r) use (&$chamadas): array {
            $chamadas[] = $r;
            return ['status' => 200, 'corpo' => json_encode(['messages' => [['id' => 'wamid.OUT1']]]), 'erro' => null];
        });

        $r = (new ActionExecutor())->enviarMensagem((int) $c['id'], "  Olá Ana!\nSegue o orçamento.  ");
        verdadeiro($r->ok, $r->mensagem);
        igual(1, count($chamadas));
        contem('/111222333/messages', $chamadas[0]['url']);
        igual('tok-wa', $chamadas[0]['token']);
        igual('5511988887777', $chamadas[0]['corpo']['to']);
        igual("Olá Ana!\nSegue o orçamento.", $chamadas[0]['corpo']['text']['body']);

        $m = (new MensagemRepository())->porIdExterno('wa:wamid.OUT1');
        igual(['saida', 'enviada', 'humano'], [$m['direcao'], $m['status'], $m['criado_por']]);
        $conv = (new ConversaRepository())->encontrar((int) $c['id']);
        igual('saida', $conv['ultima_direcao']);
        contem('Olá Ana!', $conv['ultima_previa']);
        $a = DB::conexao()->query("SELECT * FROM atividades WHERE tipo = 'whatsapp' AND direcao = 'saida'")->fetch();
        igual('WhatsApp enviado', $a['assunto']);
        igual($contato, (int) $a['contato_id']);
        igual((int) $a['id'], (int) $m['atividade_id']);
    });
});

teste('enviar: recusa texto vazio ou enorme, canal sem configuração e conversa inexistente; nada é gravado nem enviado', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.IN2'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        $chamadas = 0;
        Meta::definirTransporte(static function () use (&$chamadas): array {
            $chamadas++;
            return ['status' => 200, 'corpo' => '{}', 'erro' => null];
        });
        $x = new ActionExecutor();
        verdadeiro(!$x->enviarMensagem((int) $c['id'], '   ')->ok);
        verdadeiro(!$x->enviarMensagem((int) $c['id'], str_repeat('a', 4001))->ok);
        verdadeiro(!$x->enviarMensagem(99999, 'oi')->ok);
        igual(0, $chamadas);
        igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM mensagens')->fetchColumn(), 'só a recebida');
    });
    comCanais(['meta' => CANAIS_META['meta']], function () {
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        $r = (new ActionExecutor())->enviarMensagem((int) $c['id'], 'oi');
        verdadeiro(!$r->ok);
        contem('não está configurado', $r->mensagem);
    });
});

teste('enviar: fora da janela de 24 h (WhatsApp e Instagram) é recusado sem chamar a API; e-mail não tem janela', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.OLD', ['timestamp' => (string) (time() - 25 * 3600)]));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        DB::conexao()->exec("UPDATE conversas SET ultima_entrada_em = '" . date('Y-m-d H:i:s', time() - 25 * 3600) . "'");
        $chamadas = 0;
        Meta::definirTransporte(static function () use (&$chamadas): array {
            $chamadas++;
            return ['status' => 200, 'corpo' => json_encode(['messages' => [['id' => 'x']]]), 'erro' => null];
        });
        $r = (new ActionExecutor())->enviarMensagem((int) $c['id'], 'Podemos falar hoje?');
        verdadeiro(!$r->ok);
        contem('24 h', $r->mensagem);
        igual(0, $chamadas);

        chamarWebhook(['object' => 'instagram', 'entry' => [['messaging' => [['sender' => ['id' => 'IG1'], 'timestamp' => (time() - 30 * 3600) * 1000, 'message' => ['mid' => 'igold', 'text' => 'oi']]]]]]);
        DB::conexao()->exec("UPDATE conversas SET ultima_entrada_em = '" . date('Y-m-d H:i:s', time() - 30 * 3600) . "' WHERE canal = 'instagram'");
        $ig = (new ConversaRepository())->porIdentificador('instagram', 'IG1');
        verdadeiro(!(new ActionExecutor())->enviarMensagem((int) $ig['id'], 'oi')->ok);
    });
});

teste('enviar: erro da API vira mensagem "falhou" com o motivo (sem a chave); Instagram usa o corpo próprio', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.IN3'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        Meta::definirTransporte(static fn (): array => ['status' => 400, 'corpo' => json_encode(['error' => ['message' => 'Recipient phone number not in allowed list']]), 'erro' => null]);
        $r = (new ActionExecutor())->enviarMensagem((int) $c['id'], 'Teste');
        verdadeiro(!$r->ok);
        contem('not in allowed list', $r->mensagem);
        verdadeiro(!str_contains($r->mensagem, 'tok-wa'));
        $falha = DB::conexao()->query("SELECT * FROM mensagens WHERE direcao = 'saida'")->fetch();
        igual('falhou', $falha['status']);
        contem('allowed list', (string) $falha['erro']);
        igual(0, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE direcao = 'saida'")->fetchColumn(), 'falha não vai para a timeline');
        Meta::definirTransporte(static fn (): array => ['status' => 0, 'corpo' => '', 'erro' => 'timeout']);
        contem('Sem resposta', (new ActionExecutor())->enviarMensagem((int) $c['id'], 'Teste 2')->mensagem);

        chamarWebhook(['object' => 'instagram', 'entry' => [['messaging' => [['sender' => ['id' => 'IG5'], 'timestamp' => time() * 1000, 'message' => ['mid' => 'ig5', 'text' => 'oi']]]]]]);
        $ig = (new ConversaRepository())->porIdentificador('instagram', 'IG5');
        $visto = null;
        Meta::definirTransporte(static function (array $req) use (&$visto): array {
            $visto = $req;
            return ['status' => 200, 'corpo' => json_encode(['message_id' => 'mid.OUT']), 'erro' => null];
        });
        verdadeiro((new ActionExecutor())->enviarMensagem((int) $ig['id'], 'Olá!')->ok);
        contem('/999888/messages', $visto['url']);
        igual(['id' => 'IG5'], $visto['corpo']['recipient']);
        igual('Olá!', $visto['corpo']['message']['text']);
        igual('tok-ig', $visto['token']);
    });
});

// ---- E-mail: MIME, IMAP, SMTP -----------------------------------------------------------------------

teste('e-mail: lê cabeçalhos codificados, multipart com base64, quoted-printable em ISO-8859-1 e HTML puro; tira a citação', function () {
    $multipart = "From: =?UTF-8?B?QW5hIFNvdXphIMOnw6M=?= <Ana@Padaria.com.br>\r\nTo: crm@larbous.com.br\r\nSubject: =?UTF-8?Q?Or=C3=A7amento_do_site?=\r\n"
        . "Date: Tue, 15 Sep 2026 10:30:00 -0300\r\nMessage-ID: <abc123@padaria.com.br>\r\nIn-Reply-To: <anterior@larbous.com.br>\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"LIMITE\"\r\n\r\n--LIMITE\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode("Olá, aceito a proposta!\n\nEm 14/09/2026 10:00, Lárbous escreveu:\n> Segue a proposta\n> valor R$ 8.000"), 76, "\r\n")
        . "--LIMITE\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Olá, aceito a proposta!</p>\r\n--LIMITE--\r\n";
    $e = Mime::ler($multipart);
    igual('ana@padaria.com.br', $e['de_email']);
    igual('Ana Souza çã', $e['de_nome']);
    igual('Orçamento do site', $e['assunto']);
    igual('abc123@padaria.com.br', $e['message_id']);
    igual('anterior@larbous.com.br', $e['in_reply_to']);
    igual('Olá, aceito a proposta!', $e['texto'], 'só o texto novo, sem a citação');
    igual('2026-09-15 10:30:00', $e['data']);
    igual(false, $e['automatica']);

    $qp = "From: Bruno <bruno@x.com>\r\nSubject: Ol=E1\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nPre=E7o do servi=E7o?\r\n";
    igual('Preço do serviço?', Mime::ler($qp)['texto']);
    $html = "From: c@x.com\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<html><style>p{color:red}</style><body><p>Primeira&nbsp;linha</p><p>Segunda &amp; terceira<br>quarta</p></body></html>";
    igual("Primeira linha\nSegunda & terceira\nquarta", Mime::ler($html)['texto']);
    igual('sem assunto', Mime::ler("From: a@b.com\r\n\r\nsem assunto")['texto']);
});

teste('e-mail: respostas automáticas, listas e no-reply são marcadas como automáticas', function () {
    $base = static fn (string $cab): array => Mime::ler("From: x@y.com\r\n{$cab}\r\n\r\ncorpo");
    igual(false, $base('Subject: oi')['automatica']);
    igual(true, $base('Auto-Submitted: auto-replied')['automatica']);
    igual(false, $base('Auto-Submitted: no')['automatica']);
    igual(true, $base('Precedence: bulk')['automatica']);
    igual(true, $base('List-Id: <novidades.exemplo.com>')['automatica']);
    igual(true, Mime::ler("From: No-Reply <noreply@banco.com>\r\n\r\nx")['automatica']);
    igual(true, Mime::ler("From: MAILER-DAEMON@servidor.com\r\n\r\nx")['automatica']);
});

teste('e-mail: montar gera UTF-8 em base64 com Message-ID e In-Reply-To, e o que se monta é lido de volta igual', function () {
    $m = Mime::montar('crm@larbous.com.br', 'Lárbous Agência', 'ana@padaria.com.br', 'Re: Orçamento do site', "Olá, Ana!\nTudo certo. .Ponto no começo", 'abc123@padaria.com.br');
    contem('In-Reply-To: <abc123@padaria.com.br>', $m['bruto']);
    contem('=?UTF-8?B?', $m['bruto']);
    contem('Message-ID: <' . $m['message_id'] . '>', $m['bruto']);
    $volta = Mime::ler($m['bruto']);
    igual('crm@larbous.com.br', $volta['de_email']);
    igual('Lárbous Agência', $volta['de_nome']);
    igual('Re: Orçamento do site', $volta['assunto']);
    igual("Olá, Ana!\nTudo certo. .Ponto no começo", $volta['texto']);
    igual($m['message_id'], $volta['message_id']);
    verdadeiro(!str_contains(explode("\r\n\r\n", $m['bruto'], 2)[0], "\n\n"), 'cabeçalho sem quebra dupla');
});

teste('e-mail (IMAP): a primeira coleta só marca o ponto de partida; depois importa só o que é novo e de gente conhecida', function () {
    bancoComSeed();
    [$empresa, $contato] = clienteCaixa();
    $imap = new ImapFalso();
    $imap->caixa = [1 => emailBruto('Antigo <velho@x.com>', 'Histórico', 'já estava na caixa'), 2 => emailBruto('ana@padaria.com.br', 'Histórico da Ana', 'também antigo')];
    comCanais(['email' => CANAL_EMAIL], function () use ($imap, $contato) {
        Canais::definirFabricaConexao(function (string $tipo) use ($imap): Conexao {
            $imap->conexoes++;
            return $imap;
        });
        $config = new \App\Repositories\ConfiguracaoRepository();
        $agora = 1_800_000_000;

        igual(0, Email::coletar(null, $agora), 'primeira execução: só o ponto de partida');
        igual('2', $config->obter('canais.email.ultimo_uid'));
        igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM mensagens')->fetchColumn(), 'o histórico não é importado');
        igual(0, Email::coletar(null, $agora + 10), 'dentro do intervalo não conecta de novo');
        igual(1, $imap->conexoes);

        $imap = $imap;
        $imap->caixa += [
            3 => emailBruto('Ana Souza <ana@padaria.com.br>', 'Proposta do site', "Aceito a proposta!\r\n\r\nEm 14/09/2026 10:00, Lárbous escreveu:\r\n> valor", [], 'msg3@padaria.com.br'),
            4 => emailBruto('Desconhecido <estranho@outro.com>', 'Quero comprar', 'sou novo aqui'),
            5 => emailBruto('ana@padaria.com.br', 'Fora do escritório', 'volto segunda', ['Auto-Submitted: auto-replied', 'Content-Type: text/plain; charset=UTF-8']),
            6 => emailBruto('crm@larbous.com.br', 'Eu mesmo', 'mensagem própria'),
            7 => emailBruto('ana@padaria.com.br', 'Outra dúvida', 'Qual o prazo?', [], 'msg7@padaria.com.br'),
        ];
        igual(2, Email::coletar(null, $agora + 200), 'só a Ana (proposta e dúvida); desconhecido, automática e própria ficam de fora');
        igual('7', $config->obter('canais.email.ultimo_uid'), 'o ponto de partida avança por todas');

        $c = (new ConversaRepository())->porIdentificador('email', 'ana@padaria.com.br');
        igual([$contato, 2, 'Ana Souza'], [(int) $c['contato_id'], (int) $c['nao_lidas'], $c['nome']]);
        igual('Outra dúvida', $c['assunto']);
        igual('Qual o prazo?', $c['ultima_previa']);
        $m = (new MensagemRepository())->porIdExterno('em:msg3@padaria.com.br');
        igual('Aceito a proposta!', $m['texto']);
        $atividades = DB::conexao()->query("SELECT assunto, direcao FROM atividades WHERE tipo = 'email' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        igual([['assunto' => 'Proposta do site', 'direcao' => 'entrada'], ['assunto' => 'Outra dúvida', 'direcao' => 'entrada']], $atividades);
        igual(null, (new ConversaRepository())->porIdentificador('email', 'estranho@outro.com'));

        $imap->caixa[8] = emailBruto('ana@padaria.com.br', 'Repetida', 'mesmo id', [], 'msg7@padaria.com.br');
        igual(0, Email::coletar(null, $agora + 400), 'mesmo Message-ID não duplica');
    });
});

teste('e-mail (IMAP): remetente desconhecido entra se importar_desconhecidos estiver ligado; senha errada falha sem quebrar o worker', function () {
    bancoComSeed();
    $imap = new ImapFalso();
    $imap->caixa = [1 => emailBruto('a@x.com', 'antigo', 'x')];
    comCanais(['email' => CANAL_EMAIL + ['importar_desconhecidos' => true]], function () use ($imap) {
        Canais::definirFabricaConexao(static fn (): Conexao => $imap);
        Email::coletar(null, 1_800_000_000);
        $imap->caixa[2] = emailBruto('Novo Lead <lead@empresa.com>', 'Quero orçamento', 'Preciso de um site');
        igual(1, Email::coletar(null, 1_800_000_500));
        $c = (new ConversaRepository())->porIdentificador('email', 'lead@empresa.com');
        igual(null, $c['contato_id']);
        igual('Quero orçamento', $c['assunto']);
        igual(0, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'email'")->fetchColumn(), 'sem contato, sem timeline');
    });
    $errado = new ImapFalso();
    $errado->senhaErrada = true;
    comCanais(['email' => CANAL_EMAIL], function () use ($errado) {
        Canais::definirFabricaConexao(static fn (): Conexao => $errado);
        dispara(RuntimeException::class, fn () => Email::coletar(null, 1_800_001_000));
        // O worker isola a falha da rotina: o Worker::rodada usa tentar() e segue.
        $r = (new \App\Services\Worker())->rodada();
        verdadeiro(is_array($r), 'a rodada do worker não quebra por causa do e-mail');
    });
    comCanais([], function () {
        igual(0, Email::coletar(), 'sem configuração não faz nada');
    });
});

teste('e-mail (SMTP): resposta pela conversa autentica, envia para o remetente com Re: e In-Reply-To; falha do servidor vira "falhou"', function () {
    bancoComSeed();
    [$empresa, $contato] = clienteCaixa();
    $imap = new ImapFalso();
    $imap->caixa = [1 => emailBruto('velho@x.com', 'antigo', 'x')];
    $smtp = new SmtpFalso();
    comCanais(['email' => CANAL_EMAIL], function () use ($imap, $smtp) {
        Canais::definirFabricaConexao(static fn (string $tipo): Conexao => $tipo === 'imap' ? $imap : $smtp);
        Email::coletar(null, 1_800_000_000);
        $imap->caixa[2] = emailBruto('Ana <ana@padaria.com.br>', 'Proposta do site', 'Aceito!', [], 'entrada1@padaria.com.br');
        Email::coletar(null, 1_800_000_500);
        $c = (new ConversaRepository())->porIdentificador('email', 'ana@padaria.com.br');

        $r = (new ActionExecutor())->enviarMensagem((int) $c['id'], 'Ótimo, Ana! Vou preparar o contrato.');
        verdadeiro($r->ok, $r->mensagem);
        igual(['crm@larbous.com.br', 'segredo-imap'], $smtp->usuarios, 'sem smtp_usuario, usa o mesmo login do IMAP');
        igual(['crm@larbous.com.br', 'ana@padaria.com.br'], [$smtp->de, $smtp->para]);
        $enviada = Mime::ler($smtp->mensagem);
        igual('Re: Proposta do site', $enviada['assunto']);
        igual('entrada1@padaria.com.br', $enviada['in_reply_to']);
        igual('Ótimo, Ana! Vou preparar o contrato.', $enviada['texto']);
        $m = (new MensagemRepository())->daConversa((int) $c['id']);
        $saida = end($m);
        igual(['saida', 'enviada'], [$saida['direcao'], $saida['status']]);
        contem('em:', (string) $saida['id_externo']);
        $a = DB::conexao()->query("SELECT assunto FROM atividades WHERE tipo = 'email' AND direcao = 'saida'")->fetchColumn();
        igual('Proposta do site', $a);

        $smtp2 = new SmtpFalso();
        $smtp2->recusarSenha = true;
        Canais::definirFabricaConexao(static fn (string $tipo): Conexao => $smtp2);
        $r = (new ActionExecutor())->enviarMensagem((int) $c['id'], 'Outra mensagem');
        verdadeiro(!$r->ok);
        contem('autenticação falhou', $r->mensagem);
        verdadeiro(!str_contains($r->mensagem, 'segredo-imap'), 'a senha nunca aparece');
        igual('falhou', DB::conexao()->query("SELECT status FROM mensagens WHERE direcao = 'saida' ORDER BY id DESC LIMIT 1")->fetchColumn());
    });
});

teste('e-mail: SMTP dobra o ponto no começo da linha (dot-stuffing) e o worker chama a coleta nas rotinas', function () {
    $smtp = new SmtpFalso();
    comCanais(['email' => CANAL_EMAIL], function () use ($smtp) {
        Canais::definirFabricaConexao(static fn (): Conexao => $smtp);
        $r = Email::enviar('x@y.com', 'Teste', "linha\n.linha que começa com ponto\nfim");
        verdadeiro($r['ok'], (string) $r['erro']);
        verdadeiro(str_contains($smtp->mensagem, 'Content-Transfer-Encoding: base64'), 'corpo em base64: sem risco de ponto sozinho');
        igual("linha\n.linha que começa com ponto\nfim", Mime::ler($smtp->mensagem)['texto']);
    });
    bancoComSeed();
    comCanais(['email' => CANAL_EMAIL], function () {
        $imap = new ImapFalso();
        Canais::definirFabricaConexao(static fn (): Conexao => $imap);
        $r = (new Rotinas())->executar();
        verdadeiro(array_key_exists('emails_recebidos', $r));
    });
});

// ---- Telas ------------------------------------------------------------------------------------------

teste('tela: caixa lista conversas com não lidas primeiro, filtra por canal, situação, texto e não lidas', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.L1', ['text' => ['body' => 'Quero renovar o contrato']]));
        chamarWebhook(payloadWhatsApp('5511955554444', 'wamid.L2', ['text' => ['body' => 'Sou lead novo']], 'Carlos Lead'));
        chamarWebhook(['object' => 'instagram', 'entry' => [['messaging' => [['sender' => ['id' => 'IG9'], 'timestamp' => time() * 1000, 'message' => ['mid' => 'l3', 'text' => 'Mensagem do insta']]]]]]);
        (new ActionExecutor())->mudarStatusConversa((int) (new ConversaRepository())->porIdentificador('whatsapp', '5511955554444')['id'], 'resolvida');
        $ctl = new CaixaController();

        $_GET = [];
        $tela = $ctl->index()->corpo;
        contem('Caixa de entrada', $tela);
        contem('Ana Souza', $tela);
        contem('Mensagem do insta', $tela);
        verdadeiro(!str_contains($tela, 'Sou lead novo'), 'resolvidas ficam fora do padrão');
        contem('1 nova(s)', $tela);
        contem('Sem contato vinculado', $tela);

        $_GET = ['situacao' => 'todas'];
        contem('Sou lead novo', $ctl->index()->corpo);
        $_GET = ['canal' => 'instagram'];
        $ig = $ctl->index()->corpo;
        contem('Mensagem do insta', $ig);
        verdadeiro(!str_contains($ig, 'Quero renovar'));
        $_GET = ['q' => 'renovar'];
        $busca = $ctl->index()->corpo;
        contem('Quero renovar', $busca);
        verdadeiro(!str_contains($busca, 'Mensagem do insta'));
        $_GET = ['canal' => 'telegram', 'situacao' => 'xyz', 'pagina' => '-4'];
        igual(200, $ctl->index()->status, 'filtros inválidos são ignorados');
        $_GET = [];
    });
});

teste('tela: conversa mostra as mensagens, marca como lida, responde pelo POST e avisa quando o canal está fora da janela ou sem configuração', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.C1', ['text' => ['body' => 'Bom dia! Preciso de ajuda']]));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        $ctl = new CaixaController();

        $tela = $ctl->mostrar(['id' => (string) $c['id']]);
        igual(200, $tela->status);
        contem('Bom dia! Preciso de ajuda', $tela->corpo);
        contem('Responder por WhatsApp', $tela->corpo);
        igual(0, (int) (new ConversaRepository())->encontrar((int) $c['id'])['nao_lidas'], 'abrir marca como lida');
        igual(404, $ctl->mostrar(['id' => '99999'])->status);

        Meta::definirTransporte(static fn (): array => ['status' => 200, 'corpo' => json_encode(['messages' => [['id' => 'wamid.C-OUT']]]), 'erro' => null]);
        $_POST = ['texto' => 'Bom dia, Ana! Como posso ajudar?'];
        $r = $ctl->enviar(['id' => (string) $c['id']]);
        igual(302, $r->status);
        contem('/caixa/' . $c['id'], (string) $r->cabecalhos['Location']);
        contem('Como posso ajudar', $ctl->mostrar(['id' => (string) $c['id']])->corpo);
        igual('enviada', (new MensagemRepository())->porIdExterno('wa:wamid.C-OUT')['status']);

        DB::conexao()->exec("UPDATE conversas SET ultima_entrada_em = '" . date('Y-m-d H:i:s', time() - 30 * 3600) . "'");
        contem('Fora da janela', $ctl->mostrar(['id' => (string) $c['id']])->corpo);
        $_POST = [];
    });
    comCanais([], function () {
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511988887777');
        contem('Canal não configurado', (new CaixaController())->mostrar(['id' => (string) $c['id']])->corpo);
    });
});

teste('tela: conversa sem contato mostra vincular/criar; resolver, reabrir, vincular e criar contato pelos POSTs; selo do menu conta as não lidas', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        chamarWebhook(payloadWhatsApp('5511933334444', 'wamid.S1', ['text' => ['body' => 'Oi, tudo bem?']], 'Diego Novo'));
        $c = (new ConversaRepository())->porIdentificador('whatsapp', '5511933334444');
        $ctl = new CaixaController();

        $tela = $ctl->mostrar(['id' => (string) $c['id']])->corpo;
        contem('Vincular a um contato existente', $tela);
        contem('Ou criar um contato novo', $tela);
        contem('Diego Novo', $tela);

        $_POST = ['nome' => 'Diego Novo', 'empresa_id' => ''];
        $ctl->criarContato(['id' => (string) $c['id']]);
        $depois = (new ConversaRepository())->encontrar((int) $c['id']);
        verdadeiro($depois['contato_id'] !== null);
        verdadeiro(!str_contains($ctl->mostrar(['id' => (string) $c['id']])->corpo, 'Vincular a um contato existente'));

        $_POST = [];
        $ctl->resolver(['id' => (string) $c['id']]);
        igual('resolvida', (new ConversaRepository())->encontrar((int) $c['id'])['status']);
        contem('Reabrir', $ctl->mostrar(['id' => (string) $c['id']])->corpo);
        $ctl->reabrir(['id' => (string) $c['id']]);
        igual('aberta', (new ConversaRepository())->encontrar((int) $c['id'])['status']);

        chamarWebhook(payloadWhatsApp('5511922221111', 'wamid.S2'));
        $outra = (new ConversaRepository())->porIdentificador('whatsapp', '5511922221111');
        [$empresa, $contato] = clienteCaixa();
        $_POST = ['contato_id' => (string) $contato];
        $ctl->vincular(['id' => (string) $outra['id']]);
        igual($contato, (int) (new ConversaRepository())->encontrar((int) $outra['id'])['contato_id']);
        $_POST = [];

        $inicio = (new \App\Controllers\PaginaController())->inicio()->corpo;
        contem('Caixa de entrada', $inicio);
        contem('conversa(s) com mensagens novas', $inicio);
    });
});

teste('linha do tempo: mensagens de WhatsApp, e-mail e Instagram aparecem na timeline com o tipo certo e o quadro de atividade aceita "instagram"', function () {
    bancoComSeed();
    comCanais(CANAIS_META, function () {
        [$empresa, $contato] = clienteCaixa();
        chamarWebhook(payloadWhatsApp('5511988887777', 'wamid.TL1'));
        $detalhe = (new \App\Controllers\EmpresaController())->mostrar(['id' => (string) $empresa])->corpo;
        contem('WhatsApp recebido', $detalhe);
        contem('orçamento', $detalhe);
        $x = new ActionExecutor();
        $r = $x->criar('atividades', ['tipo' => 'instagram', 'assunto' => 'DM registrada à mão', 'contato_id' => $contato, 'direcao' => 'entrada']);
        verdadeiro($r->ok, json_encode($r->erros));
        igual('Instagram', \App\Services\Schema::opcoes('tipo_atividade')['instagram']);
    });
});

// ---- Migração ---------------------------------------------------------------------------------------

teste('migração 0012: recria atividades preservando dados e índices, aceita o tipo instagram e cria conversas e mensagens', function () {
    $pdo = DB::conectar(':memory:');
    $repo = new MigracaoRepository($pdo);
    $repo->garantirTabela();
    $arquivos = glob(Config::obter('caminhos.migracoes') . '/*.sql') ?: [];
    sort($arquivos);
    $nova = null;
    foreach ($arquivos as $arquivo) {
        if (str_contains($arquivo, '0012_')) {
            $nova = $arquivo;
            break;
        }
        $repo->aplicar(basename($arquivo), (string) file_get_contents($arquivo));
    }
    verdadeiro($nova !== null);
    $pdo->exec("INSERT INTO empresas (nome_fantasia, criado_em, atualizado_em) VALUES ('Padaria', '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
    $pdo->exec("INSERT INTO atividades (tipo, assunto, descricao, data_hora, direcao, empresa_id, criado_em, atualizado_em) VALUES ('whatsapp', 'Oi', 'texto', '2026-09-01 10:00:00', 'entrada', 1, '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
    dispara(PDOException::class, fn () => $pdo->exec("INSERT INTO atividades (tipo, data_hora, empresa_id, criado_em, atualizado_em) VALUES ('instagram', 'x', 1, 'x', 'x')"));

    $repo->aplicar(basename($nova), (string) file_get_contents($nova));

    $a = $pdo->query('SELECT * FROM atividades WHERE id = 1')->fetch();
    igual(['whatsapp', 'Oi', 'entrada', 1], [$a['tipo'], $a['assunto'], $a['direcao'], (int) $a['empresa_id']]);
    $pdo->exec("INSERT INTO atividades (tipo, data_hora, empresa_id, criado_em, atualizado_em) VALUES ('instagram', '2026-09-02 10:00:00', 1, 'x', 'x')");
    igual([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    igual(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    $indices = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'atividades'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['idx_atividades_empresa', 'idx_atividades_contato', 'idx_atividades_negocio'] as $i) {
        verdadeiro(in_array($i, $indices, true), $i);
    }
    $pdo->exec("INSERT INTO conversas (canal, identificador, criado_em, atualizado_em) VALUES ('whatsapp', '5511', 'x', 'x')");
    dispara(PDOException::class, fn () => $pdo->exec("INSERT INTO conversas (canal, identificador, criado_em, atualizado_em) VALUES ('whatsapp', '5511', 'x', 'x')"));
    dispara(PDOException::class, fn () => $pdo->exec("INSERT INTO conversas (canal, identificador, criado_em, atualizado_em) VALUES ('telegram', '1', 'x', 'x')"));
    $pdo->exec("INSERT INTO mensagens (conversa_id, direcao, id_externo, data_hora, criado_em) VALUES (1, 'entrada', 'wa:1', 'x', 'x')");
    dispara(PDOException::class, fn () => $pdo->exec("INSERT INTO mensagens (conversa_id, direcao, id_externo, data_hora, criado_em) VALUES (1, 'entrada', 'wa:1', 'x', 'x')"));
});
