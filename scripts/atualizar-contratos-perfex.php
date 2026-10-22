<?php

declare(strict_types=1);

/**
 * Atualiza o histórico de contratos já importado do Perfex: traduz as tags de mesclagem ({client_company} → {empresa.nome}…),
 * preenche os campos que o importador original deixou nas notas/campos personalizados (representante legal, valor promocional,
 * desconto, responsável da empresa) e traz os modelos de contrato do Perfex (tbltemplates) como modelos do CRM.
 * Uso: php scripts/atualizar-contratos-perfex.php <caminho-do-dump.sql> [--simular]
 *
 * Rode antes `php scripts/migrate.php` (migração 0024) e faça backup do banco. Só preenche campos vazios e é seguro rodar
 * mais de uma vez. Aponte outro banco com a variável de ambiente CRM_DB_CAMINHO.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Audit;
use App\Services\Events;
use App\Services\Importacao\DumpSqlReader;
use App\Services\TagsPerfex;

Events::limpar();

$caminho = $argv[1] ?? null;
$simular = in_array('--simular', $argv, true);
if ($caminho === null || !is_file($caminho)) {
    fwrite(STDERR, "Uso: php scripts/atualizar-contratos-perfex.php <caminho-do-dump.sql> [--simular]\n");
    exit(1);
}

$pdo = DB::conexao();
$colunas = array_column($pdo->query('PRAGMA table_info(contratos)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('representante_nome', $colunas, true)) {
    fwrite(STDERR, "Rode antes: php scripts/migrate.php (migração 0024).\n");
    exit(1);
}

echo $simular ? "Modo simulação (nada será gravado).\n" : "Modo real — gravando no banco.\n";
$dump = new DumpSqlReader($caminho);

// ---- campos personalizados do Perfex: "fieldto:relid" => [slug => valor]
$slugPorCampo = [];
foreach ($dump->linhas('tblcustomfields') as $c) {
    $slugPorCampo[(int) $c['id']] = (string) $c['slug'];
}
$personalizados = [];
foreach ($dump->linhas('tblcustomfieldsvalues') as $v) {
    $valor = trim(html_entity_decode((string) ($v['value'] ?? ''), ENT_QUOTES, 'UTF-8'));
    if ($valor !== '' && isset($slugPorCampo[(int) $v['fieldid']])) {
        $personalizados[$v['fieldto'] . ':' . $v['relid']][$slugPorCampo[(int) $v['fieldid']]] = $valor;
    }
}

$texto = static fn (?string $v): ?string => ($v = trim(html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8'))) === '' ? null : $v;
/** "89,90" / "R$ 1.250,50" / "89.90" → centavos (ou centésimos de %, para "30%") */
$centavos = static function (?string $v): ?int {
    $t = preg_replace('/[^0-9,.\-]/', '', (string) $v) ?? '';
    if ($t === '') {
        return null;
    }
    $t = str_contains($t, ',') ? str_replace(',', '.', str_replace('.', '', $t)) : (preg_match('/\.\d{3}$/', $t) ? str_replace('.', '', $t) : $t);
    return is_numeric($t) ? (int) round((float) $t * 100) : null;
};
$cpf = static function (?string $v): ?string {
    $d = so_digitos($v);
    return strlen($d) === 11 || strlen($d) === 14 ? $d : null;
};

$stats = ['contratos_texto' => 0, 'contratos_campos' => 0, 'empresas_responsavel' => 0, 'modelos_criados' => 0, 'modelos_existentes' => 0];
$restantes = [];

$gravar = static function (string $entidade, int $id, array $campos, string $acao) use ($simular): void {
    if ($campos === [] || $simular) {
        return;
    }
    $repo = Repositorios::para($entidade);
    $atual = $repo->encontrar($id, true) ?? [];
    $repo->atualizar($id, $campos + ['atualizado_em' => agora()]);
    // O texto longo do contrato não vai inteiro para a auditoria.
    $resumo = static fn (array $d): array => array_map(static fn ($v) => is_string($v) && mb_strlen($v) > 200 ? '[texto alterado]' : $v, $d);
    Audit::registrar('sistema', $entidade, $id, $acao, $resumo(array_intersect_key($atual, $campos)), $resumo($campos));
};

// ---- contratos
foreach ($dump->linhas('tblcontracts') as $c) {
    $perfexId = (int) $c['id'];
    $st = $pdo->prepare('SELECT * FROM contratos WHERE notas LIKE ?');
    $st->execute(['Importado do Perfex CRM (contrato #' . $perfexId . ')%']);
    $contrato = $st->fetch(PDO::FETCH_ASSOC);
    if ($contrato === false) {
        echo "- contrato Perfex #{$perfexId}: não encontrado no CRM (ignorado)\n";
        continue;
    }
    $campos = [];
    $novoTexto = TagsPerfex::converter((string) $contrato['conteudo']);
    if ($novoTexto !== (string) $contrato['conteudo']) {
        $campos['conteudo'] = $novoTexto;
        $stats['contratos_texto']++;
    }
    $restantes[$contrato['numero']] = TagsPerfex::restantes($novoTexto);

    $extra = $personalizados['contracts:' . $perfexId] ?? [];
    $candidatos = [
        'representante_nome' => $texto($extra['contracts_representante_legal'] ?? null),
        'representante_cpf' => $cpf($extra['contracts_cpf_do_representante'] ?? null),
        'representante_rg' => $texto($extra['contracts_rg_do_representante'] ?? null),
        'valor_promocional' => $centavos($extra['contracts_valor_promocional'] ?? null),
        'percentual_desconto' => $centavos(str_replace('%', '', $extra['contracts_percentual_desconto'] ?? '')),
        'periodo_desconto' => $texto($extra['contracts_periodo_desconto'] ?? null),
    ];
    $preenchidos = 0;
    foreach ($candidatos as $campo => $valor) {
        if ($valor !== null && $contrato[$campo] === null) {
            $campos[$campo] = $valor;
            $preenchidos++;
        }
    }
    $stats['contratos_campos'] += $preenchidos > 0 ? 1 : 0;
    $gravar('contratos', (int) $contrato['id'], $campos, 'atualizar_tags_perfex');
}

// ---- empresas: responsável pelo contrato (estava só no texto das notas)
foreach ($dump->linhas('tblclients') as $cli) {
    $userid = (int) $cli['userid'];
    $extra = $personalizados['customers:' . $userid] ?? [];
    $candidatos = [
        'responsavel_nome' => $texto($extra['company_responsavel_contrato'] ?? null),
        'responsavel_cpf' => $cpf($extra['company_responsavel_cpf'] ?? null),
        'responsavel_rg' => $texto($extra['company_responsavel_rg'] ?? null),
    ];
    if (array_filter($candidatos, static fn ($v) => $v !== null) === []) {
        continue;
    }
    $st = $pdo->prepare('SELECT * FROM empresas WHERE notas LIKE ?');
    $st->execute(['Importado do Perfex CRM (cliente #' . $userid . ').%']);
    $empresa = $st->fetch(PDO::FETCH_ASSOC);
    if ($empresa === false) {
        continue;
    }
    $campos = [];
    foreach ($candidatos as $campo => $valor) {
        if ($valor !== null && $empresa[$campo] === null) {
            $campos[$campo] = $valor;
        }
    }
    if ($campos !== []) {
        $stats['empresas_responsavel']++;
        $gravar('empresas', (int) $empresa['id'], $campos, 'atualizar_tags_perfex');
    }
}

// ---- modelos de contrato do Perfex (tbltemplates)
$exec = new ActionExecutor();
foreach ($dump->linhas('tbltemplates') as $t) {
    if ($t['type'] !== 'contracts') {
        continue;
    }
    $nome = (string) $texto((string) $t['name']);
    $existe = $pdo->prepare("SELECT COUNT(*) FROM modelos_documento WHERE tipo = 'contrato' AND nome = ?");
    $existe->execute([$nome]);
    if ((int) $existe->fetchColumn() > 0) {
        $stats['modelos_existentes']++;
        continue;
    }
    $conteudo = TagsPerfex::converter(html_entity_decode((string) $t['content'], ENT_QUOTES, 'UTF-8'));
    $restantes['modelo: ' . $nome] = TagsPerfex::restantes($conteudo);
    if (!$simular) {
        $r = $exec->criar('modelos_documento', ['tipo' => 'contrato', 'nome' => $nome, 'conteudo' => $conteudo, 'ativo' => 1], 'sistema');
        if (!$r->ok) {
            echo "- modelo \"{$nome}\": não criado — " . json_encode($r->erros, JSON_UNESCAPED_UNICODE) . "\n";
            continue;
        }
    }
    $stats['modelos_criados']++;
}

echo "\n=== Resultado ===\n";
foreach ($stats as $chave => $n) {
    echo str_pad($chave, 24) . $n . "\n";
}
$sobras = array_filter($restantes);
echo "\n=== Tags sem equivalente que continuam no texto (" . count($sobras) . " documento(s)) ===\n";
foreach ($sobras as $onde => $tags) {
    echo "- {$onde}: " . implode(' ', $tags) . "\n";
}
echo "\nConcluído.\n";
