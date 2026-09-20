<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;
use App\Repositories\ExecucaoRepository;

/**
 * Único ponto de chamada à API da Anthropic (cURL, sem SDK). Cada chamada vira uma linha em `execucoes`
 * (modelo, tokens, duração, status). O prompt de sistema vai em bloco com cache_control (prompt caching).
 * A chave vem de config.local.php (anthropic.api_key) e nunca é gravada nem registrada.
 */
final class Client
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

    public function configurado(): bool
    {
        return self::$transporte !== null || (string) Config::obter('anthropic.api_key', '') !== '';
    }

    /**
     * @param array $meta agente_id, squad_id, squad_execucao_id, etapa_ordem, entidade, registro_id, simulacao (opcionais)
     * @param array $opcoes web_search (bool: liga a ferramenta de busca na web), timeout (segundos),
     *                      temperatura (float; null omite o parâmetro — padrão 0, usado pelo roteador)
     * @throws IaErro
     */
    public function chamar(string $modelo, string $sistema, string $usuario, array $meta = [], int $maxTokens = 1024, array $opcoes = []): RespostaIA
    {
        $execucoes = new ExecucaoRepository();
        $execucaoId = $execucoes->iniciar($meta + ['entrada' => $usuario, 'modelo' => $modelo, 'status' => 'rodando']);
        $inicio = hrtime(true);
        $duracao = static fn (): int => (int) ((hrtime(true) - $inicio) / 1_000_000);
        $falhar = static function (string $paraOperador, string $tecnico) use ($execucoes, $execucaoId, $duracao): never {
            $execucoes->atualizar($execucaoId, ['status' => 'erro', 'erro' => mb_substr($tecnico, 0, 500), 'duracao_ms' => $duracao(), 'concluido_em' => agora()]);
            throw new IaErro($paraOperador);
        };

        $chave = (string) Config::obter('anthropic.api_key', '');
        if ($chave === '' && self::$transporte === null) {
            $falhar('A IA não está configurada. Defina anthropic.api_key em config.local.php ou use os comandos com / (digite /ajuda).', 'api_key ausente');
        }

        $corpo = [
            'model'      => $modelo,
            'max_tokens' => $maxTokens,
            'system'     => [['type' => 'text', 'text' => $sistema, 'cache_control' => ['type' => 'ephemeral']]],
            'messages'   => [['role' => 'user', 'content' => $usuario]],
        ];
        $temperatura = array_key_exists('temperatura', $opcoes) ? $opcoes['temperatura'] : 0;
        if ($temperatura !== null) {
            $corpo['temperature'] = $temperatura;
        }
        if (!empty($opcoes['web_search'])) {
            $corpo['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]];
        }

        $resposta = $this->enviar($corpo, $chave, isset($opcoes['timeout']) ? (int) $opcoes['timeout'] : null);
        if ($resposta['status'] === 0) {
            $falhar('A IA não respondeu a tempo. Tente novamente ou use um comando com / (digite /ajuda).', (string) $resposta['erro']);
        }
        $dados = json_decode($resposta['corpo'], true);
        if ($resposta['status'] !== 200 || !is_array($dados)) {
            $tecnico = 'HTTP ' . $resposta['status'] . ': ' . (is_array($dados) ? (string) ($dados['error']['message'] ?? '') : mb_substr($resposta['corpo'], 0, 200));
            $falhar(match (true) {
                in_array($resposta['status'], [401, 403], true) => 'A chave da API da IA foi recusada. Confira anthropic.api_key em config.local.php.',
                $resposta['status'] === 429 => 'O limite de uso da IA foi atingido. Tente novamente em instantes.',
                default => 'A IA está indisponível no momento. Tente novamente ou use um comando com / (digite /ajuda).',
            }, $tecnico);
        }

        $texto = '';
        foreach ((array) ($dados['content'] ?? []) as $bloco) {
            if (($bloco['type'] ?? '') === 'text') {
                $texto .= (string) ($bloco['text'] ?? '');
            }
        }
        $uso = (array) ($dados['usage'] ?? []);
        // Entrada total = tokens novos + escritos no cache + lidos do cache.
        $tokensEntrada = (int) ($uso['input_tokens'] ?? 0) + (int) ($uso['cache_creation_input_tokens'] ?? 0) + (int) ($uso['cache_read_input_tokens'] ?? 0);
        $tokensSaida = (int) ($uso['output_tokens'] ?? 0);
        $ms = $duracao();

        $execucoes->atualizar($execucaoId, [
            'status' => 'concluida', 'saida' => $texto, 'tokens_entrada' => $tokensEntrada, 'tokens_saida' => $tokensSaida,
            'duracao_ms' => $ms, 'concluido_em' => agora(),
        ]);
        return new RespostaIA($texto, $execucaoId, $modelo, $tokensEntrada, $tokensSaida, $ms, isset($dados['stop_reason']) ? (string) $dados['stop_reason'] : null);
    }

    /** Uma tentativa extra em 429/529/5xx. Timeout não é repetido (dobraria a espera do operador). */
    private function enviar(array $corpo, string $chave, ?int $timeout): array
    {
        $resposta = $this->requisitar($corpo, $chave, $timeout);
        if ($resposta['status'] === 429 || $resposta['status'] === 529 || $resposta['status'] >= 500) {
            usleep(800_000);
            $resposta = $this->requisitar($corpo, $chave, $timeout);
        }
        return $resposta;
    }

    /** @return array{status:int,corpo:string,erro:?string} status 0 = falha de rede/timeout */
    private function requisitar(array $corpo, string $chave, ?int $timeout): array
    {
        if (self::$transporte !== null) {
            return (self::$transporte)(['corpo' => $corpo, 'chave' => $chave]);
        }

        $ch = curl_init(self::URL);
        $opcoes = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . $chave,
                'anthropic-version: ' . self::VERSAO_API,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeout ?? (int) Config::obter('anthropic.timeout', 30)),
        ];
        // Hospedagens/Windows sem bundle de certificados: caminho do cacert.pem em anthropic.cacert.
        $cacert = (string) Config::obter('anthropic.cacert', '');
        if ($cacert !== '') {
            $opcoes[CURLOPT_CAINFO] = $cacert;
        }
        curl_setopt_array($ch, $opcoes);
        $saida = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = $saida === false ? curl_error($ch) : null;
        curl_close($ch);

        return ['status' => $saida === false ? 0 : $status, 'corpo' => $saida === false ? '' : (string) $saida, 'erro' => $erro];
    }
}
