<?php
/**
 * Documento do contrato (usado na impressão e no link público).
 * @var array $c contrato
 * @var array|null $empresa
 * @var array|null $contato
 * @var array $agencia
 */
use App\Services\Html;

$assinado = in_array($c['status'], ['assinado', 'ativo', 'vencido', 'renovado'], true) && $c['assinado_em'] !== null;
?>
<article class="documento">
    <header class="documento-cabecalho">
        <div>
            <div class="documento-marca"><?= e($agencia['nome'] ?: 'Lárbous') ?></div>
            <div class="documento-mini"><?= e(implode(' · ', array_filter([$agencia['cnpj'] ? 'CNPJ ' . $agencia['cnpj'] : '', $agencia['email'], $agencia['telefone']]))) ?></div>
        </div>
        <div class="documento-lado">
            <div class="documento-tipo">Contrato</div>
            <div><strong><?= e($c['numero']) ?></strong></div>
            <?php if ($c['data_inicio'] || $c['data_fim']): ?>
                <div class="documento-mini">Vigência: <?= e(data_br($c['data_inicio']) ?: '—') ?> a <?= e(data_br($c['data_fim']) ?: 'prazo indeterminado') ?></div>
            <?php endif; ?>
        </div>
    </header>

    <h1 class="documento-titulo"><?= e($c['titulo']) ?></h1>

    <div class="documento-corpo"><?= Html::sanitizar((string) $c['conteudo']) ?></div>

    <?php if (trim((string) $c['clausulas_especiais']) !== ''): ?>
        <section class="documento-secao"><h2>Cláusulas especiais</h2><?= Html::textoParaHtml((string) $c['clausulas_especiais']) ?></section>
    <?php endif; ?>

    <section class="documento-assinaturas">
        <?php if ($assinado): ?>
            <div class="documento-aceite">
                <strong>Assinado eletronicamente</strong>
                <div>Por <?= e($c['assinatura_nome']) ?> (<?= e(documento_formatado($c['assinatura_documento'])) ?>) em <?= e(datahora_br($c['assinado_em'])) ?> · IP <?= e($c['assinatura_ip']) ?></div>
            </div>
        <?php else: ?>
            <div class="documento-linha-assinatura"><div class="linha"></div><div><?= e($agencia['responsavel'] ?: ($agencia['nome'] ?: 'Contratada')) ?></div><div class="documento-mini">Contratada</div></div>
            <div class="documento-linha-assinatura"><div class="linha"></div><div><?= e($empresa['razao_social'] ?? $empresa['nome_fantasia'] ?? 'Contratante') ?></div><div class="documento-mini">Contratante</div></div>
        <?php endif; ?>
    </section>
</article>
