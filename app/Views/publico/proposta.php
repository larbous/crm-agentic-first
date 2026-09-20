<?php
/**
 * Página pública da proposta: documento + aceite/recusa.
 * @var array $p
 * @var string $token
 * @var array $erros
 * @var array $valores
 * @var bool $superada
 * @var bool $expirada
 * @var bool $podeResponder
 */
use App\Core\View;

$acao = static fn (string $caminho): string => e(url('/p/' . $token . $caminho));
?>
<div class="documento-barra no-print">
    <div class="text-sm font-medium">Proposta <?= e($p['numero']) ?></div>
    <?= botao('Imprimir / salvar PDF', ['icone' => 'printer', 'variante' => 'outline', 'attrs' => ['data-imprimir' => true]]) ?>
</div>

<div class="documento-avisos no-print">
    <?php if ($superada): ?>
        <?= alerta('Existe uma versão mais recente desta proposta', 'Esta versão foi substituída. Peça à agência o link da versão atual.', 'default', 'info') ?>
    <?php elseif ($expirada): ?>
        <?= alerta('Proposta expirada', 'A validade desta proposta terminou em ' . data_br($p['validade']) . '. Fale com a agência para receber uma atualização.', 'destructive') ?>
    <?php elseif ($p['status'] === 'aceita'): ?>
        <?= alerta('Proposta aceita', 'Aceite registrado em ' . datahora_br($p['respondida_em']) . '. A agência entrará em contato com os próximos passos.', 'default', 'circle-check') ?>
    <?php elseif ($p['status'] === 'recusada'): ?>
        <?= alerta('Proposta recusada', 'Resposta registrada em ' . datahora_br($p['respondida_em']) . '.', 'default', 'info') ?>
    <?php endif; ?>
</div>

<?= View::partial('documentos/proposta', get_defined_vars()) ?>

<?php if ($podeResponder): ?>
<section class="documento-acao no-print" aria-labelledby="titulo-resposta">
    <h2 id="titulo-resposta">Responder à proposta</h2>
    <form method="post" action="<?= $acao('/aceitar') ?>" class="grid gap-4" novalidate>
        <?= csrf_field() ?>
        <div class="sr-only" aria-hidden="true"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <?php if (!empty($erros['_'])): ?><?= alerta('Não foi possível registrar', (string) $erros['_'], 'destructive') ?><?php endif; ?>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= campo(['nome' => 'nome', 'rotulo' => 'Seu nome completo', 'valor' => $valores['nome'] ?? '', 'erro' => $erros['nome'] ?? null, 'obrigatorio' => true, 'attrs' => ['autocomplete' => 'name']]) ?>
            <?= campo(['nome' => 'documento', 'rotulo' => 'CPF ou CNPJ', 'valor' => $valores['documento'] ?? '', 'erro' => $erros['documento'] ?? null, 'obrigatorio' => true,
                'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off']]) ?>
        </div>
        <?= campo(['nome' => 'concordo', 'rotulo' => 'Li a proposta e concordo com o escopo, os valores e as condições.', 'tipo' => 'checkbox', 'valor_marcado' => '1',
            'valor' => ($valores['concordo'] ?? '') === '1', 'erro' => $erros['concordo'] ?? null]) ?>
        <p class="text-muted-foreground text-xs">Ao aceitar, registramos seu nome, documento, data/hora e endereço IP como comprovação do aceite eletrônico.</p>
        <div><?= botao('Aceitar proposta', ['tipo' => 'submit', 'icone' => 'check']) ?></div>
    </form>

    <details class="documento-recusa">
        <summary>Prefere recusar?</summary>
        <form method="post" action="<?= $acao('/recusar') ?>" class="mt-3 grid gap-3" novalidate>
            <?= csrf_field() ?>
            <div class="sr-only" aria-hidden="true"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <?= campo(['nome' => 'motivo', 'rotulo' => 'Motivo (opcional)', 'tipo' => 'textarea', 'linhas' => 3, 'valor' => $valores['motivo'] ?? '', 'erro' => $erros['motivo'] ?? null, 'attrs' => ['maxlength' => 500]]) ?>
            <div><?= botao('Recusar proposta', ['tipo' => 'submit', 'variante' => 'outline', 'classe' => 'text-destructive']) ?></div>
        </form>
    </details>
</section>
<?php endif; ?>
