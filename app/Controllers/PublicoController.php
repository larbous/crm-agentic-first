<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Resultado;

/**
 * Links públicos de proposta (/p/{token}) e contrato (/c/{token}): visualização, aceite/recusa e assinatura.
 * Acesso só por token aleatório de 40 caracteres; rascunhos nunca são expostos. Sem indexação e sem referer.
 */
final class PublicoController
{
    private const TOKEN = '[a-f0-9]{40}';

    public static function registrar(Router $r): void
    {
        $t = '{token:' . self::TOKEN . '}';
        $r->get("/p/{$t}", [self::class, 'proposta']);
        $r->post("/p/{$t}/aceitar", [self::class, 'aceitar']);
        $r->post("/p/{$t}/recusar", [self::class, 'recusar']);
        $r->get("/c/{$t}", [self::class, 'contrato']);
        $r->post("/c/{$t}/assinar", [self::class, 'assinar']);
    }

    // ---- Proposta --------------------------------------------------------------------------

    public function proposta(array $p): Response
    {
        $proposta = $this->propostaVisivel($p['token']);
        if ($proposta === null) {
            return $this->naoEncontrado();
        }
        // Só registra a visualização do cliente: o operador logado conferindo o link não conta.
        if (!Auth::logado() && $proposta['status'] === 'enviada') {
            (new ActionExecutor())->registrarVisualizacaoProposta((int) $proposta['id'], 'sistema');
            $proposta = Repositorios::propostas()->porToken($p['token']) ?? $proposta;
        }
        return $this->paginaProposta($proposta);
    }

    public function aceitar(array $p): Response
    {
        return $this->responder($p['token'], 'aceita');
    }

    public function recusar(array $p): Response
    {
        return $this->responder($p['token'], 'recusada');
    }

    private function responder(string $token, string $resposta): Response
    {
        $proposta = $this->propostaVisivel($token);
        if ($proposta === null || $this->honeypot()) {
            return $this->naoEncontrado();
        }
        if ($resposta === 'aceita' && ($_POST['concordo'] ?? '') !== '1') {
            return $this->paginaProposta($proposta, ['concordo' => 'Marque a caixa para confirmar que leu e concorda com a proposta.'], 422);
        }
        $r = (new ActionExecutor())->responderProposta((int) $proposta['id'], $resposta, [
            'nome' => $_POST['nome'] ?? '', 'documento' => $_POST['documento'] ?? '', 'motivo' => $_POST['motivo'] ?? '', 'ip' => $this->ip(),
        ], 'sistema');
        if (!$r->ok) {
            return $this->paginaProposta($proposta, $r->erros, 422);
        }
        Session::flash('success', $r->mensagem);
        return Response::redirecionar(url('/p/' . $token));
    }

    private function paginaProposta(array $proposta, array $erros = [], int $status = 200): Response
    {
        $superada = Repositorios::propostas()->maiorVersao($proposta['numero']) > (int) $proposta['versao'];
        $expirada = proposta_expirada($proposta);
        return $this->pagina('publico/proposta', DocumentoDados::proposta($proposta) + [
            'titulo'        => $proposta['titulo'],
            'token'         => $proposta['token_publico'],
            'erros'         => $erros,
            'valores'       => $_POST,
            'superada'      => $superada,
            'expirada'      => $expirada,
            'podeResponder' => in_array($proposta['status'], ['enviada', 'visualizada'], true) && !$superada && !$expirada,
        ], $status);
    }

    /** Proposta pelo token, ou null (inexistente ou ainda em rascunho). */
    private function propostaVisivel(string $token): ?array
    {
        $p = Repositorios::propostas()->porToken($token);
        return $p !== null && $p['status'] !== 'rascunho' ? $p : null;
    }

    // ---- Contrato --------------------------------------------------------------------------

    public function contrato(array $p): Response
    {
        $contrato = $this->contratoVisivel($p['token']);
        if ($contrato === null) {
            return $this->naoEncontrado();
        }
        if (!Auth::logado() && $contrato['status'] === 'enviado' && $contrato['visualizado_em'] === null) {
            (new ActionExecutor())->registrarVisualizacaoContrato((int) $contrato['id'], 'sistema');
            $contrato = Repositorios::contratos()->porToken($p['token']) ?? $contrato;
        }
        return $this->paginaContrato($contrato);
    }

    public function assinar(array $p): Response
    {
        $contrato = $this->contratoVisivel($p['token']);
        if ($contrato === null || $this->honeypot()) {
            return $this->naoEncontrado();
        }
        if (($_POST['concordo'] ?? '') !== '1') {
            return $this->paginaContrato($contrato, ['concordo' => 'Marque a caixa para confirmar que leu e concorda com o contrato.'], 422);
        }
        $r = (new ActionExecutor())->assinarContrato((int) $contrato['id'], [
            'nome' => $_POST['nome'] ?? '', 'documento' => $_POST['documento'] ?? '', 'ip' => $this->ip(),
        ], 'sistema');
        if (!$r->ok) {
            return $this->paginaContrato($contrato, $r->erros, 422);
        }
        Session::flash('success', $r->mensagem);
        return Response::redirecionar(url('/c/' . $p['token']));
    }

    private function paginaContrato(array $contrato, array $erros = [], int $status = 200): Response
    {
        return $this->pagina('publico/contrato', DocumentoDados::contrato($contrato) + [
            'titulo'      => $contrato['titulo'],
            'token'       => $contrato['token_publico'],
            'erros'       => $erros,
            'valores'     => $_POST,
            'podeAssinar' => $contrato['status'] === 'enviado',
        ], $status);
    }

    private function contratoVisivel(string $token): ?array
    {
        $c = Repositorios::contratos()->porToken($token);
        return $c !== null && $c['status'] !== 'rascunho' ? $c : null;
    }

    // ---- Utilidades ------------------------------------------------------------------------

    /** Campo-isca "website": robôs preenchem, pessoas não veem. */
    private function honeypot(): bool
    {
        return trim((string) ($_POST['website'] ?? '')) !== '';
    }

    /** IP direto da conexão (cabeçalhos de proxy não são confiáveis sem configuração). */
    private function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function pagina(string $view, array $dados, int $status = 200): Response
    {
        return $this->protegida(View::pagina($view, $dados, $status, 'layouts/publico'));
    }

    private function naoEncontrado(): Response
    {
        return $this->protegida(View::pagina('publico/nao_encontrado', ['titulo' => 'Documento não encontrado'], 404, 'layouts/publico'));
    }

    /** Cabeçalhos das páginas públicas: sem cache, sem referer (o token está na URL) e fora dos buscadores. */
    private function protegida(Response $r): Response
    {
        $r->cabecalhos += [
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag'    => 'noindex, nofollow',
            'Cache-Control'   => 'no-store',
        ];
        return $r;
    }
}
