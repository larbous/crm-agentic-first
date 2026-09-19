<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UsuarioRepository;

/** Autenticação por sessão (senhas com password_hash/password_verify). */
final class Auth
{
    private static ?array $usuario = null;

    public static function tentar(string $email, string $senha): bool
    {
        $repo = new UsuarioRepository();
        $usuario = $repo->buscarPorEmail(mb_strtolower(trim($email)));

        // Verifica sempre um hash, para não revelar por tempo se o e-mail existe.
        $hash = $usuario['senha_hash'] ?? password_hash('invalido', PASSWORD_DEFAULT);
        $ok = password_verify($senha, $hash) && $usuario !== null && $usuario['arquivado_em'] === null;
        if (!$ok) {
            return false;
        }

        Session::regenerar();
        Session::definir('usuario_id', (int) $usuario['id']);
        Session::definir('_csrf', bin2hex(random_bytes(32)));
        $repo->registrarLogin((int) $usuario['id'], agora());
        self::$usuario = null;
        return true;
    }

    public static function usuario(): ?array
    {
        if (self::$usuario !== null) {
            return self::$usuario;
        }
        $id = Session::obter('usuario_id');
        if (!is_int($id)) {
            return null;
        }
        $usuario = (new UsuarioRepository())->buscarPorId($id);
        if ($usuario === null || $usuario['arquivado_em'] !== null) {
            return null;
        }
        unset($usuario['senha_hash']);
        return self::$usuario = $usuario;
    }

    public static function logado(): bool
    {
        return self::usuario() !== null;
    }

    public static function sair(): void
    {
        self::$usuario = null;
        Session::destruir();
    }

    /** Middleware: exige login; /api/* recebe 401 JSON, telas são redirecionadas ao login. */
    public static function exigir(array $contexto): ?Response
    {
        if (self::logado()) {
            return null;
        }
        return str_starts_with($contexto['caminho'], '/api/')
            ? Response::json(['erro' => 'Não autenticado.'], 401)
            : Response::redirecionar(url('/login'));
    }
}
