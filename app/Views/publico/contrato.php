<?php
/**
 * Página pública do contrato: documento + assinatura.
 * @var array $c
 * @var string $token
 * @var array $erros
 * @var array $valores
 * @var bool $podeAssinar
 */
use App\Core\View;
?>
<div class="documento-barra no-print">
    <div class="text-sm font-medium">Contrato <?= e($c['numero']) ?></div>
    <?= botao('Imprimir / salvar PDF', ['icone' => 'printer', 'variante' => 'outline', 'attrs' => ['data-imprimir' => true]]) ?>
</div>

<div class="documento-avisos no-print">
    <?php if (in_array($c['status'], ['assinado', 'ativo', 'vencido', 'renovado'], true) && $c['assinado_em']): ?>
        <?= alerta('Contrato assinado', 'Assinatura registrada em ' . datahora_br($c['assinado_em']) . '. Guarde uma cópia (use “Imprimir / salvar PDF”).', 'default', 'circle-check') ?>
    <?php elseif ($c['status'] === 'cancelado'): ?>
        <?= alerta('Contrato cancelado', 'Este contrato foi cancelado e não pode mais ser assinado.', 'destructive') ?>
    <?php endif; ?>
</div>

<?= View::partial('documentos/contrato', get_defined_vars()) ?>

<?php if ($podeAssinar): ?>
<section class="documento-acao no-print" aria-labelledby="titulo-assinatura">
    <h2 id="titulo-assinatura">Assinar contrato</h2>
    <form method="post" action="<?= e(url('/c/' . $token . '/assinar')) ?>" class="grid gap-4" novalidate>
        <?= csrf_field() ?>
        <div class="sr-only" aria-hidden="true"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php if (!empty($erros['_'])): ?><?= alerta('Não foi possível registrar', (string) $erros['_'], 'destructive') ?><?php endif; ?>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= campo(['nome' => 'nome', 'rotulo' => 'Nome completo', 'valor' => $valores['nome'] ?? '', 'erro' => $erros['nome'] ?? null, 'obrigatorio' => true, 'attrs' => ['autocomplete' => 'name']]) ?>
            <?= campo(['nome' => 'documento', 'rotulo' => 'CPF ou CNPJ', 'valor' => $valores['documento'] ?? '', 'erro' => $erros['documento'] ?? null, 'obrigatorio' => true,
                'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off']]) ?>
        </div>
        <?= campo(['nome' => 'concordo', 'rotulo' => 'Li o contrato e concordo com todos os seus termos.', 'tipo' => 'checkbox', 'valor_marcado' => '1',
            'valor' => ($valores['concordo'] ?? '') === '1', 'erro' => $erros['concordo'] ?? null]) ?>
        <p class="text-muted-foreground text-xs">A assinatura eletrônica registra seu nome, documento, data/hora e endereço IP.</p>
        <div><?= botao('Assinar contrato', ['tipo' => 'submit', 'icone' => 'check']) ?></div>
    </form>
</section>
<?php endif; ?>
