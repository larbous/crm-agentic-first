<?php

declare(strict_types=1);

/**
 * Cria um usuário (ou redefine a senha, com --redefinir).
 * Uso: php scripts/criar-usuario.php "Nome" email@dominio.com [senha] [--redefinir]
 * Sem a senha na linha de comando, ela é solicitada (ficará visível no terminal).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Validator;
use App\Repositories\UsuarioRepository;

$argumentos = array_values(array_filter(array_slice($argv, 1), static fn ($a) => $a !== '--redefinir'));
$redefinir = in_array('--redefinir', $argv, true);

if (count($argumentos) < 2) {
    fwrite(STDERR, "Uso: php scripts/criar-usuario.php \"Nome\" email@dominio.com [senha] [--redefinir]\n");
    exit(1);
}

[$nome, $email] = $argumentos;
$email = mb_strtolower(trim($email));
$senha = $argumentos[2] ?? null;

if ($senha === null) {
    echo 'Senha (mín. 8 caracteres): ';
    $senha = trim((string) fgets(STDIN));
}

$v = new Validator(
    ['nome' => $nome, 'email' => $email, 'senha' => $senha],
    ['nome' => 'required|max:120', 'email' => 'required|email|max:190', 'senha' => 'required|min:8|max:200'],
    ['nome' => 'nome', 'email' => 'e-mail', 'senha' => 'senha'],
);
if ($v->falha()) {
    foreach ($v->erros() as $erro) {
        fwrite(STDERR, $erro . "\n");
    }
    exit(1);
}

$repo = new UsuarioRepository();
$existente = $repo->buscarPorEmail($email);
$hash = password_hash($senha, PASSWORD_DEFAULT);

if ($existente !== null) {
    if (!$redefinir) {
        fwrite(STDERR, "Já existe usuário com este e-mail. Use --redefinir para trocar a senha.\n");
        exit(1);
    }
    $repo->atualizarSenha((int) $existente['id'], $hash);
    echo "Senha de {$email} redefinida.\n";
    exit(0);
}

$id = $repo->criar($nome, $email, $hash);
echo "Usuário #{$id} ({$email}) criado.\n";
