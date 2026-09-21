<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;

/** POST JSON via cURL, comum aos provedores de IA. */
final class Http
{
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
