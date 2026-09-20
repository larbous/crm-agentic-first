<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\FormularioRepository;
use App\Repositories\Repositorios;
use App\Repositories\SquadRepository;
use App\Repositories\SubmissaoRepository;
use App\Services\ActionExecutor;
use App\Services\FormularioDefinicao;

/**
 * Formulários de captação (SPEC §4.10, §9), lado do operador: lista, construtor de campos e tela de submissões.
 * Toda escrita passa pelo ActionExecutor. A página pública é o FormularioPublicoController.
 */
final class FormularioController
{
    private const POR_PAGINA = 25;

    public static function registrar(Router $r): void
    {
        $r->get('/formularios', [self::class, 'index']);
        $r->get('/formularios/nova', [self::class, 'novo']);
        $r->post('/formularios', [self::class, 'criar']);
        $r->get('/formularios/submissoes', [self::class, 'submissoes']);
        $r->get('/formularios/{id:\d+}/editar', [self::class, 'editar']);
        $r->post('/formularios/{id:\d+}', [self::class, 'atualizar']);
        $r->post('/formularios/{id:\d+}/arquivar', [self::class, 'arquivar']);
    }

    public function index(): Response
    {
        return View::pagina('formularios/index', ['titulo' => 'Formulários', 'formularios' => (new FormularioRepository())->todos()]);
    }

    public function novo(): Response
    {
        return $this->editor(null, ['status_padrao' => 'lead', 'regra_duplicado' => 'tarefa', 'texto_botao' => 'Enviar', 'ativo' => 1], $this->camposIniciais(), []);
    }

    public function criar(): Response
    {
        [$dados, $campos] = $this->entrada();
        $r = (new ActionExecutor())->salvarFormulario(null, $dados, $campos);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/formularios/' . (int) $r->id . '/editar'));
        }
        return $this->editor(null, $dados, $campos, $r->erros, 422);
    }

    public function editar(array $p): Response
    {
        $f = (new FormularioRepository())->encontrar((int) $p['id']);
        if ($f === null) {
            return $this->naoEncontrado();
        }
        return $this->editor($f, $f, (new FormularioRepository())->campos((int) $f['id']), []);
    }

    public function atualizar(array $p): Response
    {
        $f = (new FormularioRepository())->encontrar((int) $p['id']);
        if ($f === null) {
            return $this->naoEncontrado();
        }
        [$dados, $campos] = $this->entrada();
        $r = (new ActionExecutor())->salvarFormulario((int) $f['id'], $dados, $campos);
        if ($r->ok) {
            Session::flash('success', $r->mensagem);
            return Response::redirecionar(url('/formularios/' . (int) $f['id'] . '/editar'));
        }
        return $this->editor($f, $dados + $f, $campos, $r->erros, 422);
    }

    public function arquivar(array $p): Response
    {
        $r = (new ActionExecutor())->arquivarFormulario((int) $p['id']);
        Session::flash($r->ok ? 'success' : 'error', $r->mensagem);
        return Response::redirecionar(url('/formularios'));
    }

    public function submissoes(): Response
    {
        $filtros = ['formulario_id' => (int) ($_GET['formulario'] ?? 0), 'status' => (string) ($_GET['status'] ?? '')];
        if (!in_array($filtros['status'], ['processada', 'duplicada', 'spam'], true)) {
            $filtros['status'] = '';
        }
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
        $resultado = (new SubmissaoRepository())->listar($filtros, $pagina, self::POR_PAGINA);
        $consulta = static fn (int $p): string => url('/formularios/submissoes?' . http_build_query(array_filter([
            'formulario' => $filtros['formulario_id'] ?: null, 'status' => $filtros['status'], 'pagina' => $p > 1 ? $p : null,
        ])));

        return View::pagina('formularios/submissoes', [
            'titulo' => 'Submissões', 'resultado' => $resultado, 'filtros' => $filtros,
            'formulariosOpcoes' => (new FormularioRepository())->nomes(), 'urlPagina' => $consulta, 'porPagina' => self::POR_PAGINA,
        ]);
    }

    // ---- Apoio ----------------------------------------------------------------------------

    /** Configuração (POST) e campos (JSON do construtor). */
    private function entrada(): array
    {
        $dados = [];
        foreach (['nome', 'titulo', 'texto_botao', 'mensagem_sucesso', 'redirect_url', 'origem_id_padrao', 'status_padrao', 'criar_negocio',
            'etapa_id_padrao', 'squad_disparado', 'regra_duplicado', 'ativo'] as $k) {
            $dados[$k] = $_POST[$k] ?? null;
        }
        $campos = json_decode((string) ($_POST['campos_json'] ?? '[]'), true);
        return [$dados, is_array($campos) ? array_values(array_filter($campos, 'is_array')) : []];
    }

    /** Ponto de partida de um formulário novo: o essencial de um contato comercial. */
    private function camposIniciais(): array
    {
        $c = static fn (string $d, string $r, string $t, int $l, int $o = 0): array => [
            'campo_destino' => $d, 'rotulo' => $r, 'tipo' => $t, 'largura' => $l, 'obrigatorio' => $o, 'placeholder' => null, 'ajuda' => null, 'opcoes' => [],
        ];
        return [
            $c('empresa.nome_fantasia', 'Empresa', 'texto', 12, 1),
            $c('contato.nome', 'Seu nome', 'texto', 6, 1),
            $c('contato.email', 'E-mail', 'email', 6, 1),
            $c('contato.whatsapp', 'WhatsApp', 'telefone', 6),
            $c('empresa.site', 'Site', 'texto', 6),
            $c('negocio.dor_principal', 'Como podemos ajudar?', 'textarea', 12),
        ];
    }

    private function editor(?array $formulario, array $dados, array $campos, array $erros, int $status = 200): Response
    {
        $etapas = [];
        foreach (Repositorios::etapas()->todas() as $e) {
            if ($e['tipo'] === 'aberta') {
                $etapas[$e['id']] = ($e['pipeline_nome'] ?? '') !== '' ? "{$e['pipeline_nome']} · {$e['nome']}" : $e['nome'];
            }
        }
        return View::pagina('formularios/editor', [
            'titulo' => $formulario === null ? 'Novo formulário' : 'Formulário: ' . $formulario['nome'],
            'formulario' => $formulario, 'cfg' => $dados, 'campos' => $campos, 'erros' => $erros,
            'destinos' => array_values(FormularioDefinicao::destinos()),
            'origens' => Repositorios::para('origens')->opcoes(),
            'etapas' => $etapas,
            'squads' => array_column((new SquadRepository())->todos(true), 'nome', 'slug'),
        ], $status);
    }

    private function naoEncontrado(): Response
    {
        return View::pagina('paginas/nao_encontrado', ['titulo' => 'Não encontrado'], 404);
    }
}
