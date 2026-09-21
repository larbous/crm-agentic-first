<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Services\ActionExecutor;
use App\Services\Canais\Meta;
use Throwable;

/**
 * Webhook da Meta (WhatsApp Business Cloud API e Instagram Direct): `/webhooks/meta`.
 * GET responde ao desafio de verificação; POST recebe mensagens e status, sempre com a assinatura `X-Hub-Signature-256`
 * conferida contra o segredo do app. Sem sessão e sem CSRF (chamada de servidor); a assinatura é a defesa. A resposta é
 * rápida: só registra a mensagem (uma escrita curta) e devolve 200; falha inesperada devolve 500 e a Meta reenvia.
 */
final class WebhookController
{
    /** Corpo máximo aceito (a Meta manda alguns KB por chamada). */
    private const LIMITE_BYTES = 1_048_576;

    public static function registrar(Router $r): void
    {
        $r->get('/webhooks/meta', [self::class, 'verificar']);
        $r->post('/webhooks/meta', [self::class, 'receber'], ['csrf' => false]);
    }

    public function verificar(): Response
    {
        $desafio = Meta::desafio($_GET);
        return $desafio !== null ? Response::texto($desafio) : Response::texto('Token de verificação inválido.', 403);
    }

    public function receber(): Response
    {
        $corpo = (string) file_get_contents('php://input', false, null, 0, self::LIMITE_BYTES + 1);
        return $this->processar($corpo, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
    }

    /** Separado de receber() para que os testes passem o corpo e a assinatura sem depender de php://input. */
    public function processar(string $corpo, string $assinatura): Response
    {
        if (strlen($corpo) > self::LIMITE_BYTES) {
            return Response::texto('Corpo grande demais.', 413);
        }
        if (!Meta::assinaturaValida($corpo, $assinatura)) {
            return Response::texto('Assinatura inválida.', 403);
        }
        $payload = json_decode($corpo, true);
        if (!is_array($payload)) {
            return Response::texto('JSON inválido.', 400);
        }

        try {
            $executor = new ActionExecutor();
            $eventos = Meta::extrair($payload);
            foreach ($eventos['mensagens'] as $mensagem) {
                $r = $executor->receberMensagem($mensagem);
                if (!$r->ok) {
                    error_log('Webhook Meta: mensagem recusada: ' . $r->mensagem);
                }
            }
            foreach ($eventos['status'] as $s) {
                $executor->atualizarStatusMensagem($s['id'], $s['status'], $s['erro']);
            }
        } catch (Throwable $e) {
            error_log('Webhook Meta: ' . $e);
            return Response::texto('Erro ao processar.', 500);
        }
        return Response::texto('ok');
    }
}
