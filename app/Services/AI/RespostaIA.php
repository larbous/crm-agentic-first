<?php

declare(strict_types=1);

namespace App\Services\AI;

/** Resultado de uma chamada de IA bem-sucedida. */
final class RespostaIA
{
    public function __construct(
        public readonly string $texto,
        public readonly int $execucaoId,
        public readonly string $modelo,
        public readonly int $tokensEntrada,
        public readonly int $tokensSaida,
        public readonly int $duracaoMs,
    ) {
    }
}
