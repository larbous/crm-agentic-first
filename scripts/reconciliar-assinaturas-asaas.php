<?php

declare(strict_types=1);

/**
 * Reconciliação das assinaturas do Asaas depois da mudança para uma cobrança por ciclo (migração 0027).
 *
 * Até a 0027, cada assinatura era uma linha só, sobrescrita a cada ciclo: com o Asaas gerando o ciclo seguinte 40 dias
 * antes do vencimento, o boleto em aberto do mês sumia do CRM, e o pagamento dele marcava o mês seguinte como pago.
 * Este script reaplica o último estado conhecido de cada pagamento de assinatura (guardado em asaas_webhooks) pelo
 * caminho normal do webhook: cada pagamento passa a ter a própria linha, com vencimento, status e link corretos.
 * Assinatura arquivada no CRM fica de fora. Pode rodar mais de uma vez: o que já está certo não muda.
 *
 * Uso: php scripts/reconciliar-assinaturas-asaas.php             simula (mostra o resultado e desfaz tudo)
 *      php scripts/reconciliar-assinaturas-asaas.php --aplicar   grava
 *
 * Rode depois de `php scripts/migrate.php` e com backup do banco.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Repositories\AsaasWebhookRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Asaas\Client as AsaasClient;

$aplicar = in_array('--aplicar', $argv, true);

// Último payload de cada pagamento de assinatura, e o último evento que diz algo sobre o status dele.
$pagamentos = [];
foreach ((new AsaasWebhookRepository())->eventosDePagamento() as $w) {
    $p = (array) (json_decode((string) $w['payload'], true)['payment'] ?? []);
    if (empty($p['id']) || empty($p['subscription'])) {
        continue;
    }
    $atual = $pagamentos[$p['id']] ?? ['evento_status' => null];
    $atual['payment'] = $p;
    if (AsaasClient::mapearStatus((string) $w['evento']) !== null) {
        $atual['evento_status'] = (string) $w['evento'];
    }
    $pagamentos[$p['id']] = $atual;
}
uasort($pagamentos, static fn (array $a, array $b): int => [$a['payment']['subscription'], $a['payment']['dueDate'] ?? '']
    <=> [$b['payment']['subscription'], $b['payment']['dueDate'] ?? '']);

/** Evento a reaplicar: o status do próprio payload (o mais recente que o Asaas mandou) manda; sem ele, o último evento de status. */
$eventoPara = static function (array $item): string {
    $p = $item['payment'];
    if (!empty($p['deleted'])) {
        return 'PAYMENT_DELETED';
    }
    return match ((string) ($p['status'] ?? '')) {
        'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => 'PAYMENT_RECEIVED',
        'OVERDUE' => 'PAYMENT_OVERDUE',
        'REFUNDED', 'REFUND_IN_PROGRESS' => 'PAYMENT_REFUNDED',
        'PENDING' => 'PAYMENT_CREATED',
        default => $item['evento_status'] ?? 'PAYMENT_UPDATED',
    };
};

$foto = static function (): array {
    $linhas = [];
    foreach (Repositorios::cobrancas()->deAssinaturas() as $c) {
        $linhas[(int) $c['id']] = $c;
    }
    return $linhas;
};
$resumo = static fn (array $c): string => sprintf(
    '#%d %s | %s | %s | %s | %s%s',
    $c['id'], $c['empresa_nome'], data_br((string) $c['vencimento']), moeda((int) $c['valor']), $c['status'],
    $c['asaas_payment_id'] ?? 'sem pagamento', $c['arquivado_em'] !== null ? ' | ARQUIVADA' : ''
);

$pdo = DB::conexao();
$antes = $foto();
$pdo->beginTransaction();
$x = new ActionExecutor();
$mensagens = [];
try {
    foreach ($pagamentos as $id => $item) {
        $r = $x->processarEventoAsaas($eventoPara($item), $item['payment']);
        $mensagens[] = sprintf('%s (venc. %s): %s', $id, data_br((string) ($item['payment']['dueDate'] ?? '')), $r->mensagem);
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Erro, nada foi gravado: ' . $e->getMessage() . "\n");
    exit(1);
}
$depois = $foto();
$aplicar ? $pdo->commit() : $pdo->rollBack();

echo $aplicar ? "=== RECONCILIAÇÃO APLICADA ===\n" : "=== SIMULAÇÃO (nada foi gravado; use --aplicar para gravar) ===\n";
echo "\nPagamentos de assinatura reprocessados: " . count($pagamentos) . "\n";
foreach ($mensagens as $m) {
    echo "  {$m}\n";
}
echo "\nLinhas novas:\n";
$novas = array_diff_key($depois, $antes);
echo $novas === [] ? "  (nenhuma)\n" : '';
foreach ($novas as $c) {
    echo '  + ' . $resumo($c) . "\n";
}
echo "\nLinhas alteradas:\n";
$alteradas = 0;
foreach (array_intersect_key($depois, $antes) as $id => $c) {
    $a = $antes[$id];
    if ($resumo($a) !== $resumo($c) || $a['url_fatura'] !== $c['url_fatura']) {
        $alteradas++;
        echo '  antes:  ' . $resumo($a) . "\n  depois: " . $resumo($c) . "\n";
    }
}
echo $alteradas === 0 ? "  (nenhuma)\n" : '';
