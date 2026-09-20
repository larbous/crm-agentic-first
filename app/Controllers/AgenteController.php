<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AgenteRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\AI\AgenteDefinicao;
use App\Services\AI\AgentRunner;
use App\Services\AI\IaErro;
use App\Services\Schema;
use InvalidArgumentException;

/**
 * Agentes (SPEC §6, §9): lista, editor JSON com validação, teste em simulação, importar/exportar e versões.
 * Toda escrita passa pelo ActionExecutor. A execução real (botões do detalhe) e o teste usam o mesmo endpoint.
 */
final class AgenteController
{
    private const MODELO_JSON = <<<'JSON'
{
  "slug": "meu-agente",
  "nome": "Meu agente",
  "descricao": "O que este agente faz, em uma frase",
  "versao": 1,
  "modelo": "claude-haiku-4-5-20251001",
  "max_tokens": 800,
  "web_search": false,
  "entrada": "empresas",
  "contexto": ["nome_fantasia", "segmento", "cidade", "uf"],
  "contexto_relacionado": {"atividades": 5},
  "prompt": "Descreva aqui, em português, o que o agente deve fazer com os dados recebidos.",
  "acoes_permitidas": ["nota"],
  "campos_gravaveis": [],
  "aprovacao": "escritas",
  "gatilho": {"tipo": "manual"}
}
JSON;

    public static function registrar(Router $r): void
    {
        $r->get('/agentes', [self::class, 'index']);
        $r->get('/agentes/nova', [self::class, 'novo']);
        $r->post('/agentes', [self::class, 'criar']);
        $r->post('/agentes/importar', [self::class, 'importar']);
        $r->get('/agentes/{id:\d+}/editar', [self::class, 'editar']);
        $r->post('/agentes/{id:\d+}', [self::class, 'atualizar']);
        $r->post('/agentes/{id:\d+}/ativo', [self::class, 'alternarAtivo']);
        $r->get('/agentes/{id:\d+}/exportar', [self::class, 'exportar']);
        $r->post('/agentes/{id:\d+}/versoes/{versao:\d+}/restaurar', [self::class, 'restaurar']);
        $r->post('/api/agentes/validar', [self::class, 'validar']);
        $r->get('/api/agentes/{id:\d+}/registros', [self::class, 'registros']);
        $r->post('/api/agentes/{id:\d+}/executar', [self::class, 'executar']);
    }

    // ---- Telas ----------------------------------------------------------------------------

    public function index(): Response
    {
        return View::pagina('agentes/index', ['titulo' => 'Agentes', 'agentes' => (new AgenteRepository())->todos()]);
    }

    public function novo(): Response
    {
        return $this->editor(null, self::MODELO_JSON, []);
    }

    public function editar(array $p): Response
    {
        $agente = (new AgenteRepository())->encontrar((int) $p['id']);
        if ($agente === null) {
            return $this->naoEncontrado();
        }
        return $this->editor($agente, self::paraJson($agente['def']), []);
    }

    public function criar(): Response
    {
        $json = (string) ($_POST['definicao'] ?? '');
        $r = (new ActionExecutor())->salvarAgente($json);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/agentes/' . (int) $r->id . '/editar'));
        }
        return $this->editor(null, $json, $r->erros, 422);
    }

    public function atualizar(array $p): Response
    {
        $agente = (new AgenteRepository())->encontrar((int) $p['id']);
        if ($agente === null) {
            return $this->naoEncontrado();
        }
        $json = (string) ($_POST['definicao'] ?? '');
        $r = (new ActionExecutor())->salvarAgente($json, (int) $agente['id']);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/agentes/' . (int) $agente['id'] . '/editar'));
        }
        return $this->editor($agente, $json, $r->erros, 422);
    }

    public function alternarAtivo(array $p): Response
    {
        $r = (new ActionExecutor())->definirAgenteAtivo((int) $p['id'], ($_POST['ativo'] ?? '') === '1');
        Session::flash($r->ok ? 'success' : 'error', $r->mensagem);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/agentes')));
    }

    /** Importa um .agent.json (arquivo ou texto colado). Slug existente vira nova versão do agente. */
    public function importar(): Response
    {
        $json = trim((string) ($_POST['definicao'] ?? ''));
        $arquivo = $_FILES['arquivo'] ?? null;
        if (is_array($arquivo) && ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            if ((int) $arquivo['size'] > 262144) {
                Session::flash('error', 'O arquivo é grande demais para um agente (máximo de 256 KB).');
                return Response::redirecionar(url('/agentes'));
            }
            $json = trim((string) file_get_contents((string) $arquivo['tmp_name']));
        }
        if ($json === '') {
            Session::flash('error', 'Escolha um arquivo .agent.json ou cole o conteúdo.');
            return Response::redirecionar(url('/agentes'));
        }
        $r = (new ActionExecutor())->salvarAgente($json);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/agentes/' . (int) $r->id . '/editar'));
        }
        Session::flash('error', 'Importação recusada: ' . implode(' ', array_slice($r->erros, 0, 3)));
        return Response::redirecionar(url('/agentes'));
    }

    public function exportar(array $p): Response
    {
        $agente = (new AgenteRepository())->encontrar((int) $p['id']);
        if ($agente === null) {
            return $this->naoEncontrado();
        }
        return new Response(self::paraJson($agente['def']) . "\n", 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $agente['slug'] . '.agent.json"',
        ]);
    }

    public function restaurar(array $p): Response
    {
        $r = (new ActionExecutor())->restaurarVersaoAgente((int) $p['id'], (int) $p['versao']);
        Session::flash($r->ok ? 'success' : 'error', $r->ok ? 'Versão ' . (int) $p['versao'] . ' restaurada como nova versão. ' . $r->mensagem : $r->mensagem);
        return Response::redirecionar(url('/agentes/' . (int) $p['id'] . '/editar'));
    }

    // ---- API JSON -------------------------------------------------------------------------

    /** POST /api/agentes/validar {definicao} */
    public function validar(): Response
    {
        $entrada = $this->entrada();
        $v = AgenteDefinicao::validar((string) ($entrada['definicao'] ?? ''));
        return Response::json(['ok' => true, 'valida' => $v['ok'], 'erros' => $v['erros']]);
    }

    /** GET /api/agentes/{id}/registros?q= — registros da entidade do agente, para escolher no teste. */
    public function registros(array $p): Response
    {
        $agente = (new AgenteRepository())->encontrar((int) $p['id']);
        $entidade = $agente['def']['entrada'] ?? 'nenhuma';
        if ($agente === null || $entidade === 'nenhuma') {
            return Response::json(['itens' => []]);
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $linhas = Repositorios::para($entidade)->listar(['busca' => $q, 'por_pagina' => 8])['linhas'];
        $itens = array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'titulo' => (string) ($l['nome_fantasia'] ?? (isset($l['numero']) ? $l['numero'] . ' — ' . $l['titulo'] : ($l['titulo'] ?? trim(($l['nome'] ?? '') . ' ' . ($l['sobrenome'] ?? ''))))),
        ], $linhas);
        return Response::json(['itens' => $itens]);
    }

    /** POST /api/agentes/{id}/executar {registro_id?, entrada?, simulacao?} */
    public function executar(array $p): Response
    {
        $agente = (new AgenteRepository())->encontrar((int) $p['id']);
        if ($agente === null) {
            return Response::json(['ok' => false, 'erro' => 'Agente não encontrado.'], 404);
        }
        $e = $this->entrada();
        $registroId = isset($e['registro_id']) && is_int($e['registro_id']) ? $e['registro_id'] : null;
        $texto = isset($e['entrada']) && is_string($e['entrada']) ? mb_substr($e['entrada'], 0, 2000) : null;
        $simulacao = !empty($e['simulacao']);

        try {
            $r = (new AgentRunner())->executar($agente, $registroId, $texto, $simulacao);
        } catch (InvalidArgumentException $ex) {
            return Response::json(['ok' => false, 'erro' => $ex->getMessage()], 422);
        } catch (IaErro $ex) {
            return Response::json(['ok' => false, 'erro' => $ex->getMessage()], 502);
        }
        return Response::json([
            'ok'        => true,
            'sucesso'   => $r['ok'],
            'simulacao' => $simulacao,
            'alterou'   => !$simulacao && ($r['aplicadas'] !== [] || $r['pendentes'] !== []),
            'pendentes' => count($r['pendentes']),
            'resumo'    => $r['resumo'],
            'html'      => agente_resultado_html($r),
        ]);
    }

    // ---- Auxiliares -----------------------------------------------------------------------

    /** JSON legível de uma definição (contexto_relacionado vazio sai como {}). */
    public static function paraJson(array $def): string
    {
        if (($def['contexto_relacionado'] ?? []) === []) {
            $def['contexto_relacionado'] = new \stdClass();
        }
        return json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function editor(?array $agente, string $json, array $erros, int $status = 200): Response
    {
        $repo = new AgenteRepository();
        return View::pagina('agentes/editor', [
            'titulo'  => $agente !== null ? 'Agente: ' . $agente['nome'] : 'Novo agente',
            'agente'  => $agente,
            'json'    => $json,
            'erros'   => $erros,
            'versoes' => $agente !== null ? $repo->versoes((int) $agente['id']) : [],
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
