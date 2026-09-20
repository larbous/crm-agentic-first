<?php
/**
 * Layout do formulário público: sem shell, sem sessão, sempre no tema claro (pode estar dentro de um iframe em site alheio).
 * @var string $conteudo
 * @var string $titulo
 * @var bool $embed
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo ?? 'Formulário') ?></title>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="fp-corpo<?= !empty($embed) ? ' fp-embed' : '' ?>">
<?= $conteudo ?>
<script type="module" src="<?= e(asset('js/formulario-publico.js')) ?>"></script>
</body>
</html>
