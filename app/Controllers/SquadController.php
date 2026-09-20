<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AgendamentoRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\SquadRepository;
use App\Services\ActionExecutor;
use App\Services\AI\SquadDefinicao;
use App\Services\AI\SquadRunner;
use InvalidArgumentException;

/**
 * Squads (SPEC §7, §9): lista, editor JSON com validação, importar/exportar, versões e execuções com progresso.
 * Toda escrita de definição passa pelo ActionExecutor. Executar apenas põe o squad na fila; o worker (cron) o roda.
 */
final class SquadController
{
    private const MODELO_JSON = <<<'JSON'
{
  "slug": "meu-squad",
  "nome": "Meu squad",
  "descricao": "O que este squad faz, em uma frase",
  "versao": 1,
  "entrada": "empresas",
  "etapas": [
    {"agente": "pesquisador"},
    {"agente": "qualificador", "usa_saida_de": ["pesquisador"], "condicao": "empresa.status == lead"},
    {"acao": "tarefa", "dados": {"titulo": "Revisar o lead", "prioridade": "media", "vencimento_em_dias": 2}}
  ],
  "parar_se": "pesquisador.status == spam",
  "gatilho": {"tipo": "manual"}
}
JSON;

    public static function registrar(Router $r): void
    {
        $r->get('/squads', [self::class, 'index']);
        $r->get('/squads/nova', [self::class, 'novo']);
        $r->post('/squads', [self::class, 'criar']);
        $r->post('/squads/importar', [self::class, 'importar']);
        $r->get('/squads/{id:\d+}/editar', [self::class, 'editar']);
        $r->post('/squads/{id:\d+}', [self::class, 'atualizar']);
        $r->post('/squads/{id:\d+}/ativo', [self::class, 'alternarAtivo']);
        $r->get('/squads/{id:\d+}/exportar', [self::class, 'exportar']);
        $r->post('/squads/{id:\d+}/versoes/{versao:\d+}/restaurar', [self::class, 'restaurar']);
        $r->get('/squads/execucoes/{id:\d+}', [self::class, 'execucao']);
        $r->post('/squads/execucoes/{id:\d+}/cancelar', [self::class, 'cancelar']);
        $r->post('/api/squads/validar', [self::class, 'validar']);
        $r->post('/api/squads/{id:\d+}/executar', [self::class, 'executar']);
        $r->get('/api/squads/execucoes/{id:\d+}', [self::class, 'progresso']);
    }

    // ---- Telas ----------------------------------------------------------------------------

    public function index(): Response
    {
        $agenda = [];
        foreach ((new AgendamentoRepository())->todos() as $a) {
            if ($a['tipo'] === 'squad') {
                $agenda[$a['dono_id']] = $a;
            }
        }
        return View::pagina('squads/index', ['titulo' => 'Squads', 'squads' => (new SquadRepository())->todos(), 'agenda' => $agenda]);
    }

    public function novo(): Response
    {
        return $this->editor(null, self::MODELO_JSON, []);
    }

    public function editar(array $p): Response
    {
        $squad = (new SquadRepository())->encontrar((int) $p['id']);
        return $squad === null ? $this->naoEncontrado() : $this->editor($squad, self::paraJson($squad['def']), []);
    }

    public function criar(): Response
    {
        $json = (string) ($_POST['definicao'] ?? '');
        $r = (new ActionExecutor())->salvarSquad($json);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/squads/' . (int) $r->id . '/editar'));
        }
        return $this->editor(null, $json, $r->erros, 422);
    }

    public function atualizar(array $p): Response
    {
        $squad = (new SquadRepository())->encontrar((int) $p['id']);
        if ($squad === null) {
            return $this->naoEncontrado();
        }
        $json = (string) ($_POST['definicao'] ?? '');
        $r = (new ActionExecutor())->salvarSquad($json, (int) $squad['id']);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/squads/' . (int) $squad['id'] . '/editar'));
        }
        return $this->editor($squad, $json, $r->erros, 422);
    }

    public function alternarAtivo(array $p): Response
    {
        $r = (new ActionExecutor())->definirSquadAtivo((int) $p['id'], ($_POST['ativo'] ?? '') === '1');
        Session::flash($r->ok ? 'success' : 'error', $r->mensagem);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/squads')));
    }

    /** Importa um .squad.json (arquivo ou texto colado). Slug existente vira nova versão do squad. */
    public function importar(): Response
    {
        $json = trim((string) ($_POST['definicao'] ?? ''));
        $arquivo = $_FILES['arquivo'] ?? null;
        if (is_array($arquivo) && ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ((int) $arquivo['size'] > 262144) {
                Session::flash('error', 'O arquivo é grande demais para um squad (máximo de 256 KB).');
                return Response::redirecionar(url('/squads'));
            }
            $json = trim((string) file_get_contents((string) $arquivo['tmp_name']));
        }
        if ($json === '') {
            Session::flash('error', 'Escolha um arquivo .squad.json ou cole o conteúdo.');
            return Response::redirecionar(url('/squads'));
        }
        $r = (new ActionExecutor())->salvarSquad($json);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/squads/' . (int) $r->id . '/editar'));
        }
        Session::flash('error', 'Importação recusada: ' . implode(' ', array_slice($r->erros, 0, 3)));
        return Response::redirecionar(url('/squads'));
    }

    public function exportar(array $p): Response
    {
        $squad = (new SquadRepository())->encontrar((int) $p['id']);
        if ($squad === null) {
            return $this->naoEncontrado();
        }
        return new Response(self::paraJson($squad['def']) . "\n", 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $squad['slug'] . '.squad.json"',
        ]);
    }

    public function restaurar(array $p): Response
    {
        $r = (new ActionExecutor())->restaurarVersaoSquad((int) $p['id'], (int) $p['versao']);
        Session::flash($r->ok ? 'success' : 'error', $r->ok ? 'Versão ' . (int) $p['versao'] . ' restaurada como nova versão. ' . $r->mensagem : $r->mensagem);
        return Response::redirecionar(url('/squads/' . (int) $p['id'] . '/editar'));
    }

    /** Andamento de uma execução de squad (com atualização por polling enquanto não terminar). */
    public function execucao(array $p): Response
    {
        $cab = $this->cabecalho((int) $p['id']);
        if ($cab === null) {
            return $this->naoEncontrado();
        }
        return View::pagina('squads/execucao', ['titulo' => 'Execução de squad #' . (int) $cab['id']] + $this->dadosDaExecucao($cab));
    }

    public function cancelar(array $p): Response
    {
        $cab = $this->cabecalho((int) $p['id']);
        if ($cab === null) {
            return $this->naoEncontrado();
        }
        $ok = (new ExecucaoRepository())->cancelarSquad((int) $cab['id']);
        Session::flash($ok ? 'success' : 'error', $ok ? 'Execução cancelada.' : 'Só é possível cancelar execuções na fila ou aguardando aprovação.');
        return Response::redirecionar(url('/squads/execucoes/' . (int) $cab['id']));
    }

    // ---- API JSON -------------------------------------------------------------------------

    /** POST /api/squads/validar {definicao} */
    public function validar(): Response
    {
        $v = SquadDefinicao::validar((string) ($this->entrada()['definicao'] ?? ''));
        return Response::json(['ok' => true, 'valida' => $v['ok'], 'erros' => $v['erros']]);
    }

    /** POST /api/squads/{id}/executar {registro_id?, entrada?} — só enfileira; o worker executa. */
    public function executar(array $p): Response
    {
        $squad = (new SquadRepository())->encontrar((int) $p['id']);
        if ($squad === null) {
            return Response::json(['ok' => false, 'erro' => 'Squad não encontrado.'], 404);
        }
        $e = $this->entrada();
        if (!empty($e['simulacao'])) {
            return Response::json(['ok' => false, 'erro' => 'Squads não têm simulação: teste cada agente em Agentes.'], 422);
        }
        $registroId = isset($e['registro_id']) && is_int($e['registro_id']) ? $e['registro_id'] : null;
        $texto = isset($e['entrada']) && is_string($e['entrada']) ? mb_substr($e['entrada'], 0, 2000) : null;
        try {
            $id = (new SquadRunner())->enfileirar($squad, $registroId, $texto, 'humano');
        } catch (InvalidArgumentException $ex) {
            return Response::json(['ok' => false, 'erro' => $ex->getMessage()], 422);
        }
        return Response::json(['ok' => true, 'execucao_id' => $id, 'url' => url('/squads/execucoes/' . $id)]);
    }

    /** GET /api/squads/execucoes/{id} — status e HTML do andamento (montado e escapado no servidor). */
    public function progresso(array $p): Response
    {
        $cab = $this->cabecalho((int) $p['id']);
        if ($cab === null) {
            return Response::json(['ok' => false, 'erro' => 'Execução não encontrada.'], 404);
        }
        $dados = $this->dadosDaExecucao($cab);
        return Response::json([
            'ok' => true, 'status' => $cab['status'], 'terminou' => in_array($cab['status'], ['concluida', 'erro', 'cancelada'], true),
            'html' => squad_execucao_html($dados['cabecalho'], $dados['estado'], $dados['etapas'], $dados['tokens']),
        ]);
    }

    // ---- Auxiliares -----------------------------------------------------------------------

    /** JSON legível de uma definição. */
    public static function paraJson(array $def): string
    {
        return json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Cabeçalho de uma execução de squad (linha de execucoes sem squad_execucao_id) ou null. */
    private function cabecalho(int $id): ?array
    {
        $c = (new ExecucaoRepository())->encontrar($id);
        return $c !== null && $c['squad_id'] !== null && $c['squad_execucao_id'] === null ? $c : null;
    }

    private function dadosDaExecucao(array $cab): array
    {
        $repo = new ExecucaoRepository();
        $squad = (new SquadRepository())->encontrar((int) $cab['squad_id']);
        return [
            'cabecalho' => $cab,
            'squad'     => $squad,
            'estado'    => (array) json_decode((string) $cab['saida'], true),
            'etapas'    => array_column($repo->etapasDoSquad((int) $cab['id']), null, 'etapa_ordem'),
            'tokens'    => $repo->tokensDoSquad((int) $cab['id']),
        ];
    }

    private function editor(?array $squad, string $json, array $erros, int $status = 200): Response
    {
        $repo = new SquadRepository();
        $id = $squad !== null ? (int) $squad['id'] : null;
        return View::pagina('squads/editor', [
            'titulo'    => $squad !== null ? 'Squad: ' . $squad['nome'] : 'Novo squad',
            'squad'     => $squad,
            'json'      => $json,
            'erros'     => $erros,
            'versoes'   => $id !== null ? $repo->versoes($id) : [],
            'execucoes' => $id !== null ? (new ExecucaoRepository())->doSquad($id) : [],
            'agenda'    => $id !== null ? (new AgendamentoRepository())->proximoDe('squad', $id) : null,
        ], $status);
    }

    private function naoEncontrado(): Response
    {
        return View::pagina('paginas/nao_encontrado', ['titulo' => 'Não encontrado'], 404);
    }

    private function entrada(): array
    {
        $dados = json_decode((string) file_get_contents('php://input'), true);
        return is_array($dados) ? $dados : [];
    }
}
