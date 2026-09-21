<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;

/** POST JSON e GET via cURL, comuns aos provedores de IA e à Meta. */
final class Http
{
    /**
     * GET (download). `$maxBytes` corta o download acima do limite (cURL aborta e o status volta 0 com o erro).
     * @param list<string> $cabecalhos
     * @return array{status:int,corpo:string,erro:?string} status 0 = falha de rede/timeout
     */
    public static function obter(string $url, array $cabecalhos, int $timeout, int $maxBytes = 0): array
    {
        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_HTTPHEADER     => $cabecalhos,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeout),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ];
        if ($maxBytes > 0) {
            $opcoes[CURLOPT_NOPROGRESS] = false;
            $opcoes[CURLOPT_PROGRESSFUNCTION] = static fn ($ch, $total, $baixado): int => $baixado > $maxBytes ? 1 : 0;
        }
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

    /**
     * @param list<string> $cabecalhos além do content-type
     * @return array{status:int,corpo:string,erro:?string} status 0 = falha de rede/timeout
     */
    public static function postarJson(string $url, array $corpo, array $cabecalhos, int $timeout): array
    {
        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['content-type: application/json', ...$cabecalhos],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeout),
        ];
        // Hospedagens/Windows sem bundle de certificados: caminho do cacert.pem em anthropic.cacert (vale para todos os provedores).
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
