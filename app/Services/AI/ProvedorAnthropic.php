<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;

/** API de mensagens da Anthropic via cURL (sem SDK). O prompt de sistema vai em bloco com cache_control (prompt caching). */
final class ProvedorAnthropic implements Provedor
{
    public const URL = 'https://api.anthropic.com/v1/messages';
    public const VERSAO_API = '2023-06-01';

    /** @var (callable(array):array{status:int,corpo:string,erro:?string})|null substituto do cURL (testes) */
    private static $transporte = null;

    /** Substitui o transporte HTTP (testes). Recebe ['corpo' => array, 'chave' => string] e devolve status/corpo/erro. */
    public static function definirTransporte(?callable $transporte): void
    {
        self::$transporte = $transporte;
    }

    public function nome(): string
    {
        return 'anthropic';
    }

    public function configurado(): bool
    {
        return self::$transporte !== null || (string) Config::obter('anthropic.api_key', '') !== '';
    }

    public function suportaBuscaWeb(): bool
    {
        return true;
    }

    public function modeloPara(string $modeloPedido): string
    {
        return $modeloPedido;
    }

    public function enviar(RequisicaoIA $req): RespostaProvedor
    {
        $corpo = [
            'model'      => $req->modelo,
            'max_tokens' => $req->maxTokens,
            'system'     => [['type' => 'text', 'text' => $req->sistema, 'cache_control' => ['type' => 'ephemeral']]],
            'messages'   => [['role' => 'user', 'content' => $req->usuario]],
        ];
        if ($req->temperatura !== null) {
            $corpo['temperature'] = $req->temperatura;
        }
        if ($req->buscaWeb) {
            $corpo['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]];
        }

        $r = $this->requisitar($corpo, (string) Config::obter('anthropic.api_key', ''), $req->timeout);
        if ($r['status'] === 0) {
            return new RespostaProvedor(0, tecnico: (string) $r['erro']);
        }
        $dados = json_decode($r['corpo'], true);
        if ($r['status'] !== 200 || !is_array($dados)) {
            return new RespostaProvedor(
                $r['status'] === 200 ? 502 : $r['status'],
                tecnico: 'HTTP ' . $r['status'] . ': ' . (is_array($dados) ? (string) ($dados['error']['message'] ?? '') : mb_substr($r['corpo'], 0, 200)),
            );
        }

        $texto = '';
        foreach ((array) ($dados['content'] ?? []) as $bloco) {
            if (($bloco['type'] ?? '') === 'text') {
                $texto .= (string) ($bloco['text'] ?? '');
            }
        }
        $uso = (array) ($dados['usage'] ?? []);
        // Entrada total = tokens novos + escritos no cache + lidos do cache.
        $entrada = (int) ($uso['input_tokens'] ?? 0) + (int) ($uso['cache_creation_input_tokens'] ?? 0) + (int) ($uso['cache_read_input_tokens'] ?? 0);
        return new RespostaProvedor(200, $texto, $entrada, (int) ($uso['output_tokens'] ?? 0), isset($dados['stop_reason']) ? (string) $dados['stop_reason'] : null);
    }

    /** @return array{status:int,corpo:string,erro:?string} status 0 = falha de rede/timeout */
    private function requisitar(array $corpo, string $chave, int $timeout): array
    {
        if (self::$transporte !== null) {
            return (self::$transporte)(['corpo' => $corpo, 'chave' => $chave]);
        }
        return Http::postarJson(self::URL, $corpo, [
            'x-api-key: ' . $chave,
            'anthropic-version: ' . self::VERSAO_API,
        ], $timeout);
    }
}
