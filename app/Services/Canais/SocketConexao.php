<?php

declare(strict_types=1);

namespace App\Services\Canais;

use RuntimeException;

/** Conexão real por `stream_socket_client` (sem extensão imap). `seguranca`: 'ssl' (TLS direto), 'tls' (STARTTLS) ou '' (texto puro). */
final class SocketConexao implements Conexao
{
    /** @var resource */
    private $fluxo;

    public function __construct(string $host, int $porta, string $seguranca = 'ssl', int $timeout = 15)
    {
        $alvo = ($seguranca === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta;
        $contexto = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fluxo = @stream_socket_client($alvo, $codigo, $mensagem, $timeout, STREAM_CLIENT_CONNECT, $contexto);
        if ($fluxo === false) {
            throw new RuntimeException("Não foi possível conectar a {$host}:{$porta} ({$mensagem}).");
        }
        stream_set_timeout($fluxo, $timeout);
        $this->fluxo = $fluxo;
    }

    public function linha(): ?string
    {
        $l = fgets($this->fluxo, 65536);
        return $l === false ? null : rtrim($l, "\r\n");
    }

    public function ler(int $n): string
    {
        $saida = '';
        while (strlen($saida) < $n) {
            $parte = fread($this->fluxo, $n - strlen($saida));
            if ($parte === false || $parte === '') {
                break;
            }
            $saida .= $parte;
        }
        return $saida;
    }

    public function escrever(string $dados): void
    {
        fwrite($this->fluxo, $dados);
    }

    public function ativarTls(): bool
    {
        return (bool) stream_socket_enable_crypto($this->fluxo, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    }

    public function fechar(): void
    {
        if (is_resource($this->fluxo)) {
            fclose($this->fluxo);
        }
    }
}
