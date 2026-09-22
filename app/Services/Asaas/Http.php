<?php

declare(strict_types=1);

namespace App\Services\Asaas;

use App\Core\Config;

/** cURL para a API do Asaas (REST v3): GET, POST e DELETE com JSON. */
final class Http
{
    /** @return array{status:int,corpo:string,erro:?string} status 0 = falha de rede/timeout */
    public static function chamar(string $metodo, string $url, ?array $corpo, array $cabecalhos, int $timeout = 30): array
    {
        $ch = curl_init($url);
        $opcoes = [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => ['content-type: application/json', ...$cabecalhos],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeout),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ];
        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        // Mesmo bundle de certificados usado pelos provedores de IA (hospedagens/Windows sem CA do sistema).
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
