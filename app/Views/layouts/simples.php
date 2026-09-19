<?php
/** Layout sem shell (login). @var string $conteudo */
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?= \App\Core\View::partial('partials/head', ['titulo' => $titulo ?? '']) ?>
</head>
<body class="min-h-svh">
<main class="grid min-h-svh place-items-center p-4">
    <?= $conteudo ?>
</main>
<div id="toaster" class="toaster" aria-live="polite"></div>
<script type="module" src="<?= e(asset('vendor/basecoat/all.min.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/ui.js')) ?>"></script>
</body>
</html>
