<?php

declare(strict_types=1);

/**
 * Dados iniciais: pipeline padrão com etapas, origens, motivos de perda e configurações (SPEC §4.12).
 * Idempotente — pode ser executado mais de uma vez. Uso: php scripts/seed.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Repositories\AgenteRepository;
use App\Repositories\SquadRepository;
use App\Repositories\SeedRepository;
use App\Services\ActionExecutor;

$pdo = DB::conexao();
$seed = new SeedRepository($pdo);

$pdo->beginTransaction();
try {
    // [nome, probabilidade padrão, cor, tipo]
    $seed->garantirPipeline('Vendas', [
        ['Novo lead', 10, '#64748b', 'aberta'],
        ['Qualificado', 25, '#0ea5e9', 'aberta'],
        ['Reunião', 40, '#8b5cf6', 'aberta'],
        ['Proposta', 60, '#f59e0b', 'aberta'],
        ['Negociação', 80, '#f97316', 'aberta'],
        ['Ganho', 100, '#22c55e', 'ganho'],
        ['Perdido', 0, '#ef4444', 'perdido'],
    ]);

    $seed->garantirNomes('origens', [
        'Indicação', 'Instagram', 'Google', 'Site', 'Formulário',
        'WhatsApp', 'Evento', 'Prospecção ativa', 'Outro',
    ]);

    $seed->garantirNomes('motivos_perda', [
        'Preço', 'Prazo', 'Escolheu concorrente', 'Sem orçamento',
        'Sem resposta', 'Projeto adiado', 'Outro',
    ]);

    $seed->garantirConfiguracao('fuso', 'America/Sao_Paulo');

    // Fase 13: áreas (departamentos) dos chamados
    $seed->garantirNomes('areas', ['Tráfego Pago', 'Design', 'Social Media', 'Web', 'Redação']);

    // Fase 3: tipos de contrato, modelos de exemplo, dados da agência e validade padrão das propostas
    $seed->garantirNomes('contrato_tipos', ['Desenvolvimento', 'Manutenção', 'Hospedagem', 'Consultoria', 'Tráfego']);
    foreach (require dirname(__DIR__) . '/library/modelos.php' as [$tipo, $nome, $assunto, $conteudo]) {
        $seed->garantirModelo($tipo, $nome, $assunto, $conteudo);
    }
    $seed->garantirConfiguracao('empresa.nome', 'Lárbous');
    $seed->garantirConfiguracao('proposta.validade_dias', '15');

    // Fase 5: biblioteca inicial de agentes (/library/*.agent.json). Só cria os que ainda não existem:
    // agentes já importados (e editados pelo operador) nunca são sobrescritos.
    $agentes = new AgenteRepository();
    $executor = new ActionExecutor();
    foreach (glob(dirname(__DIR__) . '/library/*.agent.json') ?: [] as $arquivo) {
        $json = (string) file_get_contents($arquivo);
        $definicao = json_decode($json, true);
        if (is_array($definicao) && isset($definicao['slug']) && $agentes->porSlug((string) $definicao['slug']) !== null) {
            continue;
        }
        $r = $executor->salvarAgente($json);
        if (!$r->ok) {
            throw new RuntimeException(basename($arquivo) . ': ' . $r->mensagem);
        }
    }

    // Fase 6: biblioteca inicial de squads (/library/*.squad.json), depois dos agentes que eles citam. Mesma regra: nunca sobrescreve.
    $squads = new SquadRepository();
    foreach (glob(dirname(__DIR__) . '/library/*.squad.json') ?: [] as $arquivo) {
        $json = (string) file_get_contents($arquivo);
        $definicao = json_decode($json, true);
        if (is_array($definicao) && isset($definicao['slug']) && $squads->porSlug((string) $definicao['slug']) !== null) {
            continue;
        }
        $r = $executor->salvarSquad($json);
        if (!$r->ok) {
            throw new RuntimeException(basename($arquivo) . ': ' . implode(' ', $r->erros ?: [$r->mensagem]));
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Falha no seed: {$e->getMessage()}\n");
    exit(1);
}

echo "Seed concluído.\n";
