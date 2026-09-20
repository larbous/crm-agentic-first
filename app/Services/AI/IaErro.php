<?php

declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

/** Falha na chamada de IA. A mensagem é segura para exibir ao operador (sem chave nem detalhes internos). */
final class IaErro extends RuntimeException
{
}
