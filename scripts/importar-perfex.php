<?php

declare(strict_types=1);

/**
 * Importa dados do Perfex CRM (dump .sql) para o CRM Lárbous. Uso único, fora do fluxo normal da aplicação.
 * Uso: php scripts/importar-perfex.php <caminho-do-dump.sql> [--simular] [--somente=etapa1,etapa2]
 *
 * --simular: só conta o que seria criado (não grava nada). Sem essa flag, grava de verdade — rode antes com
 * --simular e confira o relatório, e de preferência num storage/db/crm.sqlite de teste antes de importar
 * para o banco de produção.
 *
 * --somente: roda só as etapas listadas (vírgula), sem duplicar o que já foi importado antes — útil pra
 * complementar uma importação já feita (ex.: --somente=servicos). Etapas disponíveis: empresas, leads,
 * propostas, contratos, projetos, tarefas, tickets, notas, cobrancas, custos, servicos.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Services\ActionExecutor;
use App\Services\Events;
use App\Services\Importacao\DumpSqlReader;
use App\Services\Importacao\PerfexImporter;

// Import em massa não deve disparar agentes/squads (custaria chamadas de IA reais e estouraria o
// limite por hora) — cada empresa/negócio/proposta criada aqui é histórico, não um evento ao vivo.
Events::limpar();

$caminho = $argv[1] ?? null;
$simular = in_array('--simular', $argv, true);
$somente = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--somente=')) {
        $somente = array_filter(array_map('trim', explode(',', substr($arg, strlen('--somente=')))));
    }
}

if ($caminho === null || !is_file($caminho)) {
    fwrite(STDERR, "Uso: php scripts/importar-perfex.php <caminho-do-dump.sql> [--simular] [--somente=etapa1,etapa2]\n");
    exit(1);
}

echo $simular ? "Modo simulação (nada será gravado).\n" : "Modo real — gravando no banco.\n";
echo "Lendo dump: {$caminho}\n";

$dump = new DumpSqlReader($caminho);
$exec = new ActionExecutor();
$importador = new PerfexImporter($dump, $exec, $simular);

DB::conexao(); // garante a conexão/PRAGMAs antes do primeiro INSERT

try {
    $resultado = $importador->executar($somente);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha na importação: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}

echo "\n=== Contagens ===\n";
ksort($resultado['contagens']);
foreach ($resultado['contagens'] as $chave => $n) {
    echo str_pad($chave, 30) . $n . "\n";
}

echo "\n=== Avisos (" . count($resultado['avisos']) . ") ===\n";
foreach ($resultado['avisos'] as $aviso) {
    echo "- {$aviso}\n";
}

echo "\nConcluído.\n";
