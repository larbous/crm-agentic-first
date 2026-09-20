<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;

/** Páginas do layout base (Início e telas ainda vazias, que ganham conteúdo nas próximas fases). */
final class PaginaController
{
    public function inicio(): Response
    {
        $repo = \App\Repositories\Repositorios::tarefas();
        $limite = date('Y-m-d', strtotime('+7 days'));
        return View::pagina('paginas/inicio', [
            'titulo'    => 'Início',
            'painel_chat' => false, // o chat fica central na página
            'atrasadas' => $repo->grupo('atrasadas', hoje(), $limite),
            'hoje'      => $repo->grupo('hoje', hoje(), $limite),
        ]);
    }

    /** Opções da rota: titulo, icone, fase. */
    public function emBreve(array $params, array $opcoes): Response
    {
        return View::pagina('paginas/em_breve', [
            'titulo' => $opcoes['titulo'] ?? 'Em breve',
            'icone'  => $opcoes['icone'] ?? 'inbox',
            'fase'   => $opcoes['fase'] ?? 'próxima fase',
        ]);
    }

    public function guiaEstilo(): Response
    {
        return View::pagina('paginas/ui', ['titulo' => 'Guia de estilo']);
    }

    /** GET /api/ping — verificação simples de sessão para o JS. */
    public function ping(): Response
    {
        return Response::json(['ok' => true, 'hora' => agora()]);
    }
}
