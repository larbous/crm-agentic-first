<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\Schema;

/** Tarefas: tela Hoje / Atrasadas / Próximos 7 dias / Todas, conclusão rápida e formulário. */
final class TarefaController extends CrudController
{
    protected function entidade(): string
    {
        return 'tarefas';
    }

    protected function rota(): string
    {
        return '/tarefas';
    }

    public static function registrarRotas(Router $r): void
    {
        $r->get('/tarefas', [self::class, 'index']);
        $r->get('/tarefas/nova', [self::class, 'novo']);
        $r->post('/tarefas', [self::class, 'criar']);
        $r->get('/tarefas/{id:\d+}', [self::class, 'redirecionarEditar']);
        $r->get('/tarefas/{id:\d+}/editar', [self::class, 'editar']);
        $r->post('/tarefas/{id:\d+}', [self::class, 'atualizar']);
        $r->post('/tarefas/{id:\d+}/arquivar', [self::class, 'arquivar']);
        $r->post('/tarefas/{id:\d+}/concluir', [self::class, 'concluir']);
        $r->post('/tarefas/{id:\d+}/reabrir', [self::class, 'reabrir']);
    }

    protected function colunas(): array
    {
        return [];
    }

    protected function filtros(): array
    {
        return [];
    }

    protected function dadosDetalhe(array $registro): array
    {
        return [];
    }

    protected function tituloRegistro(array $registro): string
    {
        return $registro['titulo'];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados', 'vinculos' => 'Vínculos'];
    }

    protected function usaTags(): bool
    {
        return false;
    }

    protected function ocultarNoForm(?array $registro): array
    {
        return ['vencimento'];
    }

    protected function padroesNovo(): array
    {
        return parent::padroesNovo() + ['tipo' => 'outro', 'prioridade' => 'media', 'status' => 'pendente', 'recorrencia' => 'nenhuma'];
    }

    protected function destinoApos(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/tarefas'));
    }

    /** Vencimento = data + hora opcional (campos separados no formulário). */
    protected function prepararEntrada(array $entrada): array
    {
        $data = trim((string) ($_POST['vencimento_data'] ?? ''));
        $hora = trim((string) ($_POST['vencimento_hora'] ?? ''));
        $entrada['vencimento'] = $data === '' ? null : ($hora === '' ? $data : $data . ' ' . $hora);
        return $entrada;
    }

    protected function valoresParaForm(array $valores): array
    {
        $venc = (string) ($valores['vencimento'] ?? '');
        $valores['vencimento_data'] = $venc !== '' ? substr($venc, 0, 10) : '';
        $valores['vencimento_hora'] = strlen($venc) >= 16 ? substr($venc, 11, 5) : '';
        return $valores;
    }

    protected function extraAba(string $grupo, array $valores, array $erros): string
    {
        if ($grupo !== 'dados') {
            return '';
        }
        return '<div class="mt-4 grid gap-4 md:grid-cols-2">'
            . campo(['nome' => 'vencimento_data', 'rotulo' => 'Vencimento', 'tipo' => 'date', 'valor' => $valores['vencimento_data'] ?? '', 'erro' => $erros['vencimento'] ?? null])
            . campo(['nome' => 'vencimento_hora', 'rotulo' => 'Hora (opcional)', 'tipo' => 'time', 'valor' => $valores['vencimento_hora'] ?? ''])
            . '</div>';
    }

    // ---- Tela principal --------------------------------------------------------------

    public function index(): Response
    {
        $hoje = hoje();
        $limite = date('Y-m-d', strtotime('+7 days'));
        $visao = in_array($_GET['visao'] ?? '', ['hoje', 'atrasadas', 'proximas', 'todas'], true) ? $_GET['visao'] : 'hoje';
        $repo = Repositorios::tarefas();

        return View::pagina('tarefas/index', [
            'titulo'     => 'Tarefas',
            'visao'      => $visao,
            'tarefas'    => $repo->grupo($visao, $hoje, $limite),
            'contagens'  => $repo->contagens($hoje, $limite),
            'voltar'     => '/tarefas?visao=' . $visao,
        ]);
    }

    public function redirecionarEditar(array $p): Response
    {
        return Response::redirecionar(url('/tarefas/' . (int) $p['id'] . '/editar'));
    }

    public function concluir(array $p): Response
    {
        $this->flash($this->executor()->concluirTarefa((int) $p['id'], 'humano'));
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/tarefas')));
    }

    public function reabrir(array $p): Response
    {
        $this->flash($this->executor()->atualizar('tarefas', (int) $p['id'], ['status' => 'pendente'], 'humano'));
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/tarefas')));
    }
}
