<?php

declare(strict_types=1);

namespace App\Core;

/** Resposta HTTP (HTML, JSON ou redirecionamento). Só emite saída em enviar(). */
final class Response
{
    public function __construct(
        public string $corpo = '',
        public int $status = 200,
        public array $cabecalhos = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $dados, int $status = 200): self
    {
        return new self(
            json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirecionar(string $destino, int $status = 302): self
    {
        return new self('', $status, ['Location' => $destino]);
    }

    public static function texto(string $texto, int $status = 200): self
    {
        return new self($texto, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        foreach ($this->cabecalhos as $nome => $valor) {
            header($nome . ': ' . $valor);
        }
        echo $this->corpo;
    }
}
