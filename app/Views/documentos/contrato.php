<?php
/**
 * Documento do contrato (usado na impressão e no link público).
 * @var array $c contrato
 * @var string $corpo texto do contrato com as variáveis já preenchidas e sanitizado
 * @var array|null $empresa
 * @var array|null $contato
 * @var array $agencia
 * @var string $assinaturaAgencia desenho da assinatura da contratada (data URL), se cadastrado
 */
use App\Services\Assinatura;
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

    <div class="documento-corpo"><?= $corpo ?></div>

    <?php if (trim((string) $c['clausulas_especiais']) !== ''): ?>
        <section class="documento-secao"><h2>Cláusulas especiais</h2><?= Html::textoParaHtml((string) $c['clausulas_especiais']) ?></section>
    <?php endif; ?>

    <?php
    $imgAgencia = Assinatura::imagemValida($assinaturaAgencia ?? '');
    $imgCliente = $assinado ? Assinatura::imagemValida($c['assinatura_imagem'] ?? '') : null;
    ?>
    <section class="documento-assinaturas">
        <div class="documento-linha-assinatura">
            <?php if ($imgAgencia !== null): ?><img class="documento-assinatura-img" src="<?= e($imgAgencia) ?>" alt="Assinatura da contratada"><?php endif; ?>
            <div class="linha"></div><div><?= e($agencia['responsavel'] ?: ($agencia['nome'] ?: 'Contratada')) ?></div><div class="documento-mini">Contratada</div>
        </div>
        <div class="documento-linha-assinatura">
            <?php if ($imgCliente !== null): ?><img class="documento-assinatura-img" src="<?= e($imgCliente) ?>" alt="Assinatura da contratante"><?php endif; ?>
            <div class="linha"></div><div><?= e($assinado && $c['assinatura_nome'] ? $c['assinatura_nome'] : ($empresa['razao_social'] ?? $empresa['nome_fantasia'] ?? 'Contratante')) ?></div><div class="documento-mini">Contratante</div>
        </div>
    </section>

    <?php if ($assinado): ?>
        <section class="documento-aceite documento-comprovante">
            <strong>Comprovante de assinatura eletrônica</strong>
            <dl class="documento-lista">
                <div><dt>Assinado por</dt><dd><?= e($c['assinatura_nome']) ?> — <?= e(documento_formatado($c['assinatura_documento'])) ?></dd></div>
                <?php if (!empty($c['assinatura_email'])): ?><div><dt>E-mail</dt><dd><?= e($c['assinatura_email']) ?></dd></div><?php endif; ?>
                <div><dt>Data e hora</dt><dd><?= e(datahora_br($c['assinado_em'])) ?> (horário de Brasília)</dd></div>
                <div><dt>Endereço IP</dt><dd><?= e($c['assinatura_ip']) ?></dd></div>
                <?php if (!empty($c['assinatura_user_agent'])): ?><div><dt>Navegador</dt><dd><?= e($c['assinatura_user_agent']) ?></dd></div><?php endif; ?>
                <?php if (!empty($c['assinatura_hash'])): ?>
                    <div><dt>Código de verificação (SHA-256)</dt><dd class="font-mono break-all"><?= e(Assinatura::hashLegivel($c['assinatura_hash'])) ?></dd></div>
                    <div><dt>Integridade</dt><dd><?= Assinatura::integro($c) ? 'O texto deste contrato é idêntico ao que foi assinado.' : 'ATENÇÃO: o texto gravado não confere com o código de verificação.' ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="documento-mini">Assinatura eletrônica com registro de identificação, desenho manuscrito, data/hora, IP e código de verificação do texto (MP 2.200-2/2001, art. 10, § 2º; Lei 14.063/2020).</div>
        </section>
    <?php endif; ?>
</article>
