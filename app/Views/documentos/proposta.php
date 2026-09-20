<?php
/**
 * Documento da proposta (usado na impressão e no link público).
 * @var array $p proposta
 * @var list<array> $itens
 * @var array|null $empresa
 * @var array|null $contato
 * @var array $agencia
 */
use App\Services\Html;
use App\Services\Schema;

$secoes = ['apresentacao' => 'Apresentação', 'escopo' => 'Escopo', 'fora_escopo' => 'Fora do escopo', 'cronograma' => 'Cronograma', 'garantia' => 'Garantia', 'observacoes' => 'Observações'];
$desconto = 0;
if ((int) $p['desconto_valor'] > 0) {
    $desconto = $p['desconto_tipo'] === 'percentual' ? (int) round((int) $p['subtotal'] * (int) $p['desconto_valor'] / 10000) : (int) $p['desconto_valor'];
}
$condicoes = array_filter([
    'Forma de pagamento' => $p['forma_pagamento'],
    'Parcelas' => $p['parcelas'] ? $p['parcelas'] . 'x' : null,
    'Entrada' => $p['entrada_percentual'] !== null ? $p['entrada_percentual'] . '%' : null,
    'Prazo de entrega' => $p['prazo_entrega_dias'] !== null ? $p['prazo_entrega_dias'] . ' dias' : null,
], static fn ($v) => $v !== null && $v !== '');
?>
<article class="documento">
    <header class="documento-cabecalho">
        <div>
            <div class="documento-marca"><?= e($agencia['nome'] ?: 'Lárbous') ?></div>
            <div class="documento-mini">
                <?= e(implode(' · ', array_filter([$agencia['cnpj'] ? 'CNPJ ' . $agencia['cnpj'] : '', $agencia['email'], $agencia['telefone'], $agencia['site']]))) ?>
            </div>
        </div>
        <div class="documento-lado">
            <div class="documento-tipo">Proposta comercial</div>
            <div><strong><?= e($p['numero']) ?></strong> · versão <?= (int) $p['versao'] ?></div>
            <div class="documento-mini">Emitida em <?= e(data_br($p['data_emissao'])) ?><?= $p['validade'] ? ' · válida até ' . e(data_br($p['validade'])) : '' ?></div>
        </div>
    </header>

    <section class="documento-partes">
        <div>
            <div class="documento-rotulo">Para</div>
            <div><strong><?= e($empresa['razao_social'] ?? $empresa['nome_fantasia'] ?? '—') ?></strong></div>
            <?php if (!empty($empresa['cnpj'])): ?><div class="documento-mini">CNPJ <?= e(cnpj_formatado($empresa['cnpj'])) ?></div><?php endif; ?>
        </div>
        <?php if ($contato): ?>
            <div>
                <div class="documento-rotulo">A/C</div>
                <div><?= e(trim($contato['nome'] . ' ' . ($contato['sobrenome'] ?? ''))) ?></div>
                <?php if ($contato['cargo']): ?><div class="documento-mini"><?= e($contato['cargo']) ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <h1 class="documento-titulo"><?= e($p['titulo']) ?></h1>

    <?php if (trim((string) $p['apresentacao']) !== ''): ?>
        <section class="documento-secao"><?= Html::textoParaHtml((string) $p['apresentacao']) ?></section>
    <?php endif; ?>

    <?php if ($itens !== []): ?>
    <section class="documento-secao">
        <h2>Investimento</h2>
        <table class="documento-tabela">
            <thead><tr><th>Descrição</th><th class="num">Qtd.</th><th class="num">Unitário</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($itens as $i): ?>
                <tr>
                    <td><?= e($i['descricao']) ?><?= (int) $i['recorrente'] === 1 ? ' <em class="documento-mini">(recorrente)</em>' : '' ?>
                        <?php if ((int) $i['desconto'] > 0): ?><div class="documento-mini">desconto de <?= e(moeda((int) $i['desconto'])) ?></div><?php endif; ?></td>
                    <td class="num"><?= e(rtrim(rtrim(number_format((float) $i['quantidade'], 3, ',', '.'), '0'), ',') . ($i['unidade'] ? ' ' . $i['unidade'] : '')) ?></td>
                    <td class="num"><?= e(moeda((int) $i['valor_unitario'])) ?></td>
                    <td class="num"><?= e(moeda((int) $i['total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php if ($desconto > 0): ?>
                    <tr><td colspan="3" class="num">Subtotal</td><td class="num"><?= e(moeda((int) $p['subtotal'])) ?></td></tr>
                    <tr><td colspan="3" class="num">Desconto<?= $p['desconto_tipo'] === 'percentual' ? ' (' . e(percentual_br((int) $p['desconto_valor'])) . ')' : '' ?></td><td class="num">− <?= e(moeda($desconto)) ?></td></tr>
                <?php endif; ?>
                <tr class="total"><td colspan="3" class="num">Total</td><td class="num"><?= e(moeda((int) $p['total'])) ?></td></tr>
                <?php if ((int) $p['total_recorrente'] > 0): ?>
                    <tr><td colspan="3" class="num documento-mini">Parte recorrente do total</td><td class="num documento-mini"><?= e(moeda((int) $p['total_recorrente'])) ?></td></tr>
                <?php endif; ?>
            </tfoot>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($condicoes !== [] || trim((string) $p['condicoes_pagamento']) !== ''): ?>
    <section class="documento-secao">
        <h2>Condições</h2>
        <?php if ($condicoes !== []): ?>
            <dl class="documento-lista">
                <?php foreach ($condicoes as $rotulo => $valor): ?><div><dt><?= e($rotulo) ?></dt><dd><?= e($valor) ?></dd></div><?php endforeach; ?>
            </dl>
        <?php endif; ?>
        <?= Html::textoParaHtml((string) $p['condicoes_pagamento']) ?>
    </section>
    <?php endif; ?>

    <?php foreach (array_slice($secoes, 1, null, true) as $campo => $rotulo): ?>
        <?php if (trim((string) $p[$campo]) !== ''): ?>
            <section class="documento-secao"><h2><?= e($rotulo) ?></h2><?= Html::textoParaHtml((string) $p[$campo]) ?></section>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($p['status'] === 'aceita'): ?>
        <section class="documento-aceite">
            <strong>Proposta aceita eletronicamente</strong>
            <div>Por <?= e($p['aceite_nome']) ?> (<?= e(documento_formatado($p['aceite_documento'])) ?>) em <?= e(datahora_br($p['respondida_em'])) ?> · IP <?= e($p['aceite_ip']) ?></div>
        </section>
    <?php endif; ?>
</article>
