<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\UsuarioRepository;
use App\Services\Instalador;

/**
 * Instalação por cópia, sem CLI/SSH (rota pública `/instalar`): cria o primeiro usuário quando a tabela `usuarios`
 * está vazia. Some sozinha depois que o primeiro usuário existe — criar um segundo usuário continua sendo
 * `php scripts/criar-usuario.php` (ou uma tela de Configurações, se um dia existir).
 */
final class InstalacaoController
{
    public function formulario(): Response
    {
        if (!Instalador::precisaDoPrimeiroUsuario()) {
            return Response::redirecionar(url('/login'));
        }
        return View::pagina('auth/instalar', [
            'titulo' => 'Instalação', 'valores' => ['nome' => '', 'email' => ''], 'erro' => null,
        ], 200, 'layouts/simples');
    }

    public function criar(): Response
    {
        if (!Instalador::precisaDoPrimeiroUsuario()) {
            return Response::redirecionar(url('/login'));
        }

        $valores = ['nome' => (string) ($_POST['nome'] ?? ''), 'email' => mb_strtolower(trim((string) ($_POST['email'] ?? '')))];
        $v = new Validator(
            $_POST,
            ['nome' => 'required|max:120', 'email' => 'required|email|max:190', 'senha' => 'required|min:8|max:200'],
            ['nome' => 'nome', 'email' => 'e-mail', 'senha' => 'senha'],
        );
        if ($v->falha()) {
            return $this->comErro($valores, implode(' ', $v->erros()));
        }

        $repo = new UsuarioRepository();
        if ($repo->buscarPorEmail($valores['email']) !== null) {
            return $this->comErro($valores, 'Já existe um usuário com este e-mail.');
        }

        $repo->criar($valores['nome'], $valores['email'], password_hash((string) $_POST['senha'], PASSWORD_DEFAULT));
        Auth::tentar($valores['email'], (string) $_POST['senha']); // já entra direto no CRM recém-instalado
        return Response::redirecionar(url('/'));
    }

    private function comErro(array $valores, string $erro): Response
    {
        return View::pagina('auth/instalar', ['titulo' => 'Instalação', 'valores' => $valores, 'erro' => $erro], 422, 'layouts/simples');
    }
}
