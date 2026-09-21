<?php

declare(strict_types=1);

namespace App\Services\AI;

/** Pedido de uma chamada de IA, independente do provedor. `modelo` é o do provedor que vai recebê-lo. */
final class RequisicaoIA
{
    public function __construct(
        public readonly string $modelo,
        public readonly string $sistema,
        public readonly string $usuario,
        public readonly int $maxTokens,
        /** null omite o parâmetro (padrão do provedor). */
        public readonly ?float $temperatura,
        public readonly bool $buscaWeb,
        public readonly int $timeout,
        /** Áudios anexados: cada um com 'mime' e 'base64'. Só provedores com `suportaAudio()` os recebem. @var list<array{mime:string,base64:string}> */
        public readonly array $anexos = [],
    ) {
    }
}
