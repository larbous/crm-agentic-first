<?php

declare(strict_types=1);

/**
 * Aplica, em ordem, as migrações pendentes de /migrations (NNNN_descricao.sql).
 * Uso: php scripts/migrate.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\DB;
use App\Repositories\MigracaoRepository;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Extensão pdo_sqlite não está habilitada no PHP.\n");
    exit(1);
}

$repo = new MigracaoRepository(DB::conexao());
$repo->garantirTabela();
$aplicadas = $repo->aplicadas();

$arquivos = glob(rtrim((string) Config::obter('caminhos.migracoes'), '/\\') . '/*.sql') ?: [];
sort($arquivos);

$novas = 0;
foreach ($arquivos as $arquivo) {
    $nome = basename($arquivo);
    if (!preg_match('/^\d{4}_[a-z0-9_]+\.sql$/', $nome)) {
        fwrite(STDERR, "Ignorando arquivo fora do padrão NNNN_descricao.sql: {$nome}\n");
        continue;
    }
    if (in_array($nome, $aplicadas, true)) {
        continue;
    }
    try {
        $repo->aplicar($nome, (string) file_get_contents($arquivo));
    } catch (Throwable $e) {
        fwrite(STDERR, "Falha em {$nome}: {$e->getMessage()}\n");
        exit(1);
    }
    echo "Aplicada: {$nome}\n";
    $novas++;
}

echo $novas === 0 ? "Nada a migrar. Banco em dia.\n" : "{$novas} migração(ões) aplicada(s).\n";
