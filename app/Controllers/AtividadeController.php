<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\ActionExecutor;
use App\Services\Schema;

/** Criação rápida de atividades a partir da timeline e arquivamento. */
final class AtividadeController
{
    public function criar(): Response
    {
        $entrada = Schema::filtrar('atividades', $_POST);
        $r = (new ActionExecutor())->criar('atividades', $entrada, 'humano');
        if ($r->ok) {
            Session::flash('success', 'Atividade registrada.');
        } else {
            Session::flash('error', $r->mensagem);
        }
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/')));
    }

    public function arquivar(array $p): Response
    {
        $r = (new ActionExecutor())->arquivar('atividades', (int) $p['id'], 'humano');
        Session::flash($r->ok ? 'success' : 'error', $r->ok ? 'Atividade arquivada.' : $r->mensagem);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/')));
    }
}
