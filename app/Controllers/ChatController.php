<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\ChatService;
use InvalidArgumentException;

/** API do chat (JSON sob /api/chat). Controller fino: a lógica está em ChatService. */
final class ChatController
{
    /** GET /api/chat/historico — últimas mensagens já renderizadas. */
    public function historico(): Response
    {
        $html = '';
        foreach ((new ChatService())->historico(40) as $m) {
            $html .= chat_mensagem($m);
        }
        return Response::json(['ok' => true, 'html' => $html]);
    }

    /** POST /api/chat {mensagem, tela} */
    public function enviar(): Response
    {
        $entrada = $this->entrada();
        $mensagem = $entrada['mensagem'] ?? null;
        $tela = $entrada['tela'] ?? null;
        if (!is_string($mensagem)) {
            return Response::json(['ok' => false, 'erro' => 'Escreva uma mensagem.'], 422);
        }

        try {
            $r = (new ChatService())->enviar($mensagem, is_string($tela) ? $tela : null);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'erro' => $e->getMessage()], 422);
        }
        return Response::json([
            'ok'       => true,
            'alterou'  => !empty($r['resposta']['payload']['alterou']),
            'resposta' => ['id' => $r['resposta']['id'], 'html' => chat_mensagem($r['resposta'])],
        ]);
    }

    /** POST /api/chat/acao {mensagem_id, acao, opcao?} — botões das respostas. */
    public function acao(): Response
    {
        $entrada = $this->entrada();
        $id = $entrada['mensagem_id'] ?? null;
        $acao = $entrada['acao'] ?? null;
        $opcao = $entrada['opcao'] ?? null;
        if (!is_int($id) || !is_string($acao) || ($opcao !== null && !is_int($opcao))) {
            return Response::json(['ok' => false, 'erro' => 'Pedido inválido.'], 422);
        }

        try {
            $r = (new ChatService())->acionar($id, $acao, $opcao);
        } catch (InvalidArgumentException $e) {
            return Response::json(['ok' => false, 'erro' => $e->getMessage()], 422);
        }
        return Response::json([
            'ok'         => true,
            'alterou'    => !empty($r['resposta']['payload']['alterou']),
            'atualizada' => $r['atualizada'] !== null ? ['id' => $r['atualizada']['id'], 'html' => chat_mensagem($r['atualizada'])] : null,
            'resposta'   => $r['resposta'] !== null ? ['id' => $r['resposta']['id'], 'html' => chat_mensagem($r['resposta'])] : null,
        ]);
    }

    private function entrada(): array
    {
        $dados = json_decode((string) file_get_contents('php://input'), true);
        return is_array($dados) ? $dados : [];
    }
}
