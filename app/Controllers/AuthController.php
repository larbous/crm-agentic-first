<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Services\Instalador;

/** Login e logout. */
final class AuthController
{
    public function formLogin(): Response
    {
        if (Auth::logado()) {
            return Response::redirecionar(url('/'));
        }
        if (Instalador::precisaDoPrimeiroUsuario()) {
            return Response::redirecionar(url('/instalar'));
        }
        return View::pagina('auth/login', ['titulo' => 'Entrar', 'email' => '', 'erro' => null], 200, 'layouts/simples');
    }

    public function login(): Response
    {
        $v = new Validator($_POST, ['email' => 'required|email', 'senha' => 'required'], ['email' => 'e-mail', 'senha' => 'senha']);
        $email = (string) ($_POST['email'] ?? '');

        if ($v->passa() && Auth::tentar($email, (string) $_POST['senha'])) {
            return Response::redirecionar(url('/'));
        }

        $erro = $v->falha() ? implode(' ', $v->erros()) : 'E-mail ou senha inválidos.';
        return View::pagina('auth/login', ['titulo' => 'Entrar', 'email' => $email, 'erro' => $erro], 422, 'layouts/simples');
    }

    public function logout(): Response
    {
        Auth::sair();
        return Response::redirecionar(url('/login'));
    }
}
