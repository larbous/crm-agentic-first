<?php

declare(strict_types=1);

/**
 * Dados iniciais: pipeline padrão com etapas, origens, motivos de perda e configurações (SPEC §4.12).
 * Idempotente — pode ser executado mais de uma vez. Uso: php scripts/seed.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Services\Semeador;

try {
    Semeador::executar(DB::conexao());
} catch (Throwable $e) {
    fwrite(STDERR, "Falha no seed: {$e->getMessage()}\n");
    exit(1);
}

echo "Seed concluído.\n";
