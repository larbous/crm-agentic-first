<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Services\AI\AcoesRapidas;
use App\Services\AI\IaErro;
use InvalidArgumentException;

/** Ações rápidas de IA (SPEC §8), JSON sob /api/ia. Controller fino: a lógica está em AcoesRapidas. Não grava nada. */
final class IaRapidaController
{
    public static function registrar(Router $r): void
    {
        $r->post('/api/ia/rapida', [self::class, 'executar']);
    }

    /** POST /api/ia/rapida {acao, texto?} ou {acao, entidade, id} → {ok, texto} */
    public function executar(): Response
    {
        $entrada = json_decode((string) file_get_contents('php://input'), true);
        $entrada = is_array($entrada) ? $entrada : [];
        $acao = $entrada['acao'] ?? null;
        $texto = $entrada['texto'] ?? null;
        $entidade = $entrada['entidade'] ?? null;
        $id = $entrada['id'] ?? null;
        if (!is_string($acao) || ($texto !== null && !is_string($texto)) || ($entidade !== null && !is_string($entidade)) || ($id !== null && !is_int($id))) {
            return Response::json(['ok' => false, 'erro' => 'Pedido inválido.'], 422);
        }

        try {
            $r = (new AcoesRapidas())->executar($acao, $texto, $entidade, $id);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'erro' => $e->getMessage()], 422);
        } catch (IaErro $e) {
            return Response::json(['ok' => false, 'erro' => $e->getMessage()], 503);
        }
        return Response::json(['ok' => true, 'texto' => $r['texto']]);
    }
}
