<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\CamposExtras;
use App\Services\Resultado;
use App\Services\Schema;

/**
 * CRUD genérico sobre o Schema: lista (busca, filtros, ordenação, paginação, colunas configuráveis),
 * formulário em abas, detalhe e arquivamento. Toda escrita passa pelo ActionExecutor.
 */
abstract class CrudController
{
    /** Nome da entidade no Schema (ex.: "empresas"). */
    abstract protected function entidade(): string;

    /** Caminho base das telas (ex.: "/empresas"). */
    abstract protected function rota(): string;

    /**
     * Colunas da lista: chave => ['rotulo', 'ordenavel' => bool, 'padrao' => bool, 'alinhar' => 'direita',
     * 'render' => fn(array $linha, array $ctx): string|array]. O render devolve texto (escapado) ou ['html' => ..., 'valor' => ...].
     */
    abstract protected function colunas(): array;

    /** Filtros de select da lista: nome => ['rotulo' => '', 'opcoes' => [valor => rótulo]]. */
    abstract protected function filtros(): array;

    /** Abas do formulário: grupo do Schema => rótulo. */
    abstract protected function abasForm(): array;

    abstract protected function tituloRegistro(array $registro): string;

    /** Dados extras da tela de detalhe (listas relacionadas, timeline...). */
    abstract protected function dadosDetalhe(array $registro): array;

    protected function viewDetalhe(): string
    {
        return $this->entidade() . '/detalhe';
    }

    protected function usaTags(): bool
    {
        return in_array($this->entidade(), ['empresas', 'contatos', 'negocios'], true);
    }

    /** Valores iniciais de um formulário novo (podem vir da query string, ex.: ?empresa_id=3). */
    protected function padroesNovo(): array
    {
        $valores = [];
        foreach (Schema::gravaveis($this->entidade()) as $campo => $def) {
            if (($def['t'] === 'fk') && isset($_GET[$campo]) && ctype_digit((string) $_GET[$campo])) {
                $valores[$campo] = (int) $_GET[$campo];
            }
        }
        return $valores;
    }

    /** Campos que não aparecem no formulário. */
    protected function ocultarNoForm(?array $registro): array
    {
        return [];
    }

    /** Sobrescreve as opções de selects (campo => [valor => rótulo]). */
    protected function opcoesForm(?array $registro): array
    {
        return [];
    }

    /** HTML adicional dentro de uma aba do formulário. */
    protected function extraAba(string $grupo, array $valores, array $erros): string
    {
        return '';
    }

    /** Ajusta a entrada do formulário antes de enviar ao ActionExecutor. */
    protected function prepararEntrada(array $entrada): array
    {
        return $entrada;
    }

    /** Ajusta os valores mostrados no formulário (edição/erro). */
    protected function valoresParaForm(array $valores): array
    {
        return $valores;
    }

    protected function acoesExtrasLista(): string
    {
        return '';
    }

    protected function porPagina(): int
    {
        return 25;
    }

    protected function destinoApos(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url($this->rota() . '/' . $id));
    }

    /** Filtros da lista: os da entidade e os dos campos extras de lista de opções e sim/não. */
    private function filtrosLista(): array
    {
        return $this->filtros() + CamposExtras::filtros($this->entidade());
    }

    /** Colunas da lista: as da entidade e uma opcional para cada campo extra ativo. */
    private function colunasLista(): array
    {
        return $this->colunas() + CamposExtras::colunas($this->entidade());
    }

    protected function executor(): ActionExecutor
    {
        return new ActionExecutor();
    }

    // ---- Rotas -----------------------------------------------------------------------

    /** Registra as rotas padrão do CRUD. Chamar dentro do grupo autenticado. */
    public static function registrar(Router $r, string $rota, string $classe): void
    {
        $r->get($rota, [$classe, 'index']);
        $r->get($rota . '/nova', [$classe, 'novo']);
        $r->post($rota, [$classe, 'criar']);
        $r->post($rota . '/colunas', [$classe, 'colunasConfig']);
        $r->get($rota . '/{id:\d+}', [$classe, 'mostrar']);
        $r->get($rota . '/{id:\d+}/editar', [$classe, 'editar']);
        $r->post($rota . '/{id:\d+}', [$classe, 'atualizar']);
        $r->post($rota . '/{id:\d+}/arquivar', [$classe, 'arquivar']);
    }

    // ---- Lista -----------------------------------------------------------------------

    public function index(): Response
    {
        $entidade = $this->entidade();
        $repo = Repositorios::para($entidade);

        $q = trim((string) ($_GET['q'] ?? ''));
        $filtrosAtivos = [];
        foreach (array_keys($this->filtrosLista()) as $nome) {
            $filtrosAtivos[$nome] = (string) ($_GET[$nome] ?? '');
        }
        $tagId = ctype_digit((string) ($_GET['tag_id'] ?? '')) ? (int) $_GET['tag_id'] : 0;
        $ordem = (string) ($_GET['ordem'] ?? '');
        $dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $resultado = $repo->listar([
            'busca' => $q, 'filtros' => $filtrosAtivos, 'tag_id' => $tagId,
            'ordem' => $ordem, 'dir' => $dir, 'pagina' => (int) ($_GET['pagina'] ?? 1), 'por_pagina' => $this->porPagina(),
        ]);

        $ctx = [];
        if ($this->usaTags()) {
            $ctx['tags'] = Repositorios::tags()->tagsDeVarios($entidade, array_column($resultado['linhas'], 'id'));
        }

        // Colunas visíveis: preferência salva ou padrão
        $todas = $this->colunasLista();
        $salvas = json_decode((new ConfiguracaoRepository())->obter("colunas.{$entidade}", '[]') ?? '[]', true);
        $visiveis = array_values(array_filter(
            is_array($salvas) && $salvas !== [] ? $salvas : array_keys(array_filter($todas, static fn (array $c) => !empty($c['padrao']))),
            static fn ($k) => isset($todas[$k]),
        ));
        $colunasTabela = [];
        foreach ($visiveis as $k) {
            $colunasTabela[$k] = ['rotulo' => $todas[$k]['rotulo'], 'ordenavel' => !empty($todas[$k]['ordenavel']), 'alinhar' => $todas[$k]['alinhar'] ?? null];
        }
        $linhas = [];
        foreach ($resultado['linhas'] as $linha) {
            $celulas = [];
            foreach ($visiveis as $k) {
                $celulas[$k] = ($todas[$k]['render'])($linha, $ctx);
            }
            $linhas[] = $celulas;
        }

        $filtrosComValor = [];
        foreach ($this->filtrosLista() as $nome => $f) {
            $filtrosComValor[$nome] = $f + ['valor' => $filtrosAtivos[$nome]];
        }

        $parametros = fn (array $sobrepor): string => $this->rota() . '?' . http_build_query(array_filter(
            array_merge(['q' => $q, 'tag_id' => $tagId ?: null, 'ordem' => $ordem, 'dir' => $ordem !== '' ? $dir : null] + $filtrosAtivos, $sobrepor),
            static fn ($v) => $v !== null && $v !== '',
        ));

        return View::pagina('crud/lista', [
            'titulo'      => Schema::entidade($entidade)['plural'],
            'schema'      => Schema::entidade($entidade),
            'rota'        => $this->rota(),
            'q'           => $q,
            'filtros'     => $filtrosComValor,
            'filtrosNomes' => array_keys($this->filtrosLista()),
            'tagId'       => $tagId,
            'tagsOpcoes'  => $this->usaTags() ? Repositorios::tags()->opcoes() : [],
            'colunasTabela' => $colunasTabela,
            'linhas'      => $linhas,
            'todasColunas' => array_map(static fn (array $c) => $c['rotulo'], $todas),
            'visiveis'    => $visiveis,
            'ordem'       => $ordem,
            'dir'         => $dir,
            'resultado'   => $resultado,
            'urlPagina'   => static fn (int $p): string => url($parametros(['pagina' => $p])),
            'urlOrdem'    => static fn (string $chave, string $d): string => url($parametros(['ordem' => $chave, 'dir' => $d, 'pagina' => null])),
            'acoesExtras' => $this->acoesExtrasLista(),
        ]);
    }

    public function colunasConfig(): Response
    {
        $todas = array_keys($this->colunasLista());
        $escolhidas = array_values(array_intersect($todas, (array) ($_POST['colunas'] ?? [])));
        $r = $this->executor()->definirConfiguracao('colunas.' . $this->entidade(), $escolhidas === [] ? null : json_encode($escolhidas));
        $this->flash($r);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url($this->rota())));
    }

    // ---- Formulários -----------------------------------------------------------------

    public function novo(): Response
    {
        return $this->renderForm(null, $this->valoresParaForm($this->padroesNovo()), []);
    }

    public function criar(): Response
    {
        $entrada = $this->prepararEntrada(Schema::filtrar($this->entidade(), $_POST));
        $r = $this->executor()->criar($this->entidade(), $entrada, 'humano');
        if ($r->ok) {
            $this->flash($r);
            return Response::redirecionar($this->destinoApos((int) $r->id));
        }
        return $this->renderForm(null, $this->valoresParaForm($entrada), $r->erros, 422);
    }

    public function editar(array $p): Response
    {
        $registro = Repositorios::para($this->entidade())->encontrar((int) $p['id']);
        return $registro === null ? $this->naoEncontrado() : $this->renderForm($registro, $this->valoresParaForm($registro), []);
    }

    public function atualizar(array $p): Response
    {
        $id = (int) $p['id'];
        $registro = Repositorios::para($this->entidade())->encontrar($id);
        if ($registro === null) {
            return $this->naoEncontrado();
        }
        $entrada = $this->prepararEntrada(Schema::filtrar($this->entidade(), $_POST));
        $r = $this->executor()->atualizar($this->entidade(), $id, $entrada, 'humano');
        if ($r->ok) {
            $this->flash($r);
            return Response::redirecionar($this->destinoApos($id));
        }
        return $this->renderForm($registro, $this->valoresParaForm($entrada + $registro), $r->erros, 422);
    }

    public function arquivar(array $p): Response
    {
        $r = $this->executor()->arquivar($this->entidade(), (int) $p['id'], 'humano');
        $this->flash($r);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url($r->ok ? $this->rota() : $this->rota() . '/' . (int) $p['id'])));
    }

    protected function renderForm(?array $registro, array $valores, array $erros, int $status = 200): Response
    {
        $entidade = $this->entidade();
        $schema = Schema::entidade($entidade);
        $editando = $registro !== null;
        return View::pagina('crud/form', [
            'titulo'   => ($editando ? 'Editar ' : 'Nov' . ($schema['genero'] === 'f' ? 'a ' : 'o ')) . mb_strtolower($schema['singular']),
            'entidade' => $entidade,
            'schema'   => $schema,
            'registro' => $registro,
            'acao'     => url($this->rota() . ($editando ? '/' . (int) $registro['id'] : '')),
            'cancelar' => url($editando ? $this->rota() . '/' . (int) $registro['id'] : $this->rota()),
            'valores'  => $valores,
            'erros'    => $erros,
            'abas'     => $this->abasForm(),
            'ocultar'  => $this->ocultarNoForm($registro),
            'opcoes'   => $this->opcoesForm($registro),
            'extraAba' => fn (string $grupo): string => $this->extraAba($grupo, $valores, $erros),
            'voltar'   => caminho_seguro($_GET['voltar'] ?? $_POST['voltar'] ?? null, ''),
        ], $status);
    }

    // ---- Detalhe ---------------------------------------------------------------------

    public function mostrar(array $p): Response
    {
        $registro = Repositorios::para($this->entidade())->encontrar((int) $p['id']);
        if ($registro === null) {
            return $this->naoEncontrado();
        }
        $tags = $this->usaTags() ? Repositorios::tags()->tagsDe($this->entidade(), (int) $registro['id']) : [];
        return View::pagina($this->viewDetalhe(), $this->dadosDetalhe($registro) + [
            'titulo'   => $this->tituloRegistro($registro),
            'registro' => $registro,
            'entidade' => $this->entidade(),
            'schema'   => Schema::entidade($this->entidade()),
            'rota'     => $this->rota(),
            'tags'     => $tags,
            'tagsOpcoes' => $this->usaTags() ? Repositorios::tags()->opcoes() : [],
            'anexos'   => Repositorios::anexos()->porRegistro($this->entidade(), (int) $registro['id']),
        ]);
    }

    // ---- Utilidades ------------------------------------------------------------------

    protected function naoEncontrado(): Response
    {
        return View::pagina('paginas/nao_encontrado', ['titulo' => 'Não encontrado'], 404);
    }

    protected function flash(Resultado $r): void
    {
        if ($r->ok) {
            if ($r->mensagem !== '' && $r->mensagem !== 'Nada a alterar.') {
                Session::flash('success', $r->mensagem);
            }
        } else {
            Session::flash('error', $r->mensagem !== '' ? $r->mensagem : 'Não foi possível concluir a ação.');
        }
    }
}
