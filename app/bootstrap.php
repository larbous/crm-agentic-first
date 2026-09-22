<?php

declare(strict_types=1);

/**
 * Inicialização comum: autoloader PSR-4 (App\ → /app), configuração, fuso e helpers.
 * Usado pelo front controller, pelos scripts CLI e pelos testes.
 *
 * CRM Lárbous — github.com/larbous/crm-agentic-first — licença MIT.
 */

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'App\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
        return;
    }
    $arquivo = __DIR__ . '/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});

require __DIR__ . '/Core/Helpers.php';

foreach (glob(__DIR__ . '/Views/components/*.php') ?: [] as $componente) {
    require_once $componente;
}

$config = require dirname(__DIR__) . '/config.php';
\App\Core\Config::definir($config);

date_default_timezone_set((string) \App\Core\Config::obter('app.fuso', 'America/Sao_Paulo'));
mb_internal_encoding('UTF-8');

// Gatilhos por evento de agentes e squads (Fase 6): só enfileiram; quem executa é o worker.
\App\Services\Gatilhos::registrar();
