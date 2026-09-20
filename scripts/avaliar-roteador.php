<?php

declare(strict_types=1);

/**
 * Mede o acerto do roteador de IA com as frases de tests/frases.json (ROADMAP Fase 4: meta ≥ 90%).
 *
 * Uso:
 *   php scripts/avaliar-roteador.php              chama a API real (exige anthropic.api_key em config.local.php)
 *   php scripts/avaliar-roteador.php --offline    só confere que o fixture é válido no contrato (sem API, sem custo)
 *   php scripts/avaliar-roteador.php --verbose    mostra a saída do modelo em cada frase
 *
 * Roda num banco SQLite em memória: não toca em storage/db/crm.sqlite. Uma frase acerta quando a saída
 * do modelo passa na validação por whitelist E contém tudo o que está em "esperado".
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\DB;
use App\Repositories\MigracaoRepository;
use App\Services\AI\CommandRouter;
use App\Services\AI\ContratoRoteador;
use App\Services\AI\IaErro;

$offline = in_array('--offline', $argv, true);
$verboso = in_array('--verbose', $argv, true);

$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/tests/frases.json'), true, 512, JSON_THROW_ON_ERROR);
$frases = $fixture['frases'];
$hoje = (string) $fixture['hoje'];

/** Igualdade tolerante: sem acento/caixa; datas com "T"/segundos; telefone só com dígitos; "*" = qualquer valor. */
function iguais(mixed $esperado, mixed $obtido, string $chave = ''): bool
{
    if ($esperado === '*') {
        return $obtido !== null && $obtido !== '' && $obtido !== [];
    }
    if (is_array($esperado) && array_is_list($esperado) && $chave === 'op') {
        return in_array(strtolower((string) $obtido), array_map('strtolower', $esperado), true);
    }
    if (is_numeric($esperado) && is_numeric($obtido)) {
        return (float) $esperado === (float) $obtido;
    }
    if (is_string($esperado) && is_scalar($obtido)) {
        $o = (string) $obtido;
        if (in_array($chave, ['telefone', 'whatsapp'], true)) {
            return so_digitos($esperado) === so_digitos($o);
        }
        if ($chave === 'vencimento') {
            $o = preg_replace('/^(\d{4}-\d{2}-\d{2})T/', '$1 ', $o) ?? $o;
            $o = preg_replace('/(\d{2}:\d{2}):00$/', '$1', $o) ?? $o;
            // Esperado só com a data: aceita a mesma data com hora.
            return strlen($esperado) === 10 ? str_starts_with($o, $esperado) : $o === $esperado;
        }
        return normalizar_busca($esperado) === normalizar_busca($o);
    }
    return $esperado === $obtido;
}

/** Cada chave de $esperado (objeto) precisa existir e bater em $obtido. */
function contem_subconjunto(array $esperado, mixed $obtido, string &$motivo, string $caminho = ''): bool
{
    if (!is_array($obtido)) {
        $motivo = "{$caminho}: ausente";
        return false;
    }
    foreach ($esperado as $chave => $valor) {
        $onde = $caminho === '' ? (string) $chave : "{$caminho}.{$chave}";
        if (!array_key_exists($chave, $obtido)) {
            $motivo = "{$onde}: ausente";
            return false;
        }
        if (is_array($valor) && !array_is_list($valor)) {
            if (!contem_subconjunto($valor, $obtido[$chave], $motivo, $onde)) {
                return false;
            }
        } elseif (!iguais($valor, $obtido[$chave], (string) $chave)) {
            $motivo = "{$onde}: esperado " . json_encode($valor, JSON_UNESCAPED_UNICODE) . ', veio ' . json_encode($obtido[$chave], JSON_UNESCAPED_UNICODE);
            return false;
        }
    }
    return true;
}

/** "filtro": cada condição esperada [campo, op, valor?] precisa aparecer no filtro devolvido. */
function filtro_confere(array $esperado, mixed $obtido, string &$motivo): bool
{
    foreach ($esperado as $cond) {
        $achou = false;
        foreach (is_array($obtido) ? $obtido : [] as $c) {
            if (!is_array($c) || !isset($c[0], $c[1]) || !iguais($cond[0], $c[0]) || !iguais($cond[1], $c[1], 'op')) {
                continue;
            }
            if (!array_key_exists(2, $cond) || (isset($c[2]) && iguais($cond[2], $c[2]))) {
                $achou = true;
                break;
            }
        }
        if (!$achou) {
            $motivo = 'filtro sem ' . json_encode($cond, JSON_UNESCAPED_UNICODE);
            return false;
        }
    }
    return true;
}

function confere(array $esperado, mixed $bruto, string &$motivo): bool
{
    $semFiltro = array_diff_key($esperado, ['filtro' => 1]);
    return contem_subconjunto($semFiltro, $bruto, $motivo)
        && (!isset($esperado['filtro']) || filtro_confere($esperado['filtro'], $bruto['filtro'] ?? null, $motivo));
}

// ---- Modo offline: o próprio fixture precisa ser válido no contrato ---------------------

if ($offline) {
    /** O esperado é parcial: completa o que o contrato exige (pergunta do "indefinido") e escolhe a 1ª alternativa de "op". */
    $instanciar = static function (array $esperado): array {
        if (($esperado['tipo'] ?? '') === 'indefinido') {
            $esperado['pergunta'] = 'Pergunta de teste?';
        }
        foreach ($esperado['filtro'] ?? [] as $k => $cond) {
            if (is_array($cond[1] ?? null)) {
                $esperado['filtro'][$k][1] = $cond[1][0];
            }
        }
        return $esperado;
    };
    $invalidas = 0;
    foreach ($frases as $i => $f) {
        $v = ContratoRoteador::validar($instanciar($f['esperado']));
        if (!$v['ok']) {
            $invalidas++;
            echo sprintf("#%02d INVÁLIDA no contrato (%s): %s\n", $i + 1, $v['motivo'], $f['mensagem']);
        }
    }
    echo count($frases) . ' frases, ' . $invalidas . " inválidas no contrato.\n";
    exit($invalidas === 0 ? 0 : 1);
}

// ---- Modo online -------------------------------------------------------------------------

if ((string) Config::obter('anthropic.api_key', '') === '') {
    fwrite(STDERR, "Defina anthropic.api_key em config.local.php (ou use --offline).\n");
    exit(2);
}

$pdo = DB::conectar(':memory:');
$migracoes = new MigracaoRepository($pdo);
$migracoes->garantirTabela();
$arquivos = glob(rtrim((string) Config::obter('caminhos.migracoes'), '/\\') . '/*.sql') ?: [];
sort($arquivos);
foreach ($arquivos as $arquivo) {
    $migracoes->aplicar(basename($arquivo), (string) file_get_contents($arquivo));
}
DB::definir($pdo);

$router = new CommandRouter();
$acertos = 0;
foreach ($frases as $i => $f) {
    $rotulo = sprintf('#%02d %s', $i + 1, $f['mensagem']);
    try {
        $r = $router->rotear($f['mensagem'], $f['tela'] ?? null, $f['ultima_ref'] ?? null, $hoje);
    } catch (IaErro $e) {
        echo "ERRO  {$rotulo}\n      {$e->getMessage()}\n";
        continue;
    }
    $motivo = '';
    $ok = $r['ok'] && confere($f['esperado'], $r['bruto'], $motivo);
    if (!$r['ok']) {
        $motivo = 'recusada pela validação: ' . $r['motivo'];
    }
    $acertos += $ok ? 1 : 0;
    echo ($ok ? 'OK    ' : 'FALHA ') . $rotulo . "\n";
    if (!$ok) {
        echo "      {$motivo}\n";
    }
    if ($verboso || !$ok) {
        echo '      saída: ' . json_encode($r['bruto'], JSON_UNESCAPED_UNICODE) . "\n";
    }
}

$total = count($frases);
$pct = $total > 0 ? $acertos / $total * 100 : 0;
$uso = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(tokens_entrada), 0) AS e, COALESCE(SUM(tokens_saida), 0) AS s, COALESCE(AVG(duracao_ms), 0) AS ms FROM execucoes")->fetch();
printf("\nAcertos: %d/%d (%.1f%%) — meta: 90%%\n", $acertos, $total, $pct);
printf("Chamadas: %d · tokens de entrada: %d · saída: %d · duração média: %d ms\n", $uso['n'], $uso['e'], $uso['s'], $uso['ms']);
exit($pct >= 90 ? 0 : 1);
