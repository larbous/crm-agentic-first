<?php
/** Layout das páginas públicas (proposta e contrato): sem shell, sem indexação. @var string $conteudo */
use App\Core\Session;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?= \App\Core\View::partial('partials/head', ['titulo' => $titulo ?? '']) ?>
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
</head>
<body class="pagina-impressao">
<?= $conteudo ?>
<div id="toaster" class="toaster" aria-live="polite"></div>
<script type="application/json" id="flash-dados"><?= json_encode(Session::pegarFlash(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="<?= e(asset('vendor/basecoat/all.min.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/ui.js')) ?>"></script>
</body>
</html>
