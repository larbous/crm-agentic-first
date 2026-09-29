<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LoginTentativaRepository;
use PDOException;

/**
 * Limite de tentativas de login (força bruta). Duas travas independentes, numa janela deslizante:
 *  - por IP: MAX_POR_IP falhas bloqueiam aquele IP (o caso normal: um script tentando senhas);
 *  - por e-mail: MAX_POR_EMAIL falhas somando todos os IPs bloqueiam esse e-mail (ataque distribuído). O teto é bem
 *    maior que o do IP para que um invasor não consiga trancar o operador de fora só errando de propósito.
 * Usa só REMOTE_ADDR (nunca X-Forwarded-For, que o cliente controla). Atrás de proxy reverso, configure o servidor
 * para repassar o IP real em REMOTE_ADDR.
 */
final class LoginLimite
{
    public const JANELA_MINUTOS = 15;
    public const MAX_POR_IP = 5;
    public const MAX_POR_EMAIL = 20;

    public function __construct(private readonly LoginTentativaRepository $repo = new LoginTentativaRepository())
    {
    }

    public static function ipDaRequisicao(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }

    private static function desde(): string
    {
        return date('Y-m-d H:i:s', time() - self::JANELA_MINUTOS * 60);
    }

    /**
     * Minutos até poder tentar de novo, ou null se não está bloqueado. Se a tabela ainda não existe (código novo no
     * servidor antes de rodar as migrações), não bloqueia: um deploy fora de ordem não pode trancar o operador de fora.
     */
    public function bloqueadoPor(string $ip, string $email): ?int
    {
        try {
            return $this->calcularBloqueio($ip, $email);
        } catch (PDOException $e) {
            error_log('LoginLimite: ' . $e->getMessage() . ' (rode php scripts/migrate.php)');
            return null;
        }
    }

    private function calcularBloqueio(string $ip, string $email): ?int
    {
        $desde = self::desde();
        $email = mb_strtolower(trim($email));
        $porIp = $this->repo->contarPorIp($ip, $desde) >= self::MAX_POR_IP;
        $porEmail = $email !== '' && $this->repo->contarPorEmail($email, $desde) >= self::MAX_POR_EMAIL;
        if (!$porIp && !$porEmail) {
            return null;
        }
        $maisAntiga = $this->repo->maisAntigaDoIp($ip, $desde);
        $libera = ($maisAntiga !== null ? strtotime($maisAntiga) : time()) + self::JANELA_MINUTOS * 60;
        return max(1, (int) ceil(($libera - time()) / 60));
    }

    public function registrarFalha(string $ip, string $email): void
    {
        try {
            $this->repo->registrar($ip, mb_strtolower(trim($email)));
            if (random_int(1, 20) === 1) {
                $this->repo->limparAntigas(date('Y-m-d H:i:s', time() - 24 * 3600));
            }
        } catch (PDOException $e) {
            error_log('LoginLimite: ' . $e->getMessage());
        }
    }

    public function registrarSucesso(string $ip): void
    {
        try {
            $this->repo->limparDoIp($ip);
        } catch (PDOException $e) {
            error_log('LoginLimite: ' . $e->getMessage());
        }
    }
}
