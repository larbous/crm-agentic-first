<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ConversaRepository;
use App\Repositories\MensagemRepository;
use App\Services\ActionExecutor;
use App\Services\Canais\Canais;
use App\Services\Opcoes;
use App\Services\Resultado;

/** Caixa de entrada unificada (Fase 14): conversas de WhatsApp, Instagram e e-mail numa tela, com resposta pelo próprio canal. */
final class CaixaController
{
    private const POR_PAGINA = 25;

    public static function registrar(Router $r): void
    {
        $r->get('/caixa', [self::class, 'index']);
        $r->get('/caixa/{id:\d+}', [self::class, 'mostrar']);
        $r->post('/caixa/{id:\d+}/enviar', [self::class, 'enviar']);
        $r->post('/caixa/{id:\d+}/resolver', [self::class, 'resolver']);
        $r->post('/caixa/{id:\d+}/reabrir', [self::class, 'reabrir']);
        $r->post('/caixa/{id:\d+}/vincular', [self::class, 'vincular']);
        $r->post('/caixa/{id:\d+}/criar-contato', [self::class, 'criarContato']);
    }

    public function index(): Response
    {
        $canal = isset(Canais::ROTULOS[$_GET['canal'] ?? ''] ) ? (string) $_GET['canal'] : '';
        $situacao = in_array($_GET['situacao'] ?? '', ['abertas', 'resolvidas', 'todas'], true) ? (string) $_GET['situacao'] : 'abertas';
        $q = trim((string) ($_GET['q'] ?? ''));
        $naoLidas = ($_GET['nao_lidas'] ?? '') === '1';
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
        $filtros = ['canal' => $canal, 'situacao' => $situacao, 'q' => $q, 'nao_lidas' => $naoLidas];
        $url = static fn (int $p): string => url('/caixa?' . http_build_query(array_filter(
            ['canal' => $canal, 'situacao' => $situacao !== 'abertas' ? $situacao : '', 'q' => $q, 'nao_lidas' => $naoLidas ? '1' : '', 'pagina' => $p > 1 ? $p : ''],
            static fn ($v) => $v !== '',
        )));

        return View::pagina('caixa/index', [
            'titulo' => 'Caixa de entrada', 'resultado' => (new ConversaRepository())->listar($filtros, $pagina, self::POR_PAGINA),
            'filtros' => $filtros, 'urlPagina' => $url,
            'canais' => array_map(static fn (string $c): bool => Canais::ativo($c), array_combine(array_keys(Canais::ROTULOS), array_keys(Canais::ROTULOS))),
        ]);
    }

    public function mostrar(array $p): Response
    {
        $conversa = (new ConversaRepository())->encontrar((int) $p['id']);
        if ($conversa === null) {
            return View::pagina('paginas/nao_encontrado', ['titulo' => 'Não encontrado'], 404);
        }
        if ((int) $conversa['nao_lidas'] > 0) {
            (new ActionExecutor())->marcarConversaLida((int) $conversa['id']);
            $conversa['nao_lidas'] = 0;
        }
        $ultimaEntrada = (string) ($conversa['ultima_entrada_em'] ?? '');
        $dentroDaJanela = !Canais::temJanela((string) $conversa['canal']) || ($ultimaEntrada !== '' && strtotime($ultimaEntrada) >= time() - Canais::JANELA_HORAS * 3600);

        return View::pagina('caixa/conversa', [
            'titulo' => 'Conversa: ' . ($conversa['contato_nome'] ?: ($conversa['nome'] ?: $conversa['identificador'])),
            'conversa' => $conversa, 'mensagens' => (new MensagemRepository())->daConversa((int) $conversa['id']),
            'canalAtivo' => Canais::ativo((string) $conversa['canal']), 'dentroDaJanela' => $dentroDaJanela,
            'contatos' => Opcoes::para('contatos'), 'empresas' => Opcoes::para('empresas'),
        ]);
    }

    public function enviar(array $p): Response
    {
        $this->flash((new ActionExecutor())->enviarMensagem((int) $p['id'], (string) ($_POST['texto'] ?? ''), 'humano'));
        return Response::redirecionar(url('/caixa/' . (int) $p['id']));
    }

    public function resolver(array $p): Response
    {
        $this->flash((new ActionExecutor())->mudarStatusConversa((int) $p['id'], 'resolvida'));
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/caixa')));
    }

    public function reabrir(array $p): Response
    {
        $this->flash((new ActionExecutor())->mudarStatusConversa((int) $p['id'], 'aberta'));
        return Response::redirecionar(url('/caixa/' . (int) $p['id']));
    }

    public function vincular(array $p): Response
    {
        $this->flash((new ActionExecutor())->vincularConversa((int) $p['id'], (int) ($_POST['contato_id'] ?? 0)));
        return Response::redirecionar(url('/caixa/' . (int) $p['id']));
    }

    public function criarContato(array $p): Response
    {
        $this->flash((new ActionExecutor())->criarContatoDaConversa(
            (int) $p['id'], trim((string) ($_POST['nome'] ?? '')), ($_POST['empresa_id'] ?? '') !== '' ? (int) $_POST['empresa_id'] : null,
        ));
        return Response::redirecionar(url('/caixa/' . (int) $p['id']));
    }

    private function flash(Resultado $r): void
    {
        $mensagem = $r->mensagem !== '' ? $r->mensagem : implode(' ', $r->erros);
        Session::flash($r->ok ? 'success' : 'error', $mensagem !== '' ? $mensagem : 'Não foi possível concluir a ação.');
    }
}
