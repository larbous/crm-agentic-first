<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\PaginaController;
use App\Core\Auth;
use App\Core\Router;

/** Definição de rotas. Rotas JSON ficam sob /api; telas, sem prefixo. */
return static function (Router $r): void {
    // Públicas
    $r->get('/login', [AuthController::class, 'formLogin']);
    $r->post('/login', [AuthController::class, 'login']);

    // Autenticadas
    $r->grupo('', static function (Router $r): void {
        $r->get('/', [PaginaController::class, 'inicio']);
        $r->post('/logout', [AuthController::class, 'logout']);
        $r->get('/ui', [PaginaController::class, 'guiaEstilo']);

        // Telas que ganham conteúdo nas próximas fases (título, ícone, fase)
        foreach ([
            ['/empresas', 'Empresas', 'building', 'Fase 2'],
            ['/contatos', 'Contatos', 'users', 'Fase 2'],
            ['/negocios', 'Negócios', 'handshake', 'Fase 2'],
            ['/tarefas', 'Tarefas', 'list-checks', 'Fase 2'],
            ['/auditoria', 'Auditoria', 'scroll-text', 'Fase 2'],
            ['/configuracoes', 'Configurações', 'settings', 'Fase 2'],
        ] as [$caminho, $titulo, $icone, $fase]) {
            $r->get($caminho, [PaginaController::class, 'emBreve'], ['titulo' => $titulo, 'icone' => $icone, 'fase' => $fase]);
        }

        $r->grupo('/api', static function (Router $r): void {
            $r->get('/ping', [PaginaController::class, 'ping']);
        });
    }, [[Auth::class, 'exigir']]);
};
