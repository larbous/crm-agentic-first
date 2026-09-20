<?php

declare(strict_types=1);

/**
 * Dados iniciais: pipeline padrão com etapas, origens, motivos de perda e configurações (SPEC §4.12).
 * Idempotente — pode ser executado mais de uma vez. Uso: php scripts/seed.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Repositories\SeedRepository;

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

    // Fase 3: tipos de contrato, modelos de exemplo, dados da agência e validade padrão das propostas
    $seed->garantirNomes('contrato_tipos', ['Desenvolvimento', 'Manutenção', 'Hospedagem', 'Consultoria', 'Tráfego']);
    foreach (require dirname(__DIR__) . '/library/modelos.php' as [$tipo, $nome, $assunto, $conteudo]) {
        $seed->garantirModelo($tipo, $nome, $assunto, $conteudo);
    }
    $seed->garantirConfiguracao('empresa.nome', 'Lárbous');
    $seed->garantirConfiguracao('proposta.validade_dias', '15');

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Falha no seed: {$e->getMessage()}\n");
    exit(1);
}

echo "Seed concluído.\n";
