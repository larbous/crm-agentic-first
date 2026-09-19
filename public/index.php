<?php

declare(strict_types=1);

/**
 * Front controller — única porta de entrada HTTP.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;

foreach ([
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options'        => 'SAMEORIGIN',
    'Referrer-Policy'        => 'strict-origin-when-cross-origin',
] as $nome => $valor) {
    header($nome . ': ' . $valor);
}

try {
    Session::iniciar();

    $caminho = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $base = rtrim((string) Config::obter('app.base_url', ''), '/');
    if ($base !== '' && str_starts_with($caminho, $base)) {
        $caminho = substr($caminho, strlen($base)) ?: '/';
    }

    View::compartilhar('caminho', $caminho);
    View::compartilhar('usuario', Auth::usuario());

    $router = new Router();
    $router->global([Csrf::class, 'middleware']);
    (require dirname(__DIR__) . '/app/routes.php')($router);

    $router->despachar($_SERVER['REQUEST_METHOD'] ?? 'GET', $caminho)->enviar();
} catch (Throwable $e) {
    error_log((string) $e);
    $debug = (bool) Config::obter('app.debug', false);
    (new Response(
        $debug ? '<pre>' . e((string) $e) . '</pre>' : 'Erro interno. Tente novamente em instantes.',
        500,
        ['Content-Type' => 'text/html; charset=utf-8'],
    ))->enviar();
}
