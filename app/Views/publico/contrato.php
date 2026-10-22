<?php
/**
 * Página pública do contrato: documento, assinatura digital (desenho) e comentários.
 * @var array $c
 * @var string $token
 * @var array $erros erros da assinatura (campo => mensagem) e, em 'comentario', os do comentário
 * @var array $valores
 * @var bool $podeAssinar
 * @var list<array> $comentarios
 */
use App\Core\View;

$erroComentario = is_array($erros['comentario'] ?? null) ? $erros['comentario'] : [];
$erroAssinatura = array_diff_key($erros, ['comentario' => 1]);
$aceitaComentario = !in_array($c['status'], ['rascunho', 'cancelado'], true);
$clienteNome = trim((string) (($contato['nome'] ?? '') . ' ' . ($contato['sobrenome'] ?? '')));
?>
<div class="documento-barra no-print">
    <div class="text-sm font-medium">Contrato <?= e($c['numero']) ?></div>
    <?= botao('Imprimir / salvar PDF', ['icone' => 'printer', 'variante' => 'outline', 'attrs' => ['data-imprimir' => true]]) ?>
</div>

<div class="documento-avisos no-print">
    <?php if (in_array($c['status'], ['assinado', 'ativo', 'vencido', 'renovado'], true) && $c['assinado_em']): ?>
        <?= alerta('Contrato assinado', 'Assinatura registrada em ' . datahora_br($c['assinado_em']) . '. O comprovante está no fim do documento; guarde uma cópia (use “Imprimir / salvar PDF”).', 'default', 'circle-check') ?>
    <?php elseif ($c['status'] === 'cancelado'): ?>
        <?= alerta('Contrato cancelado', 'Este contrato foi cancelado e não pode mais ser assinado.', 'destructive') ?>
    <?php endif; ?>
</div>

<?= View::partial('documentos/contrato', get_defined_vars()) ?>

<?php if ($podeAssinar || $aceitaComentario): ?>
<section class="documento-acao no-print" aria-labelledby="titulo-assinatura">
    <?php if ($podeAssinar): ?>
        <h2 id="titulo-assinatura">Assinar contrato</h2>
        <p class="text-muted-foreground mb-3 text-sm">Leia o contrato acima. Se estiver de acordo, assine digitalmente; se tiver dúvidas ou quiser pedir um ajuste, deixe um comentário.</p>
    <?php else: ?>
        <h2 id="titulo-assinatura">Dúvidas?</h2>
    <?php endif; ?>
    <div class="flex flex-wrap gap-2">
        <?php if ($podeAssinar): ?>
            <?= botao('Assinar', ['icone' => 'pen-line', 'attrs' => ['type' => 'button', 'data-abrir-dialogo' => 'dialogo-assinar']]) ?>
        <?php endif; ?>
        <?php if ($aceitaComentario): ?>
            <?= botao('Comentar', ['icone' => 'message-square', 'variante' => 'outline', 'attrs' => ['type' => 'button', 'data-abrir-dialogo' => 'dialogo-comentar']]) ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($comentarios !== []): ?>
<section id="comentarios" class="documento-acao no-print" aria-labelledby="titulo-comentarios">
    <h2 id="titulo-comentarios">Comentários</h2>
    <ul class="grid gap-3">
        <?php foreach ($comentarios as $m): ?>
            <li class="comentario" data-autor="<?= e($m['autor']) ?>">
                <div class="text-sm font-medium"><?= e($m['nome']) ?> <span class="text-muted-foreground text-xs font-normal">· <?= $m['autor'] === 'equipe' ? 'equipe' : 'você' ?> · <?= e(datahora_br($m['criado_em'])) ?></span></div>
                <p class="mt-1 text-sm whitespace-pre-line"><?= e($m['texto']) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($podeAssinar): ?>
<dialog id="dialogo-assinar" class="assinatura-dialogo no-print" aria-labelledby="titulo-dialogo-assinar"<?= $erroAssinatura !== [] ? ' data-abrir-ao-carregar' : '' ?>>
    <form method="post" action="<?= e(url('/c/' . $token . '/assinar')) ?>" class="grid gap-4" novalidate data-form-assinatura>
        <?= csrf_field() ?>
        <div class="sr-only" aria-hidden="true"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <h2 id="titulo-dialogo-assinar" class="text-lg font-semibold">Assinar contrato <?= e($c['numero']) ?></h2>
        <?php if (!empty($erroAssinatura['_'])): ?><?= alerta('Não foi possível registrar', (string) $erroAssinatura['_'], 'destructive') ?><?php endif; ?>
        <div class="grid gap-4 sm:grid-cols-2">
            <?= campo(['nome' => 'nome', 'id' => 'ass-nome', 'rotulo' => 'Nome completo', 'valor' => $valores['nome'] ?? $clienteNome, 'erro' => $erroAssinatura['nome'] ?? null, 'obrigatorio' => true, 'attrs' => ['autocomplete' => 'name']]) ?>
            <?= campo(['nome' => 'documento', 'id' => 'ass-documento', 'rotulo' => 'CPF ou CNPJ', 'valor' => $valores['documento'] ?? '', 'erro' => $erroAssinatura['documento'] ?? null, 'obrigatorio' => true,
                'attrs' => ['inputmode' => 'numeric', 'autocomplete' => 'off']]) ?>
        </div>
        <?= campo(['nome' => 'email', 'id' => 'ass-email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'valor' => $valores['email'] ?? ($contato['email'] ?? ''), 'erro' => $erroAssinatura['email'] ?? null, 'obrigatorio' => true, 'attrs' => ['autocomplete' => 'email']]) ?>

        <div class="field">
            <div class="flex items-center justify-between gap-2">
                <label for="ass-pad">Sua assinatura <span class="text-destructive" aria-hidden="true">*</span></label>
                <button type="button" class="btn" data-variant="ghost" data-size="xs" data-pad-limpar>Limpar</button>
            </div>
            <canvas id="ass-pad" class="assinatura-pad" width="600" height="200" data-pad aria-label="Quadro para desenhar a assinatura com o mouse ou o dedo"></canvas>
            <input type="hidden" name="assinatura" value="" data-pad-valor>
            <p class="text-muted-foreground text-xs">Desenhe sua assinatura dentro do quadro, com o mouse ou o dedo.</p>
            <p class="text-destructive text-sm" role="alert" data-pad-erro<?= isset($erroAssinatura['assinatura']) ? '' : ' hidden' ?>><?= e((string) ($erroAssinatura['assinatura'] ?? 'Desenhe sua assinatura no quadro.')) ?></p>
        </div>

        <?= campo(['nome' => 'concordo', 'id' => 'ass-concordo', 'rotulo' => 'Li o contrato e concordo com todos os seus termos.', 'tipo' => 'checkbox', 'valor_marcado' => '1',
            'valor' => ($valores['concordo'] ?? '') === '1', 'erro' => $erroAssinatura['concordo'] ?? null]) ?>
        <p class="text-muted-foreground text-xs">Ao assinar, você reconhece este documento eletrônico e a sua assinatura como válidos (MP 2.200-2/2001, art. 10, § 2º, e Lei 14.063/2020).
            Ficam registrados seu nome, documento, e-mail, o desenho da assinatura, data e hora, endereço IP, navegador e o código de verificação do texto.</p>
        <div class="flex flex-wrap justify-end gap-2">
            <?= botao('Cancelar', ['variante' => 'outline', 'attrs' => ['type' => 'button', 'data-fechar-dialogo' => true]]) ?>
            <?= botao('Assinar contrato', ['tipo' => 'submit', 'icone' => 'check']) ?>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($aceitaComentario): ?>
<dialog id="dialogo-comentar" class="assinatura-dialogo no-print" aria-labelledby="titulo-dialogo-comentar"<?= $erroComentario !== [] ? ' data-abrir-ao-carregar' : '' ?>>
    <form method="post" action="<?= e(url('/c/' . $token . '/comentar')) ?>" class="grid gap-4" novalidate>
        <?= csrf_field() ?>
        <div class="sr-only" aria-hidden="true"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <h2 id="titulo-dialogo-comentar" class="text-lg font-semibold">Comentar no contrato</h2>
        <p class="text-muted-foreground text-sm">Seu comentário é enviado à equipe, que responde aqui mesmo, neste link.</p>
        <?php if (!empty($erroComentario['_'])): ?><?= alerta('Não foi possível enviar', (string) $erroComentario['_'], 'destructive') ?><?php endif; ?>
        <?= campo(['nome' => 'nome', 'id' => 'com-nome', 'rotulo' => 'Seu nome', 'valor' => $erroComentario !== [] ? ($valores['nome'] ?? '') : $clienteNome, 'erro' => $erroComentario['nome'] ?? null, 'obrigatorio' => true, 'attrs' => ['autocomplete' => 'name']]) ?>
        <?= campo(['nome' => 'texto', 'id' => 'com-texto', 'rotulo' => 'Comentário', 'tipo' => 'textarea', 'linhas' => 5, 'valor' => $erroComentario !== [] ? ($valores['texto'] ?? '') : '', 'erro' => $erroComentario['texto'] ?? null, 'obrigatorio' => true, 'attrs' => ['maxlength' => 2000]]) ?>
        <div class="flex flex-wrap justify-end gap-2">
            <?= botao('Cancelar', ['variante' => 'outline', 'attrs' => ['type' => 'button', 'data-fechar-dialogo' => true]]) ?>
            <?= botao('Enviar comentário', ['tipo' => 'submit', 'icone' => 'send']) ?>
        </div>
    </form>
</dialog>
<?php endif; ?>
<script type="module" src="<?= e(asset('js/assinatura.js')) ?>"></script>
