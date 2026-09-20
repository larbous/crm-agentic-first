<?php
/** Layout de impressão (A4), independente do shell da aplicação (SPEC §12.10). @var string $conteudo */
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?= \App\Core\View::partial('partials/head', ['titulo' => $titulo ?? '']) ?>
<meta name="robots" content="noindex, nofollow">
</head>
<body class="pagina-impressao">
<?= $conteudo ?>
<script type="module" src="<?= e(asset('js/ui.js')) ?>"></script>
</body>
</html>
