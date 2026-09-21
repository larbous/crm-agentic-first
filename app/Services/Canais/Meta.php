<?php

declare(strict_types=1);

namespace App\Services\Canais;

use App\Services\AI\Http;

/**
 * Integração com a plataforma da Meta (Fase 14): WhatsApp Business Cloud API e Instagram Direct usam o mesmo webhook
 * (`/webhooks/meta`, assinado com o segredo do app) e a mesma API Graph para enviar. Aqui ficam a verificação, a leitura
 * dos payloads (normalizados para o formato interno) e o envio de texto.
 */
final class Meta
{
    public const VERSAO_PADRAO = 'v21.0';

    /** @var (callable(array):array{status:int,corpo:string,erro:?string})|null substituto do cURL (testes): recebe url, corpo, token */
    private static $transporte = null;

    public static function definirTransporte(?callable $transporte): void
    {
        self::$transporte = $transporte;
    }

    /** Assinatura `X-Hub-Signature-256` do corpo bruto. Sem `app_secret` configurado, nada é aceito. */
    public static function assinaturaValida(string $corpo, string $cabecalho): bool
    {
        $segredo = (string) Canais::valor('meta', 'app_secret');
        if ($segredo === '' || !str_starts_with($cabecalho, 'sha256=')) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $corpo, $segredo), substr($cabecalho, 7));
    }

    /** Verificação de assinatura do webhook (GET): devolve o desafio se o token confere, senão null. */
    public static function desafio(array $query): ?string
    {
        $token = (string) Canais::valor('meta', 'verify_token');
        if ($token === '' || ($query['hub_mode'] ?? $query['hub.mode'] ?? '') !== 'subscribe') {
            return null;
        }
        $enviado = (string) ($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
        return hash_equals($token, $enviado) ? (string) ($query['hub_challenge'] ?? $query['hub.challenge'] ?? '') : null;
    }

    /**
     * Lê o payload de um webhook.
     * @return array{mensagens:list<array>,status:list<array{id:string,status:string,erro:?string}>}
     *   mensagem: canal, identificador, nome, id_externo, tipo, texto, midia, data_hora
     */
    public static function extrair(array $payload): array
    {
        $saida = ['mensagens' => [], 'status' => []];
        $objeto = (string) ($payload['object'] ?? '');
        foreach ((array) ($payload['entry'] ?? []) as $entrada) {
            if ($objeto === 'whatsapp_business_account') {
                foreach ((array) ($entrada['changes'] ?? []) as $mudanca) {
                    self::whatsapp((array) ($mudanca['value'] ?? []), $saida);
                }
            } elseif ($objeto === 'instagram') {
                foreach ((array) ($entrada['messaging'] ?? []) as $evento) {
                    self::instagram((array) $evento, $saida);
                }
            }
        }
        return $saida;
    }

    /**
     * Envia um texto. Devolve o id da mensagem no provedor ou o erro (sem a chave).
     * @return array{ok:bool,id:?string,erro:?string}
     */
    public static function enviar(string $canal, string $destinatario, string $texto): array
    {
        $versao = (string) (Canais::valor('meta', 'versao') ?: self::VERSAO_PADRAO);
        if ($canal === 'whatsapp') {
            $url = "https://graph.facebook.com/{$versao}/" . rawurlencode((string) Canais::valor('whatsapp', 'phone_number_id')) . '/messages';
            $corpo = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $destinatario, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $texto]];
            $token = (string) Canais::valor('whatsapp', 'token');
        } else {
            $url = "https://graph.facebook.com/{$versao}/" . rawurlencode((string) Canais::valor('instagram', 'conta_id')) . '/messages';
            $corpo = ['recipient' => ['id' => $destinatario], 'message' => ['text' => $texto], 'messaging_type' => 'RESPONSE'];
            $token = (string) Canais::valor('instagram', 'token');
        }

        $r = self::$transporte !== null
            ? (self::$transporte)(['url' => $url, 'corpo' => $corpo, 'token' => $token])
            : Http::postarJson($url, $corpo, ['Authorization: Bearer ' . $token], 20);
        if ($r['status'] === 0) {
            return ['ok' => false, 'id' => null, 'erro' => 'Sem resposta da Meta: ' . (string) $r['erro']];
        }
        $dados = json_decode($r['corpo'], true);
        $dados = is_array($dados) ? $dados : [];
        $id = $dados['messages'][0]['id'] ?? $dados['message_id'] ?? null;
        if ($r['status'] >= 200 && $r['status'] < 300 && is_string($id)) {
            // Mesmo prefixo das mensagens recebidas: o webhook de status devolve este id.
            return ['ok' => true, 'id' => ($canal === 'whatsapp' ? 'wa:' : 'ig:') . $id, 'erro' => null];
        }
        $mensagem = (string) ($dados['error']['message'] ?? ('HTTP ' . $r['status']));
        return ['ok' => false, 'id' => null, 'erro' => mb_substr('Meta: ' . $mensagem, 0, 300)];
    }

    /**
     * Baixa a mídia de uma mensagem recebida. WhatsApp: o id da mídia dá, na API Graph, uma URL temporária que também exige o
     * token. Instagram: o webhook já traz a URL do anexo. `definitivo` = repetir não adianta (mídia expirada, grande demais).
     * @param array $midia {id, mime, nome} (WhatsApp) ou {url} (Instagram)
     * @return array{ok:bool,bytes:?string,mime:?string,erro:?string,definitivo:bool}
     */
    public static function baixarMidia(string $canal, array $midia, int $maxBytes): array
    {
        $falha = static fn (string $erro, bool $definitivo = false): array => ['ok' => false, 'bytes' => null, 'mime' => null, 'erro' => mb_substr($erro, 0, 300), 'definitivo' => $definitivo];
        $mime = isset($midia['mime']) ? (string) $midia['mime'] : null;

        if ($canal === 'whatsapp') {
            $token = (string) Canais::valor('whatsapp', 'token');
            $versao = (string) (Canais::valor('meta', 'versao') ?: self::VERSAO_PADRAO);
            if (!isset($midia['id']) || $token === '') {
                return $falha('Mídia sem id ou WhatsApp sem token.', !isset($midia['id']));
            }
            $r = self::obter("https://graph.facebook.com/{$versao}/" . rawurlencode((string) $midia['id']), $token, $maxBytes);
            if ($r['status'] === 0) {
                return $falha('Sem resposta da Meta: ' . (string) $r['erro']);
            }
            $meta = json_decode($r['corpo'], true);
            if ($r['status'] !== 200 || !is_array($meta) || !is_string($meta['url'] ?? null)) {
                return $falha('Meta: ' . (string) (is_array($meta) ? ($meta['error']['message'] ?? 'HTTP ' . $r['status']) : 'HTTP ' . $r['status']), in_array($r['status'], [400, 404], true));
            }
            if ((int) ($meta['file_size'] ?? 0) > $maxBytes) {
                return $falha('Arquivo maior que o limite de ' . intdiv($maxBytes, 1048576) . ' MB.', true);
            }
            $mime = (string) ($meta['mime_type'] ?? $mime);
            $url = $meta['url'];
        } else {
            $url = (string) ($midia['url'] ?? '');
            $token = '';
            if ($url === '') {
                return $falha('Anexo sem URL.', true);
            }
        }
        if (!str_starts_with($url, 'https://')) {
            return $falha('URL da mídia não é HTTPS.', true);
        }

        $r = self::obter($url, $token, $maxBytes);
        if ($r['status'] === 0) {
            return $falha('Sem resposta ao baixar a mídia: ' . (string) $r['erro']);
        }
        if ($r['status'] !== 200 || $r['corpo'] === '') {
            return $falha('Download da mídia falhou (HTTP ' . $r['status'] . ').', in_array($r['status'], [400, 403, 404, 410], true));
        }
        if (strlen($r['corpo']) > $maxBytes) {
            return $falha('Arquivo maior que o limite de ' . intdiv($maxBytes, 1048576) . ' MB.', true);
        }
        return ['ok' => true, 'bytes' => $r['corpo'], 'mime' => $mime, 'erro' => null, 'definitivo' => false];
    }

    /** GET autenticado (ou não, sem token), pelo transporte de teste ou pelo cURL. @return array{status:int,corpo:string,erro:?string} */
    private static function obter(string $url, string $token, int $maxBytes): array
    {
        return self::$transporte !== null
            ? (self::$transporte)(['url' => $url, 'corpo' => null, 'token' => $token, 'metodo' => 'GET'])
            : Http::obter($url, $token !== '' ? ['Authorization: Bearer ' . $token] : [], 60, $maxBytes + 1024);
    }

    // ---- Interno --------------------------------------------------------------------------

    private static function whatsapp(array $valor, array &$saida): void
    {
        $nomes = [];
        foreach ((array) ($valor['contacts'] ?? []) as $c) {
            $nomes[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
        }
        foreach ((array) ($valor['messages'] ?? []) as $m) {
            $tipo = (string) ($m['type'] ?? '');
            if ($tipo === 'reaction' || !isset($m['id'], $m['from'])) {
                continue;
            }
            [$tipoInterno, $texto, $midia] = match ($tipo) {
                'text' => ['texto', (string) ($m['text']['body'] ?? ''), null],
                'audio' => ['audio', null, self::midia($m['audio'] ?? [])],
                'image' => ['imagem', $m['image']['caption'] ?? null, self::midia($m['image'] ?? [])],
                'video' => ['video', $m['video']['caption'] ?? null, self::midia($m['video'] ?? [])],
                'document' => ['documento', $m['document']['caption'] ?? ($m['document']['filename'] ?? null), self::midia($m['document'] ?? [])],
                'sticker' => ['sticker', null, self::midia($m['sticker'] ?? [])],
                'location' => ['localizacao', trim(($m['location']['name'] ?? '') . ' ' . ($m['location']['latitude'] ?? '') . ',' . ($m['location']['longitude'] ?? '')), null],
                'button' => ['texto', (string) ($m['button']['text'] ?? ''), null],
                'interactive' => ['texto', (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''), null],
                default => ['outro', null, null],
            };
            $wa = preg_replace('/\D/', '', (string) $m['from']) ?? '';
            $saida['mensagens'][] = [
                'canal' => 'whatsapp', 'identificador' => $wa, 'nome' => ($nomes[(string) $m['from']] ?? '') ?: null,
                'id_externo' => 'wa:' . $m['id'], 'tipo' => $tipoInterno, 'texto' => $texto !== null ? trim((string) $texto) : null, 'midia' => $midia,
                'data_hora' => self::data($m['timestamp'] ?? null),
            ];
        }
        foreach ((array) ($valor['statuses'] ?? []) as $s) {
            if (isset($s['id'], $s['status'])) {
                $saida['status'][] = ['id' => 'wa:' . $s['id'], 'status' => (string) $s['status'], 'erro' => isset($s['errors'][0]) ? mb_substr((string) ($s['errors'][0]['title'] ?? $s['errors'][0]['message'] ?? 'falhou'), 0, 300) : null];
            }
        }
    }

    private static function instagram(array $evento, array &$saida): void
    {
        $m = $evento['message'] ?? null;
        if (!is_array($m) || !empty($m['is_echo']) || !isset($m['mid'], $evento['sender']['id'])) {
            return; // leituras, entregas e ecos das nossas próprias mensagens
        }
        $tipo = 'texto';
        $midia = null;
        $anexo = $m['attachments'][0] ?? null;
        if (is_array($anexo)) {
            $tipo = match ((string) ($anexo['type'] ?? '')) {
                'image' => 'imagem', 'audio' => 'audio', 'video' => 'video', 'file' => 'documento', default => 'outro',
            };
            $midia = ['url' => (string) ($anexo['payload']['url'] ?? ''), 'mime' => null, 'nome' => null];
        }
        $saida['mensagens'][] = [
            'canal' => 'instagram', 'identificador' => (string) $evento['sender']['id'], 'nome' => null,
            'id_externo' => 'ig:' . $m['mid'], 'tipo' => $tipo, 'texto' => isset($m['text']) ? trim((string) $m['text']) : null, 'midia' => $midia,
            'data_hora' => self::data($evento['timestamp'] ?? null),
        ];
    }

    private static function midia(array $m): ?array
    {
        return isset($m['id']) ? ['id' => (string) $m['id'], 'mime' => $m['mime_type'] ?? null, 'nome' => $m['filename'] ?? null] : null;
    }

    /** Timestamp em segundos ou milissegundos → data local. */
    private static function data(mixed $ts): string
    {
        $n = is_numeric($ts) ? (int) $ts : time();
        return date('Y-m-d H:i:s', $n > 1_000_000_000_000 ? intdiv($n, 1000) : $n);
    }
}
