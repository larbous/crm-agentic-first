<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\BuscaRepository;

/** Busca global (topo do layout): GET /api/busca?q= */
final class BuscaController
{
    public function buscar(): Response
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            return Response::json(['grupos' => []]);
        }
        $r = (new BuscaRepository())->buscar($q);
        $rotulos = ['empresas' => ['Empresas', '/empresas/'], 'contatos' => ['Contatos', '/contatos/'], 'negocios' => ['Negócios', '/negocios/']];
        $grupos = [];
        foreach ($rotulos as $chave => [$rotulo, $base]) {
            if ($r[$chave] === []) {
                continue;
            }
            $grupos[] = [
                'rotulo' => $rotulo,
                'itens'  => array_map(static fn (array $i) => [
                    'titulo' => $i['titulo'], 'subtitulo' => $i['subtitulo'] ?? '', 'url' => url($base . (int) $i['id']),
                ], $r[$chave]),
            ];
        }
        return Response::json(['grupos' => $grupos]);
    }
}
