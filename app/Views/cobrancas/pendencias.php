<?php
/**
 * Pagamentos e assinaturas do Asaas que ainda não viraram cobrança no CRM (empresa não encontrada, ou o Asaas
 * não respondeu na hora). O worker tenta de novo sozinho; "Tentar agora" faz o mesmo sem esperar.
 * @var list<array<string,mixed>> $pendencias
 */
$linhas = [];
foreach ($pendencias as $p) {
    $cliente = trim((string) ($p['cliente_nome'] ?? '')) !== '' ? (string) $p['cliente_nome'] : 'Cliente ' . $p['customer_id'];
    $documento = trim((string) ($p['documento'] ?? '')) !== '' ? documento_formatado((string) $p['documento']) : 'sem documento no Asaas';
    $detalhes = [];
    if ($p['valor'] !== null) {
        $detalhes[] = moeda((int) $p['valor']);
    }
    if (!empty($p['vencimento'])) {
        $detalhes[] = 'vence ' . data_br((string) $p['vencimento']);
    }
    $linhas[] = [
        'cliente' => ['html' => '<div class="font-medium">' . e($cliente) . '</div><div class="text-muted-foreground text-xs">' . e($documento) . '</div>'],
        'cobranca' => ['html' => '<div>' . e(trim((string) ($p['descricao'] ?? '')) !== '' ? (string) $p['descricao'] : 'Sem descrição') . '</div>'
            . ($detalhes !== [] ? '<div class="text-muted-foreground text-xs">' . e(implode(' · ', $detalhes)) . '</div>' : '')],
        'motivo' => (string) $p['motivo'],
        'tentativas' => ['html' => e((string) $p['tentativas']) . ' <span class="text-muted-foreground text-xs">· última ' . e(datahora_br((string) $p['ultima_tentativa_em'])) . '</span>'],
    ];
}
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <h1 class="page-titulo">Pendentes de vínculo</h1>
        <p class="text-muted-foreground">Pagamentos e assinaturas criados no Asaas que ainda não têm uma empresa no CRM. Cadastre a empresa (ou preencha o CNPJ/CPF dela) e o CRM importa sozinho em poucos minutos.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Voltar às cobranças', ['href' => url('/financeiro/cobrancas'), 'variante' => 'outline', 'icone' => 'arrow-left']) ?>
        <?php if ($pendencias !== []): ?>
            <form method="post" action="<?= e(url('/financeiro/cobrancas/pendencias/tentar')) ?>">
                <?= csrf_field() ?>
                <?= botao('Tentar agora', ['tipo' => 'submit', 'icone' => 'refresh-cw']) ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($pendencias === []): ?>
    <?= vazio('Nada pendente', 'Todo pagamento criado no Asaas já está vinculado a uma empresa.', ['icone' => 'circle-check']) ?>
<?php else: ?>
    <?= tabela(['cliente' => 'Cliente no Asaas', 'cobranca' => 'Cobrança', 'motivo' => 'Por que está pendente', 'tentativas' => 'Tentativas'], $linhas) ?>
<?php endif; ?>
