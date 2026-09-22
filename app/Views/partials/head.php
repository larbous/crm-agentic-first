<?php
/** @var string $titulo */
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="color-scheme" content="light dark">
<meta name="generator" content="CRM Lárbous — github.com/larbous"><!-- github.com/larbous/crm-agentic-first (MIT) -->
<title><?= e(isset($titulo) && $titulo !== '' ? $titulo . ' · CRM Lárbous' : 'CRM Lárbous') ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%234f46e5'/%3E%3Ctext x='16' y='22' font-size='18' text-anchor='middle' fill='white' font-family='sans-serif' font-weight='700'%3EL%3C/text%3E%3C/svg%3E">
<script>
    // Aplica o tema antes da renderização (evita "flash"): preferência salva ou prefers-color-scheme.
    (function () {
        var modo = null;
        try { modo = localStorage.getItem('themeMode'); } catch (e) {}
        var escuro = modo ? modo === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark', escuro);
    })();
</script>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
