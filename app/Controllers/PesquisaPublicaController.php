<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\FormularioRepository;
use App\Repositories\PesquisaRepository;
use App\Services\ActionExecutor;

/**
 * Página pública da pesquisa de satisfação (Fase 12): `/nps/{token}`, um link individual por cliente e por envio.
 * O token (40 hex, aleatório) é a única credencial e vale para uma resposta. O envio não usa CSRF: a página não abre
 * sessão (como os formulários de captação) e a defesa é o token de uso único.
 */
final class PesquisaPublicaController
{
    private const TOKEN = '[a-f0-9]{40}';

    public static function registrar(Router $r): void
    {
        $r->get('/nps/{token:' . self::TOKEN . '}', [self::class, 'mostrar']);
        $r->post('/nps/{token:' . self::TOKEN . '}', [self::class, 'enviar'], ['csrf' => false]);
    }

    public function mostrar(array $p): Response
    {
        $pesquisa = (new PesquisaRepository())->porToken($p['token']);
        $formulario = $pesquisa !== null ? (new FormularioRepository())->encontrar((int) $pesquisa['formulario_id']) : null;
        if ($pesquisa === null || $formulario === null || (int) $formulario['ativo'] !== 1) {
            return $this->mensagem('Pesquisa indisponível', 'Este link não está disponível.', true, 404);
        }
        $aviso = $this->motivoIndisponivel($pesquisa);
        if ($aviso !== null) {
            return $this->mensagem((string) ($formulario['titulo'] ?? $formulario['nome']), $aviso, $pesquisa['status'] !== 'respondida');
        }
        return $this->pagina($pesquisa, $formulario, [], [], 200);
    }

    public function enviar(array $p): Response
    {
        $repo = new PesquisaRepository();
        $pesquisa = $repo->porToken($p['token']);
        $formulario = $pesquisa !== null ? (new FormularioRepository())->encontrar((int) $pesquisa['formulario_id']) : null;
        if ($pesquisa === null || $formulario === null) {
            return $this->mensagem('Pesquisa indisponível', 'Este link não está disponível.', true, 404);
        }

        $r = (new ActionExecutor())->responderPesquisa($p['token'], $_POST, ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
        if (!$r->ok) {
            if (isset($r->erros['_'])) {
                return $this->mensagem((string) ($formulario['titulo'] ?? $formulario['nome']), (string) $r->erros['_'], $pesquisa['status'] !== 'respondida');
            }
            return $this->pagina($pesquisa, $formulario, $r->erros, $_POST, 422);
        }
        return $this->mensagem((string) ($formulario['titulo'] ?? $formulario['nome']), (string) $r->mensagem, false);
    }

    // ---- Apoio ----------------------------------------------------------------------------

    /** Texto para quem abre um link que não aceita mais resposta (null = aceita). */
    private function motivoIndisponivel(array $pesquisa): ?string
    {
        if ($pesquisa['status'] === 'pendente' && strtotime((string) $pesquisa['expira_em']) >= time()) {
            return null;
        }
        return match (true) {
            $pesquisa['status'] === 'respondida' => 'Esta pesquisa já foi respondida. Obrigado!',
            $pesquisa['status'] === 'cancelada' => 'Esta pesquisa não está mais disponível.',
            default => 'O prazo para responder esta pesquisa terminou.',
        };
    }

    private function pagina(array $pesquisa, array $formulario, array $erros, array $valores, int $status): Response
    {
        return $this->protegida(View::pagina('publico/pesquisa', [
            'titulo' => (string) ($formulario['titulo'] ?? $formulario['nome']), 'formulario' => $formulario, 'pesquisa' => $pesquisa,
            'campos' => (new FormularioRepository())->campos((int) $formulario['id']), 'erros' => $erros, 'valores' => $valores,
            'acao' => url('/nps/' . $pesquisa['token']), 'embed' => false,
        ], $status, 'layouts/formulario'));
    }

    private function mensagem(string $titulo, string $texto, bool $erro, int $status = 200): Response
    {
        return $this->protegida(View::pagina('publico/formulario_ok', [
            'titulo' => $titulo, 'mensagem' => $texto, 'redirect' => null, 'erro' => $erro, 'embed' => false, 'formulario' => null,
        ], $status, 'layouts/formulario'));
    }

    /** Sem cache, fora dos buscadores e sem referer (o token está na URL). */
    private function protegida(Response $r): Response
    {
        $r->cabecalhos += ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];
        return $r;
    }
}
