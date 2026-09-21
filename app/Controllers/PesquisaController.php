<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\FormularioRepository;
use App\Repositories\PesquisaRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Nps;

/**
 * Pesquisas NPS (Fase 12), área interna: tabulação (índice, distribuição, tendência, taxa de resposta), fila de envio,
 * respostas e o envio individual (link com token, botões de WhatsApp e e-mail). A escrita passa pelo ActionExecutor.
 */
final class PesquisaController
{
    private const POR_PAGINA = 25;
    private const PERIODOS = [30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias', 180 => 'Últimos 180 dias', 365 => 'Últimos 12 meses', 0 => 'Todo o período'];

    public static function registrar(Router $r): void
    {
        $r->get('/pesquisas', [self::class, 'index']);
        $r->post('/pesquisas', [self::class, 'criar']);
        $r->get('/pesquisas/{id:\d+}', [self::class, 'mostrar']);
        $r->post('/pesquisas/{id:\d+}/enviada', [self::class, 'enviada']);
        $r->post('/pesquisas/{id:\d+}/cancelar', [self::class, 'cancelar']);
    }

    public function index(): Response
    {
        $formularios = $this->pesquisasDefinidas();
        $formularioId = (int) ($_GET['formulario'] ?? 0);
        $formularioId = isset($formularios[$formularioId]) ? $formularioId : 0;
        $periodo = isset($_GET['periodo']) && isset(self::PERIODOS[(int) $_GET['periodo']]) ? (int) $_GET['periodo'] : 90;
        $desde = $periodo > 0 ? date('Y-m-d 00:00:00', strtotime("-{$periodo} days")) : '';
        $filtros = ['formulario_id' => $formularioId ?: null, 'desde' => $desde];

        $repo = new PesquisaRepository();
        $resumo = Nps::resumir($repo->contagemPorNota($filtros));
        $status = $repo->contagemPorStatus($filtros);
        $tendencia = [];
        foreach ($repo->contagemPorMes($formularioId ? ['formulario_id' => $formularioId] : []) as $mes => $contagem) {
            $tendencia[$mes] = Nps::resumir($contagem);
        }
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
        $consulta = static fn (int $p): string => url('/pesquisas?' . http_build_query(array_filter([
            'formulario' => $formularioId ?: null, 'periodo' => $periodo !== 90 ? $periodo : null, 'pagina' => $p > 1 ? $p : null,
        ], static fn ($v) => $v !== null)));

        return View::pagina('pesquisas/index', [
            'titulo' => 'Pesquisas NPS',
            'formularios' => $formularios, 'formularioId' => $formularioId, 'periodo' => $periodo, 'periodos' => self::PERIODOS,
            'resumo' => $resumo, 'distribuicao' => $repo->contagemPorNota($filtros), 'tendencia' => $tendencia,
            'criadas' => array_sum($status) - ($status['cancelada'] ?? 0), 'respondidas' => $status['respondida'] ?? 0,
            'aguardando' => $repo->listar(['status' => 'pendente'] + ($formularioId ? ['formulario_id' => $formularioId] : []), 1, 100)['linhas'],
            'respostas' => $repo->listar(['status' => 'respondida'] + $filtros, $pagina, self::POR_PAGINA),
            'urlPagina' => $consulta, 'porPagina' => self::POR_PAGINA,
            'ativas' => (new FormularioRepository())->pesquisasAtivas(),
        ]);
    }

    /** POST /pesquisas — cria o envio (empresa, pesquisa e, opcional, contato) e abre a página do link. */
    public function criar(): Response
    {
        $empresaId = (int) ($_POST['empresa_id'] ?? 0);
        $formulario = (new FormularioRepository())->encontrar((int) ($_POST['formulario_id'] ?? 0));
        $voltar = $this->voltar((string) ($_POST['voltar'] ?? ''));
        if ($formulario === null) {
            Session::flash('error', 'Escolha uma pesquisa.');
            return Response::redirecionar($voltar);
        }
        $r = (new ActionExecutor())->criarPesquisa(
            $formulario, $empresaId, ($_POST['contato_id'] ?? '') !== '' ? (int) $_POST['contato_id'] : null, null, 'manual', 'humano',
        );
        Session::flash($r->ok ? 'success' : 'error', $r->mensagem);
        return Response::redirecionar($r->ok ? url('/pesquisas/' . (int) $r->id) : $voltar);
    }

    public function mostrar(array $p): Response
    {
        $pesquisa = (new PesquisaRepository())->encontrar((int) $p['id']);
        if ($pesquisa === null) {
            return View::pagina('paginas/nao_encontrado', ['titulo' => 'Não encontrado'], 404);
        }
        return View::pagina('pesquisas/detalhe', ['titulo' => 'Pesquisa NPS: ' . $pesquisa['empresa_nome'], 'pesquisa' => $pesquisa]);
    }

    public function enviada(array $p): Response
    {
        $r = (new ActionExecutor())->marcarPesquisaEnviada((int) $p['id']);
        Session::flash($r->ok ? 'success' : 'error', $r->mensagem);
        return Response::redirecionar($this->voltar((string) ($_POST['voltar'] ?? ''), url('/pesquisas/' . (int) $p['id'])));
    }

    public function cancelar(array $p): Response
    {
        $r = (new ActionExecutor())->cancelarPesquisa((int) $p['id']);
        Session::flash($r->ok ? 'success' : 'error', $r->mensagem);
        return Response::redirecionar($this->voltar((string) ($_POST['voltar'] ?? ''), url('/pesquisas')));
    }

    // ---- Apoio ----------------------------------------------------------------------------

    /** @return array<int,string> id => nome das pesquisas (ativas ou não) */
    private function pesquisasDefinidas(): array
    {
        $saida = [];
        foreach ((new FormularioRepository())->todos() as $f) {
            if ($f['tipo'] === 'pesquisa') {
                $saida[(int) $f['id']] = (string) $f['nome'];
            }
        }
        return $saida;
    }

    /** Só caminhos internos (evita redirecionar para outro site). */
    private function voltar(string $destino, ?string $padrao = null): string
    {
        return $destino !== '' && str_starts_with($destino, '/') && !str_starts_with($destino, '//') ? url($destino) : ($padrao ?? url('/pesquisas'));
    }
}
