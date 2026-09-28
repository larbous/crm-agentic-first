<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\AsaasWebhookRepository;
use App\Services\ActionExecutor;
use Throwable;

/**
 * Webhook do Asaas (Fase 17): `/webhooks/asaas`, primeira integração de entrada externa do sistema. Autenticado pelo
 * cabeçalho `asaas-access-token` (token configurado tanto no painel do Asaas quanto em `asaas.webhook_token`), sem
 * sessão e sem CSRF (chamada de servidor). Idempotência pelo hash do corpo bruto: o Asaas reenvia webhooks e não
 * garante um id de evento estável em todos os planos, então o mesmo corpo nunca é processado duas vezes.
 */
final class AsaasWebhookController
{
    private const LIMITE_BYTES = 1_048_576;

    public static function registrar(Router $r): void
    {
        $r->post('/webhooks/asaas', [self::class, 'receber'], ['csrf' => false]);
    }

    public function receber(): Response
    {
        $corpo = (string) file_get_contents('php://input', false, null, 0, self::LIMITE_BYTES + 1);
        return $this->processar($corpo, (string) ($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? ''));
    }

    /** Separado de receber() para que os testes passem o corpo e o token sem depender de php://input. */
    public function processar(string $corpo, string $token): Response
    {
        if (strlen($corpo) > self::LIMITE_BYTES) {
            return Response::texto('Corpo grande demais.', 413);
        }
        $esperado = trim((string) Config::obter('asaas.webhook_token', ''));
        if ($esperado === '' || !hash_equals($esperado, $token)) {
            return Response::texto('Token inválido.', 403);
        }
        $payload = json_decode($corpo, true);
        if (!is_array($payload) || !isset($payload['event'])) {
            return Response::texto('JSON inválido.', 400);
        }

        $novo = (new AsaasWebhookRepository())->registrarSeNovo(hash('sha256', $corpo), (string) $payload['event'], $corpo);
        if (!$novo) {
            return Response::texto('ok'); // já processado (reenvio do Asaas)
        }

        try {
            $evento = (string) $payload['event'];
            $x = new ActionExecutor();
            $r = null;
            if (($dados = (array) ($payload['payment'] ?? [])) !== []) {
                $r = $x->processarEventoAsaas($evento, $dados);
            } elseif (($nota = (array) ($payload['invoice'] ?? [])) !== []) {
                $r = $x->processarEventoNotaAsaas($evento, $nota);
            }
            if ($r !== null && !$r->ok) {
                error_log('Webhook Asaas: evento recusado: ' . $r->mensagem);
            }
        } catch (Throwable $e) {
            error_log('Webhook Asaas: ' . $e);
            return Response::texto('Erro ao processar.', 500);
        }
        return Response::texto('ok');
    }
}
