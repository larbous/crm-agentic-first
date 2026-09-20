<?php
/**
 * Resultado do envio do formulário público (sucesso, limite de envios ou indisponível).
 * @var string $mensagem
 * @var string|null $redirect endereço para onde levar a pessoa depois (no iframe, quem navega é a página-mãe)
 * @var bool $erro
 */
?>
<main class="fp-pagina">
    <div class="fp-form fp-resultado" <?= $redirect !== null ? 'data-redirect="' . e($redirect) . '"' : '' ?> role="status">
        <?= $erro
            ? alerta((string) $titulo, (string) $mensagem, 'destructive')
            : alerta('Recebido!', (string) $mensagem, 'default', 'circle-check') ?>
        <?php if ($redirect !== null): ?>
            <p class="mt-4"><?= botao('Continuar', ['href' => $redirect, 'variante' => 'outline', 'attrs' => ['target' => '_top']]) ?></p>
        <?php endif; ?>
    </div>
</main>
