<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\IntegracaoRepository;
use App\Services\ActionExecutor;
use Throwable;

/**
 * Entrada da integração com o Opensquad (SPEC §13): `POST /api/integracao/opensquad`. Autenticada pelo cabeçalho
 * `X-Opensquad-Token` (valor em `integracao.opensquad_token`; vazio = integração desligada), sem sessão e sem CSRF.
 * Cabeçalho próprio em vez de `Authorization`: hospedagem compartilhada com PHP em CGI costuma descartar esse.
 *
 * Corpo: {"squad", "run", "simular"?, "eventos": [...]}. Cada evento é processado e registrado à parte (um que falha
 * não impede os outros); a chave squad|run|id garante que reenviar o mesmo lote não repete nada.
 */
final class IntegracaoController
{
    private const LIMITE_BYTES = 1_048_576;
    private const LIMITE_EVENTOS = 100;

    public static function registrar(Router $r): void
    {
        $r->post('/api/integracao/opensquad', [self::class, 'receber'], ['csrf' => false]);
    }

    public function receber(): Response
    {
        $corpo = (string) file_get_contents('php://input', false, null, 0, self::LIMITE_BYTES + 1);
        return $this->processar($corpo, (string) ($_SERVER['HTTP_X_OPENSQUAD_TOKEN'] ?? ''));
    }

    /** Separado de receber() para que os testes passem o corpo e o token sem depender de php://input. */
    public function processar(string $corpo, string $token): Response
    {
        if (strlen($corpo) > self::LIMITE_BYTES) {
            return self::erro('Corpo grande demais (máx. 1 MB).', 413);
        }
        $esperado = trim((string) Config::obter('integracao.opensquad_token', ''));
        if ($esperado === '') {
            return self::erro('Integração com o Opensquad desativada (defina integracao.opensquad_token no config.local.php).', 403);
        }
        if (!hash_equals($esperado, $token)) {
            return self::erro('Token inválido.', 403);
        }

        $lote = json_decode($corpo, true);
        if (!is_array($lote)) {
            return self::erro('JSON inválido.', 400);
        }
        $squad = (string) ($lote['squad'] ?? '');
        $run = (string) ($lote['run'] ?? '');
        $eventos = $lote['eventos'] ?? null;
        $simular = ($lote['simular'] ?? false) === true;
        if (!preg_match('/^[a-z0-9-]{1,60}$/', $squad)) {
            return self::erro('Campo squad inválido (minúsculas, números e hífen).', 400);
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $run)) {
            return self::erro('Campo run inválido (letras, números, ponto, hífen e sublinhado).', 400);
        }
        if (!is_array($eventos) || !array_is_list($eventos)) {
            return self::erro('Campo eventos deve ser uma lista.', 400);
        }
        if (count($eventos) > self::LIMITE_EVENTOS) {
            return self::erro('Máximo de ' . self::LIMITE_EVENTOS . ' eventos por envio.', 400);
        }

        $executor = new ActionExecutor();
        $processar = fn (): array => $this->processarEventos($executor, $squad, $run, $eventos, $simular);
        $resultados = $simular ? $executor->simularIntegracao($processar) : $processar();

        $ok = array_filter($resultados, static fn (array $r) => $r['status'] === 'erro') === [];
        return Response::json(['ok' => $ok, 'simulacao' => $simular, 'resultados' => $resultados]);
    }

    /** @return list<array> um resultado por evento, na ordem recebida */
    private function processarEventos(ActionExecutor $executor, string $squad, string $run, array $eventos, bool $simular): array
    {
        $repo = new IntegracaoRepository();
        $vistos = [];
        $resultados = [];
        foreach ($eventos as $i => $evento) {
            $evento = is_array($evento) ? $evento : [];
            $id = (string) ($evento['id'] ?? '');
            $tipo = (string) ($evento['tipo'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $id)) {
                $resultados[] = ['id' => $id, 'status' => 'erro', 'mensagem' => 'Evento ' . ($i + 1) . ': campo id ausente ou inválido.'];
                continue;
            }
            if (isset($vistos[$id])) {
                $resultados[] = ['id' => $id, 'status' => 'erro', 'mensagem' => 'id repetido neste envio.'];
                continue;
            }
            $vistos[$id] = true;

            $anterior = $repo->porChave(IntegracaoRepository::chave($squad, $run, $id));
            if ($anterior !== null && $anterior['status'] === 'ok') {
                $resultados[] = ['status' => 'ja_processado'] + (array) json_decode((string) $anterior['resposta'], true);
                continue;
            }

            try {
                $r = $executor->processarEventoIntegracao($squad, $evento);
                $resposta = $r->ok
                    ? ['id' => $id, 'status' => 'ok'] + (array) $r->registro + ['link' => $simular ? null : self::link((array) $r->registro)]
                    : ['id' => $id, 'status' => 'erro', 'mensagem' => $r->mensagem];
            } catch (Throwable $e) {
                error_log('Integração Opensquad: ' . $e);
                $resposta = ['id' => $id, 'status' => 'erro', 'mensagem' => 'Erro interno ao processar o evento (ver log do servidor).'];
            }
            if (!$simular) {
                $repo->registrar($squad, $run, $id, $tipo, $resposta['status'], $resposta);
            }
            $resultados[] = $resposta;
        }
        return $resultados;
    }

    private static function link(array $registro): ?string
    {
        if (!empty($registro['negocio_id'])) {
            return url_publica('/negocios/' . (int) $registro['negocio_id']);
        }
        return !empty($registro['empresa_id']) ? url_publica('/empresas/' . (int) $registro['empresa_id']) : null;
    }

    private static function erro(string $mensagem, int $status): Response
    {
        return Response::json(['ok' => false, 'erro' => $mensagem], $status);
    }
}
