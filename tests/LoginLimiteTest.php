<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Core\DB;
use App\Repositories\LoginTentativaRepository;
use App\Repositories\UsuarioRepository;
use App\Services\LoginLimite;

// Proteção contra força bruta no login: bloqueio por IP e por e-mail, janela deslizante, sucesso zera o IP.

function loginComoRequisicao(string $ip, string $email, string $senha): int
{
    $_SERVER['REMOTE_ADDR'] = $ip;
    $_POST = ['email' => $email, 'senha' => $senha];
    try {
        return (new AuthController())->login()->status;
    } finally {
        $_POST = [];
        unset($_SERVER['REMOTE_ADDR']);
    }
}

function operadorDeTeste(): void
{
    (new UsuarioRepository())->criar('Operador', 'op@teste.com', password_hash('senha-certa-123', PASSWORD_DEFAULT));
}

teste('login: 5 falhas do mesmo IP bloqueiam (429), mesmo com a senha certa', function () {
    bancoDeTeste();
    operadorDeTeste();
    for ($i = 0; $i < LoginLimite::MAX_POR_IP; $i++) {
        igual(422, loginComoRequisicao('203.0.113.7', 'op@teste.com', 'errada'), "falha {$i}");
    }
    igual(429, loginComoRequisicao('203.0.113.7', 'op@teste.com', 'senha-certa-123'), 'bloqueado até com a senha certa');
    igual(429, loginComoRequisicao('203.0.113.7', 'outro@teste.com', 'x'), 'o bloqueio é do IP, vale para qualquer e-mail');
});

teste('login: outro IP não é afetado pelo bloqueio, e a senha certa entra (302)', function () {
    bancoDeTeste();
    operadorDeTeste();
    for ($i = 0; $i < LoginLimite::MAX_POR_IP; $i++) {
        loginComoRequisicao('203.0.113.7', 'op@teste.com', 'errada');
    }
    igual(302, loginComoRequisicao('198.51.100.9', 'op@teste.com', 'senha-certa-123'));
});

teste('login: sucesso zera as falhas daquele IP', function () {
    bancoDeTeste();
    operadorDeTeste();
    for ($i = 0; $i < LoginLimite::MAX_POR_IP - 1; $i++) {
        loginComoRequisicao('203.0.113.7', 'op@teste.com', 'errada');
    }
    igual(302, loginComoRequisicao('203.0.113.7', 'op@teste.com', 'senha-certa-123'));
    igual(0, (new LoginTentativaRepository())->contarPorIp('203.0.113.7', '2000-01-01 00:00:00'));
    igual(422, loginComoRequisicao('203.0.113.7', 'op@teste.com', 'errada'), 'contagem recomeçou do zero');
});

teste('login: falhas antigas (fora da janela) não contam', function () {
    $pdo = bancoDeTeste();
    operadorDeTeste();
    $velho = date('Y-m-d H:i:s', time() - (LoginLimite::JANELA_MINUTOS + 1) * 60);
    for ($i = 0; $i < 10; $i++) {
        $pdo->prepare('INSERT INTO login_tentativas (ip, email, criado_em) VALUES (?, ?, ?)')->execute(['203.0.113.7', 'op@teste.com', $velho]);
    }
    igual(302, loginComoRequisicao('203.0.113.7', 'op@teste.com', 'senha-certa-123'));
});

teste('login: ataque distribuído (muitos IPs, mesmo e-mail) bloqueia o e-mail, e informa os minutos de espera', function () {
    $pdo = bancoDeTeste();
    operadorDeTeste();
    for ($i = 0; $i < LoginLimite::MAX_POR_EMAIL; $i++) {
        $pdo->prepare('INSERT INTO login_tentativas (ip, email, criado_em) VALUES (?, ?, ?)')->execute(["10.0.0.{$i}", 'op@teste.com', agora()]);
    }
    igual(429, loginComoRequisicao('192.0.2.50', 'op@teste.com', 'senha-certa-123'));
    verdadeiro((new LoginLimite())->bloqueadoPor('192.0.2.50', 'op@teste.com') >= 1);
    igual(422, loginComoRequisicao('192.0.2.50', 'outro-email-sem-falhas@teste.com', 'x'), 'e-mail sem falhas segue o fluxo normal (senha errada = 422, não 429)');
});

teste('login: se a tabela ainda não existe (deploy antes da migração), não bloqueia nem quebra o login', function () {
    $pdo = bancoDeTeste();
    operadorDeTeste();
    $pdo->exec('DROP TABLE login_tentativas');
    igual(302, loginComoRequisicao('203.0.113.7', 'op@teste.com', 'senha-certa-123'));
    igual(422, loginComoRequisicao('203.0.113.7', 'op@teste.com', 'errada'));
});
