<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AuditoriaRepository;
use App\Services\ActionExecutor;
use App\Services\Schema;

/** Log de auditoria filtrável com botão Desfazer (SPEC §10). */
final class AuditoriaController
{
    public function index(): Response
    {
        $filtros = [];
        foreach (['entidade', 'origem', 'acao'] as $campo) {
            $filtros[$campo] = trim((string) ($_GET[$campo] ?? ''));
        }
        foreach (['registro_id', 'execucao_id'] as $campo) {
            if (ctype_digit((string) ($_GET[$campo] ?? ''))) {
                $filtros[$campo] = (string) $_GET[$campo];
            }
        }
        $repo = new AuditoriaRepository();
        $resultado = $repo->listar($filtros, (int) ($_GET['pagina'] ?? 1));
        $ultima = $repo->ultimaDesfazivel();
        $entidades = [];
        foreach (array_diff(Schema::nomes(), ['proposta_itens']) as $nome) {
            $entidades[$nome] = Schema::entidade($nome)['plural'];
        }

        return View::pagina('auditoria/index', [
            'titulo'    => 'Auditoria',
            'filtros'   => $filtros,
            'resultado' => $resultado,
            'ultimaId'  => $ultima['id'] ?? null,
            'entidades' => $entidades,
        ]);
    }

    public function desfazer(array $p): Response
    {
        return $this->executar((int) $p['id']);
    }

    public function desfazerUltima(): Response
    {
        return $this->executar(null);
    }

    private function executar(?int $logId): Response
    {
        $r = (new ActionExecutor())->desfazer($logId, 'humano');
        Session::flash($r->ok ? 'success' : 'error', $r->mensagem);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/auditoria')));
    }
}
