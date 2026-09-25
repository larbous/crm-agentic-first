<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Evento da integração recusado por regra de negócio: a transação do evento é desfeita e a mensagem volta ao Opensquad. */
final class IntegracaoRecusada extends RuntimeException
{
}
