<?php

declare(strict_types=1);

namespace App\Core;

/** Token CSRF em sessão; aceito em _csrf (formulário) ou header X-CSRF-Token (fetch). */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::obter('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::definir('_csrf', $token);
        }
        return $token;
    }

    public static function validar(?string $recebido): bool
    {
        $esperado = Session::obter('_csrf');
        return is_string($esperado) && $esperado !== ''
            && is_string($recebido) && hash_equals($esperado, $recebido);
    }

    /** Middleware global: exige token em métodos que alteram estado. */
    public static function middleware(array $contexto): ?Response
    {
        if (in_array($contexto['metodo'], ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        if (($contexto['opcoes']['csrf'] ?? true) === false) {
            return null;
        }
        $recebido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? null);
        if (self::validar(is_string($recebido) ? $recebido : null)) {
            return null;
        }
        return str_starts_with($contexto['caminho'], '/api/')
            ? Response::json(['erro' => 'Token CSRF inválido.'], 419)
            : Response::texto('Sessão expirada ou token inválido. Recarregue a página.', 419);
    }
}
