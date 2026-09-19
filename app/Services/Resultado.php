<?php

declare(strict_types=1);

namespace App\Services;

/** Resultado de uma ação do ActionExecutor. */
final class Resultado
{
    /**
     * @param array<string,string> $erros campo => mensagem (campo "_" para erros gerais)
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $id = null,
        public readonly array $erros = [],
        public readonly string $mensagem = '',
        public readonly ?array $registro = null,
        public readonly ?int $logId = null,
    ) {
    }

    public static function sucesso(?int $id, string $mensagem, ?array $registro = null, ?int $logId = null): self
    {
        return new self(true, $id, [], $mensagem, $registro, $logId);
    }

    /** @param array<string,string> $erros */
    public static function falha(array $erros): self
    {
        return new self(false, null, $erros, implode(' ', $erros));
    }

    public static function erroGeral(string $mensagem): self
    {
        return self::falha(['_' => $mensagem]);
    }
}
