<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;

/** API de mensagens da Anthropic via cURL (sem SDK). O prompt de sistema vai em bloco com cache_control (prompt caching). */
final class ProvedorAnthropic implements Provedor
{
    public const URL = 'https://api.anthropic.com/v1/messages';
    public const VERSAO_API = '2023-06-01';

    /** Teto de usos de cada ferramenta de web numa chamada (busca e leitura contam separado). */
    public const MAX_USOS_WEB = 8;
    /**
     * Continuações de `pause_turn` permitidas numa chamada. O servidor pausa o turno a cada 10 iterações de ferramenta;
     * reenviar a mesma mensagem com a resposta parcial do modelo faz o servidor retomar de onde parou.
     */
    public const MAX_CONTINUACOES = 3;
    /**
     * Modelos com as ferramentas de web de filtragem dinâmica (o modelo filtra os resultados por código antes de
     * ocuparem o contexto). Os demais usam as versões básicas. Não declare `code_execution` junto: são incompatíveis.
     */
    private const RE_WEB_FILTRADA = '/^claude-(opus-(5|4-6|4-7|4-8)|sonnet-(5|4-6)|fable-5)\b/';

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

    public function suportaAudio(): bool
    {
        return false;
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
            $corpo['tools'] = self::ferramentasWeb($req->modelo);
        }

        $chave = (string) Config::obter('anthropic.api_key', '');
        $inicio = hrtime(true);
        $decorrido = static fn (): int => (int) ((hrtime(true) - $inicio) / 1_000_000_000);
        $texto = '';
        $entrada = 0;
        $saidaTokens = 0;
        $parada = null;

        // O turno pode pausar (`pause_turn`) no meio do uso das ferramentas de web. Reenviar a mesma mensagem com a
        // resposta parcial do modelo faz o servidor retomar o turno; nada de mensagem extra do usuário no meio.
        for ($volta = 0; ; $volta++) {
            $r = $this->requisitar($corpo, $chave, $volta === 0 ? $req->timeout : max(5, $req->timeout - $decorrido()));
            if ($r['status'] === 0) {
                // Falha de rede numa retomada também é falha da chamada: devolver o texto pela metade viraria
                // "resposta não é JSON" na tela, escondendo o motivo real e impedindo a nova tentativa do Client.
                return new RespostaProvedor(0, tecnico: (string) $r['erro']);
            }
            $dados = json_decode($r['corpo'], true);
            if ($r['status'] !== 200 || !is_array($dados)) {
                return new RespostaProvedor(
                    $r['status'] === 200 ? 502 : $r['status'],
                    tecnico: 'HTTP ' . $r['status'] . ': ' . (is_array($dados) ? (string) ($dados['error']['message'] ?? '') : mb_substr($r['corpo'], 0, 200)),
                );
            }

            $blocos = (array) ($dados['content'] ?? []);
            foreach ($blocos as $bloco) {
                if (($bloco['type'] ?? '') === 'text') {
                    $texto .= (string) ($bloco['text'] ?? '');
                }
            }
            $uso = (array) ($dados['usage'] ?? []);
            // Entrada total = tokens novos + escritos no cache + lidos do cache.
            $entrada += (int) ($uso['input_tokens'] ?? 0) + (int) ($uso['cache_creation_input_tokens'] ?? 0) + (int) ($uso['cache_read_input_tokens'] ?? 0);
            $saidaTokens += (int) ($uso['output_tokens'] ?? 0);
            $parada = isset($dados['stop_reason']) ? (string) $dados['stop_reason'] : null;

            $podeRetomar = $parada === 'pause_turn' && $blocos !== [] && $volta < self::MAX_CONTINUACOES
                && $req->timeout - $decorrido() >= 10;
            if (!$podeRetomar) {
                return new RespostaProvedor(200, $texto, $entrada, $saidaTokens, $parada);
            }
            $corpo['messages'][] = ['role' => 'assistant', 'content' => $blocos];
        }
    }

    /**
     * Ferramentas de web da chamada: busca (encontra páginas) e leitura (abre uma URL já citada na conversa e devolve
     * o conteúdo). Sem a leitura o modelo só enxerga os trechos indexados pela busca — nunca o rodapé de um site.
     * @return list<array<string,mixed>>
     */
    private static function ferramentasWeb(string $modelo): array
    {
        $filtrada = preg_match(self::RE_WEB_FILTRADA, $modelo) === 1;
        return [
            ['type' => $filtrada ? 'web_search_20260209' : 'web_search_20250305', 'name' => 'web_search', 'max_uses' => self::MAX_USOS_WEB],
            ['type' => $filtrada ? 'web_fetch_20260209' : 'web_fetch_20250910', 'name' => 'web_fetch', 'max_uses' => self::MAX_USOS_WEB],
        ];
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
