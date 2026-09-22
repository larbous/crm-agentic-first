<?php

declare(strict_types=1);

/**
 * Worker do CRM (SPEC §11). Rode a cada minuto pelo cron da hospedagem:
 *   * * * * * php /caminho/do/projeto/cron/worker.php
 * Só um worker roda por vez (lock de arquivo em storage/worker.lock); uma segunda chamada simultânea sai em silêncio.
 * Não imprime nada quando não há o que fazer (para não gerar e-mail do cron); use --verbose para ver o resumo.
 *
 * Parte do CRM Lárbous (github.com/larbous/crm-agentic-first), sob licença MIT.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\Worker;

$verboso = in_array('--verbose', $argv ?? [], true);

$caminhoLock = rtrim((string) Config::obter('caminhos.storage', dirname(__DIR__) . '/storage'), '/\\') . '/worker.lock';
$lock = fopen($caminhoLock, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($verboso) {
        fwrite(STDOUT, "Outro worker está rodando.\n");
    }
    exit(0);
}

set_time_limit(0);
$codigo = 0;
try {
    $r = (new Worker())->rodada();
    $rotinas = array_sum($r['rotinas']);
    $fezAlgo = $r['interrompidas'] + $rotinas + $r['agendadas'] + $r['processadas'] > 0;
    if ($verboso || $fezAlgo) {
        fwrite(STDOUT, sprintf(
            "[%s] interrompidas: %d · rotinas: %d · agendadas: %d · processadas: %d (erros: %d) · na fila: %d\n",
            date('Y-m-d H:i:s'), $r['interrompidas'], $rotinas, $r['agendadas'], $r['processadas'], $r['erros'], $r['restantes'],
        ));
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Falha no worker: ' . $e . "\n");
    $codigo = 1;
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
exit($codigo);
