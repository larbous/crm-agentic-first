<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AgenteRepository;
use App\Repositories\ExecucaoRepository;

/** Histórico de execuções de IA (SPEC §9): status, tokens, erros e totais do mês por modelo. */
final class ExecucaoController
{
    public static function registrar(Router $r): void
    {
        $r->get('/execucoes', [self::class, 'index']);
    }

    public function index(): Response
    {
        $filtros = [];
        foreach (['status', 'tipo'] as $campo) {
            $filtros[$campo] = trim((string) ($_GET[$campo] ?? ''));
        }
        $filtros['agente_id'] = ctype_digit((string) ($_GET['agente_id'] ?? '')) ? (string) $_GET['agente_id'] : '';
        $mes = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($_GET['mes'] ?? '')) === 1 ? (string) $_GET['mes'] : date('Y-m');

        $repo = new ExecucaoRepository();
        return View::pagina('execucoes/index', [
            'titulo'    => 'Execuções',
            'filtros'   => $filtros,
            'resultado' => $repo->listar($filtros, (int) ($_GET['pagina'] ?? 1)),
            'mes'       => $mes,
            'totais'    => $repo->totaisDoMes($mes),
            'agentes'   => array_column((new AgenteRepository())->todos(), 'nome', 'id'),
        ]);
    }
}
