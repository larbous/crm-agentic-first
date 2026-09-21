<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;
use App\Repositories\ConfiguracaoRepository;

/**
 * Google Gemini (generateContent, REST via cURL). Provedor secundário do failover. Sem busca na web nem prompt caching:
 * o prompt de sistema vai inteiro a cada chamada. A chave vai no cabeçalho (nunca na URL, que pode ir para logs).
 */
final class ProvedorGemini implements Provedor
{
    public const URL = 'https://generativelanguage.googleapis.com/v1beta/models/';
    /** Padrões usados só se `ia.modelo_gemini_rapido` / `ia.modelo_gemini_redacao` não estiverem em `configuracoes`. */
    public const MODELO_RAPIDO = 'gemini-3.5-flash';
    public const MODELO_REDACAO = 'gemini-3.1-pro-preview';
    /** Folga somada a maxOutputTokens nos modelos 3.x: o raciocínio deles consome o mesmo limite e não dá para desligá-lo. */
    public const FOLGA_RACIOCINIO = 2048;

    /** @var (callable(array):array{status:int,corpo:string,erro:?string})|null substituto do cURL (testes) */
    private static $transporte = null;

    /** Substitui o transporte HTTP (testes). Recebe ['modelo' => string, 'corpo' => array, 'chave' => string]. */
    public static function definirTransporte(?callable $transporte): void
    {
        self::$transporte = $transporte;
    }

    public function nome(): string
    {
        return 'gemini';
    }

    public function configurado(): bool
    {
        return self::$transporte !== null || (string) Config::obter('gemini.api_key', '') !== '';
    }

    public function suportaBuscaWeb(): bool
    {
        return false;
    }

    public function suportaAudio(): bool
    {
        return true;
    }

    /** Classe do modelo pedido (Haiku = tarefa simples → rápido; o resto → redação/análise) traduzida para o modelo Gemini configurado. */
    public function modeloPara(string $modeloPedido): string
    {
        if (str_starts_with($modeloPedido, 'gemini-')) {
            return $modeloPedido;
        }
        $config = new ConfiguracaoRepository();
        return str_contains($modeloPedido, 'haiku')
            ? ($config->obter('ia.modelo_gemini_rapido') ?: self::MODELO_RAPIDO)
            : ($config->obter('ia.modelo_gemini_redacao') ?: self::MODELO_REDACAO);
    }

    public function enviar(RequisicaoIA $req): RespostaProvedor
    {
        $config = ['maxOutputTokens' => $req->maxTokens];
        if (!str_starts_with($req->modelo, 'gemini-2.5-')) {
            $config['maxOutputTokens'] += self::FOLGA_RACIOCINIO;
        }
        if ($req->temperatura !== null) {
            $config['temperature'] = $req->temperatura;
        }
        // Na família 2.5 o "raciocínio" consome maxOutputTokens: sem limite, uma resposta curta sairia cortada.
        if (str_starts_with($req->modelo, 'gemini-2.5-')) {
            $config['thinkingConfig'] = ['thinkingBudget' => str_contains($req->modelo, '-pro') ? 128 : 0]; // o Pro não aceita 0
        }
        $partes = [['text' => $req->usuario]];
        foreach ($req->anexos as $anexo) {
            $partes[] = ['inline_data' => ['mime_type' => $anexo['mime'], 'data' => $anexo['base64']]];
        }
        $corpo = [
            'systemInstruction' => ['parts' => [['text' => $req->sistema]]],
            'contents'          => [['role' => 'user', 'parts' => $partes]],
            'generationConfig'  => $config,
        ];

        $chave = (string) Config::obter('gemini.api_key', '');
        $r = self::$transporte !== null
            ? (self::$transporte)(['modelo' => $req->modelo, 'corpo' => $corpo, 'chave' => $chave])
            : Http::postarJson(self::URL . rawurlencode($req->modelo) . ':generateContent', $corpo, ['x-goog-api-key: ' . $chave], $req->timeout);

        if ($r['status'] === 0) {
            return new RespostaProvedor(0, tecnico: (string) $r['erro']);
        }
        $dados = json_decode($r['corpo'], true);
        if ($r['status'] !== 200 || !is_array($dados)) {
            $mensagem = is_array($dados) ? (string) ($dados['error']['message'] ?? '') : mb_substr($r['corpo'], 0, 200);
            // Chave inválida volta como 400 "API key not valid": é erro de configuração, não de requisição.
            $status = $r['status'] === 400 && stripos($mensagem, 'api key') !== false ? 401 : ($r['status'] === 200 ? 502 : $r['status']);
            return new RespostaProvedor($status, tecnico: 'HTTP ' . $r['status'] . ': ' . $mensagem);
        }

        $candidato = (array) ($dados['candidates'][0] ?? []);
        $texto = '';
        foreach ((array) ($candidato['content']['parts'] ?? []) as $parte) {
            $texto .= (string) ($parte['text'] ?? '');
        }
        $motivo = (string) ($candidato['finishReason'] ?? '');
        if ($candidato === [] || ($texto === '' && $motivo !== 'MAX_TOKENS')) {
            // Bloqueio de segurança ou resposta vazia: outro provedor não ajudaria nesta requisição (422 não dispara failover).
            return new RespostaProvedor(422, tecnico: 'Gemini sem texto (' . ($motivo !== '' ? $motivo : (string) ($dados['promptFeedback']['blockReason'] ?? 'vazio')) . ')');
        }
        $uso = (array) ($dados['usageMetadata'] ?? []);
        return new RespostaProvedor(
            200,
            $texto,
            (int) ($uso['promptTokenCount'] ?? 0),
            (int) ($uso['candidatesTokenCount'] ?? 0) + (int) ($uso['thoughtsTokenCount'] ?? 0),
            $motivo === 'MAX_TOKENS' ? 'max_tokens' : 'end_turn',
        );
    }
}
