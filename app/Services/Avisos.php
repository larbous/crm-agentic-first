<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Services\Canais\Canais;
use App\Services\Canais\Email;
use Throwable;

/**
 * Avisos por e-mail para o operador (o e-mail de login de cada usuário ativo): contrato assinado e comentário do cliente.
 * Usa só o SMTP do canal de e-mail (`canais.email` no config.local.php — serve o relay SMTP do Resend); não precisa do IMAP.
 * Nunca quebra a ação que o disparou: sem SMTP configurado não faz nada, e falhas só vão para o log de erros do PHP.
 */
final class Avisos
{
    public static function contratoAssinado(array $c): void
    {
        $nome = (string) $c['assinatura_nome'];
        self::enviar(
            "Contrato {$c['numero']} assinado por {$nome}",
            "O contrato {$c['numero']} — {$c['titulo']} foi assinado.\n\n"
            . "Assinado por: {$nome} (" . documento_formatado((string) $c['assinatura_documento']) . ")\n"
            . 'E-mail: ' . $c['assinatura_email'] . "\n"
            . 'Data e hora: ' . datahora_br((string) $c['assinado_em']) . "\n"
            . 'Código de verificação: ' . Assinatura::hashLegivel($c['assinatura_hash']) . "\n\n"
            . 'Abrir no CRM: ' . url_publica('/contratos/' . (int) $c['id']) . "\n",
        );
    }

    public static function contratoComentado(array $c, string $autor, string $texto): void
    {
        self::enviar(
            "Comentário no contrato {$c['numero']}",
            "{$autor} comentou no contrato {$c['numero']} — {$c['titulo']}:\n\n{$texto}\n\n"
            . 'Responder no CRM: ' . url_publica('/contratos/' . (int) $c['id']) . "#comentarios\n",
        );
    }

    /** SMTP completo? (host, remetente e credenciais — as do SMTP ou, se faltarem, as do login do IMAP) */
    public static function configurado(): bool
    {
        $c = Canais::config('email');
        $tem = static fn (string ...$chaves): bool => array_reduce($chaves, static fn (bool $ok, string $k): bool => $ok || trim((string) ($c[$k] ?? '')) !== '', false);
        return trim((string) ($c['smtp_host'] ?? '')) !== '' && trim((string) ($c['de_email'] ?? '')) !== ''
            && $tem('smtp_usuario', 'usuario') && $tem('smtp_senha', 'senha');
    }

    /** @return list<string> e-mails de login dos usuários ativos */
    public static function destinatarios(): array
    {
        return DB::conexao()->query('SELECT email FROM usuarios WHERE arquivado_em IS NULL ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function enviar(string $assunto, string $texto): void
    {
        if (!self::configurado()) {
            return;
        }
        foreach (self::destinatarios() as $email) {
            try {
                $r = Email::enviar($email, $assunto, $texto);
                if (!$r['ok']) {
                    error_log("Aviso por e-mail para {$email} falhou: " . $r['erro']);
                }
            } catch (Throwable $e) {
                error_log('Aviso por e-mail: ' . $e->getMessage());
            }
        }
    }
}
