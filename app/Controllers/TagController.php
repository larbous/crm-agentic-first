<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\ActionExecutor;

/** Atribuição de tags a empresas, contatos e negócios (o cadastro das tags fica em Configurações). */
final class TagController
{
    public function definir(): Response
    {
        $tags = array_filter((array) ($_POST['tags'] ?? []), 'is_scalar');
        $r = (new ActionExecutor())->definirTags((string) ($_POST['entidade'] ?? ''), (int) ($_POST['registro_id'] ?? 0), $tags, 'humano');
        if ($r->ok) {
            if ($r->mensagem !== 'Nada a alterar.') {
                Session::flash('success', $r->mensagem);
            }
        } else {
            Session::flash('error', $r->mensagem);
        }
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/')));
    }
}
