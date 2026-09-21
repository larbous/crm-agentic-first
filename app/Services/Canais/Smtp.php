<?php

declare(strict_types=1);

namespace App\Services\Canais;

use RuntimeException;

/** Cliente SMTP mínimo: EHLO, STARTTLS opcional, AUTH LOGIN e envio de uma mensagem de texto. Sem bibliotecas. */
final class Smtp
{
    public function __construct(private readonly Conexao $conexao, private readonly string $nomeLocal = 'localhost')
    {
    }

    /**
     * @param string $bruto mensagem completa (cabeçalhos + corpo) com CRLF, como devolvida por Mime::montar()
     * @throws RuntimeException com a resposta do servidor
     */
    public function enviar(string $usuario, string $senha, string $seguranca, string $de, string $para, string $bruto): void
    {
        $this->esperar([220]);
        $this->comando('EHLO ' . $this->nomeLocal, [250]);
        if ($seguranca === 'tls') {
            $this->comando('STARTTLS', [220]);
            if (!$this->conexao->ativarTls()) {
                throw new RuntimeException('Não foi possível ativar TLS na conexão SMTP.');
            }
            $this->comando('EHLO ' . $this->nomeLocal, [250]);
        }
        if ($usuario !== '') {
            $this->comando('AUTH LOGIN', [334]);
            $this->comando(base64_encode($usuario), [334]);
            $this->comando(base64_encode($senha), [235]);
        }
        $this->comando('MAIL FROM:<' . $de . '>', [250]);
        $this->comando('RCPT TO:<' . $para . '>', [250, 251]);
        $this->comando('DATA', [354]);
        // Linhas que começam com "." dobram o ponto; o fim é CRLF.CRLF.
        $corpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], "\n", $bruto)) ?? $bruto;
        $this->conexao->escrever(str_replace("\n", "\r\n", rtrim($corpo, "\n")) . "\r\n.\r\n");
        $this->esperar([250]);
        try {
            $this->comando('QUIT', [221]);
        } finally {
            $this->conexao->fechar();
        }
    }

    /** @param list<int> $esperados */
    private function comando(string $comando, array $esperados): string
    {
        $this->conexao->escrever($comando . "\r\n");
        return $this->esperar($esperados);
    }

    /** Lê a resposta (inclusive multilinha "250-") e confere o código. */
    private function esperar(array $esperados): string
    {
        $texto = '';
        while (true) {
            $linha = $this->conexao->linha();
            if ($linha === null) {
                throw new RuntimeException('A conexão SMTP foi encerrada.');
            }
            $texto .= $linha . "\n";
            if (strlen($linha) >= 4 && $linha[3] === ' ' || strlen($linha) === 3) {
                break;
            }
        }
        $codigo = (int) substr($texto, 0, 3);
        if (!in_array($codigo, $esperados, true)) {
            throw new RuntimeException('O servidor SMTP respondeu: ' . trim(substr($texto, 0, 200)));
        }
        return $texto;
    }
}
