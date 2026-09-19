<?php

declare(strict_types=1);

/**
 * Runner de testes em PHP puro. Uso: php tests/run.php [filtro]
 * Cada arquivo tests/*Test.php registra casos com teste('descrição', fn () => ...).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

/** @var list<array{string,callable}> $GLOBALS['__testes'] */
$GLOBALS['__testes'] = [];

function teste(string $descricao, callable $fn): void
{
    $GLOBALS['__testes'][] = [$descricao, $fn];
}

final class FalhaDeAsserção extends RuntimeException
{
}

function igual(mixed $esperado, mixed $obtido, string $msg = ''): void
{
    if ($esperado !== $obtido) {
        throw new FalhaDeAsserção(
            ($msg !== '' ? $msg . ': ' : '') . 'esperado ' . var_export($esperado, true) . ', obtido ' . var_export($obtido, true)
        );
    }
}

function verdadeiro(mixed $condicao, string $msg = 'condição falsa'): void
{
    if ($condicao !== true) {
        throw new FalhaDeAsserção($msg);
    }
}

function contem(string $trecho, string $texto, string $msg = ''): void
{
    if (!str_contains($texto, $trecho)) {
        throw new FalhaDeAsserção(($msg !== '' ? $msg . ': ' : '') . "'{$trecho}' não encontrado em '{$texto}'");
    }
}

function dispara(string $classe, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $classe) {
            return;
        }
        throw new FalhaDeAsserção("esperava {$classe}, veio " . $e::class . ': ' . $e->getMessage());
    }
    throw new FalhaDeAsserção("esperava exceção {$classe}, nada foi lançado");
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Extensão pdo_sqlite não está habilitada no PHP.\n");
    exit(1);
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $arquivo) {
    require $arquivo;
}

$filtro = $argv[1] ?? null;
$ok = 0;
$falhas = [];
foreach ($GLOBALS['__testes'] as [$descricao, $fn]) {
    if ($filtro !== null && stripos($descricao, $filtro) === false) {
        continue;
    }
    try {
        $fn();
        $ok++;
        echo '.';
    } catch (Throwable $e) {
        $falhas[] = [$descricao, $e];
        echo 'F';
    }
}

echo "\n\n";
foreach ($falhas as [$descricao, $e]) {
    echo "FALHOU: {$descricao}\n  " . $e::class . ': ' . $e->getMessage() . "\n  em " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
echo $ok . ' passaram, ' . count($falhas) . " falharam.\n";
exit($falhas === [] ? 0 : 1);
