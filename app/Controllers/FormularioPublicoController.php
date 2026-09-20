<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\FormularioRepository;
use App\Services\ActionExecutor;
use App\Services\FormularioDefinicao;

/**
 * Página pública do formulário de captação (SPEC §4.10): `/f/{chave}` e `/f/{chave}?embed=1` (iframe).
 * Acesso só pela chave aleatória de 32 caracteres. O envio não usa CSRF (o formulário roda em iframe de outro site,
 * onde o cookie de sessão não vale): a defesa é honeypot + limite de 5 envios por IP por hora.
 */
final class FormularioPublicoController
{
    private const CHAVE = '[a-f0-9]{32}';

    public static function registrar(Router $r): void
    {
        $r->get('/f/{chave:' . self::CHAVE . '}', [self::class, 'mostrar']);
        $r->post('/f/{chave:' . self::CHAVE . '}', [self::class, 'enviar'], ['csrf' => false]);
    }

    public function mostrar(array $p): Response
    {
        $f = $this->formularioAtivo($p['chave']);
        if ($f === null) {
            return $this->indisponivel();
        }
        return $this->pagina($f, [], [], 200);
    }

    public function enviar(array $p): Response
    {
        $f = $this->formularioAtivo($p['chave']);
        if ($f === null) {
            return $this->indisponivel();
        }
        $r = (new ActionExecutor())->receberFormulario($f, $_POST, [
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'pagina_origem' => (string) ($_POST['_pagina'] ?? ''),
            'referer' => (string) ($_POST['_ref'] ?? ''),
            'honeypot' => trim((string) ($_POST['website'] ?? '')) !== '',
            'utm' => array_intersect_key($_POST, array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])),
        ]);

        if (!$r->ok) {
            if (isset($r->erros['_limite'])) {
                return $this->protegida(View::pagina('publico/formulario_ok', $this->dadosBase($f) + [
                    'titulo' => 'Muitos envios', 'mensagem' => $r->erros['_limite'], 'redirect' => null, 'erro' => true,
                ], 429, 'layouts/formulario'), $f);
            }
            return $this->pagina($f, $r->erros, $_POST, 422);
        }

        $redirect = (string) ($f['redirect_url'] ?? '');
        if ($redirect !== '' && FormularioDefinicao::urlHttp($redirect) && !$this->embutido()) {
            return Response::redirecionar($redirect, 303);
        }
        return $this->protegida(View::pagina('publico/formulario_ok', $this->dadosBase($f) + [
            'titulo' => (string) ($f['titulo'] ?? $f['nome']), 'mensagem' => (string) $f['mensagem_sucesso'],
            'redirect' => FormularioDefinicao::urlHttp($redirect) ? $redirect : null, 'erro' => false,
        ], 200, 'layouts/formulario'), $f);
    }

    // ---- Apoio ----------------------------------------------------------------------------

    private function formularioAtivo(string $chave): ?array
    {
        $f = (new FormularioRepository())->porChave($chave);
        return $f !== null && (int) $f['ativo'] === 1 ? $f : null;
    }

    private function embutido(): bool
    {
        return ($_GET['embed'] ?? '') === '1';
    }

    private function dadosBase(array $f): array
    {
        return ['formulario' => $f, 'embed' => $this->embutido()];
    }

    private function pagina(array $f, array $erros, array $valores, int $status): Response
    {
        // Rastreamento: UTMs, página-mãe e referer vêm da query (o script de incorporação as repassa) ou do reenvio.
        $de = static fn (string $k): string => mb_substr(trim((string) ($_POST[$k] ?? $_GET[$k] ?? '')), 0, 500);
        $rastreio = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
            $rastreio[$k] = $de($k);
        }
        $rastreio['_pagina'] = $de('_pagina') !== '' ? $de('_pagina') : $de('pagina');
        $rastreio['_ref'] = $de('_ref') !== '' ? $de('_ref') : ($de('ref') !== '' ? $de('ref') : mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500));

        $repo = new FormularioRepository();
        $destinos = FormularioDefinicao::destinos();
        $campos = array_values(array_filter(
            $repo->campos((int) $f['id']),
            static fn (array $c): bool => isset($destinos[$c['campo_destino']]),
        ));
        return $this->protegida(View::pagina('publico/formulario', $this->dadosBase($f) + [
            'titulo' => (string) ($f['titulo'] ?? $f['nome']), 'campos' => $campos, 'destinos' => $destinos,
            'erros' => $erros, 'valores' => $valores, 'rastreio' => $rastreio,
            'acao' => url('/f/' . $f['chave'] . ($this->embutido() ? '?embed=1' : '')),
        ], $status, 'layouts/formulario'), $f);
    }

    private function indisponivel(): Response
    {
        return $this->protegida(View::pagina('publico/formulario_ok', [
            'titulo' => 'Formulário indisponível', 'mensagem' => 'Este formulário não está disponível no momento.',
            'redirect' => null, 'erro' => true, 'embed' => $this->embutido(), 'formulario' => null,
        ], 404, 'layouts/formulario'), null);
    }

    /** Sem cache e fora dos buscadores; o modo iframe libera o enquadramento por qualquer site. */
    private function protegida(Response $r, ?array $f): Response
    {
        $r->cabecalhos += ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store'];
        if ($this->embutido()) {
            header_remove('X-Frame-Options');
            $r->cabecalhos += ['Content-Security-Policy' => 'frame-ancestors *'];
        }
        return $r;
    }
}
