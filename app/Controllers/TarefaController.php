<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\Opcoes;
use App\Services\Schema;

/** Tarefas e chamados: tela Hoje / Atrasadas / Próximos 7 dias / Todas, conclusão rápida e formulário. */
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

    /**
     * Tarefas e chamados na mesma tela. Filtros: `tipo` (todos | tarefas | chamados) e `area` (só chamados têm área).
     * Os dois entram nos mesmos grupos (hoje, atrasadas, próximos 7 dias, todas) e são ordenados juntos pelo vencimento.
     */
    public function index(): Response
    {
        $hoje = hoje();
        $limite = date('Y-m-d', strtotime('+7 days'));
        $visao = in_array($_GET['visao'] ?? '', ['hoje', 'atrasadas', 'proximas', 'todas'], true) ? $_GET['visao'] : 'hoje';
        $areas = Opcoes::para('areas');
        $area = ctype_digit((string) ($_GET['area'] ?? '')) && isset($areas[(int) $_GET['area']]) ? (int) $_GET['area'] : 0;
        $tipo = in_array($_GET['tipo'] ?? '', ['tarefas', 'chamados'], true) ? $_GET['tipo'] : 'todos';
        if ($area > 0) {
            $tipo = 'chamados'; // tarefa não tem área
        }
        $comTarefas = $tipo !== 'chamados';
        $comChamados = $tipo !== 'tarefas';

        $itens = [];
        foreach ($comTarefas ? Repositorios::tarefas()->grupo($visao, $hoje, $limite) : [] as $t) {
            $itens[] = ['tipo' => 'tarefa', 'aberto' => tarefa_aberta($t), 'r' => $t];
        }
        foreach ($comChamados ? Repositorios::chamados()->grupo($visao, $hoje, $limite, $area ?: null) : [] as $c) {
            $itens[] = ['tipo' => 'chamado', 'aberto' => chamado_aberto($c), 'r' => $c];
        }
        // Mesma ordem das listas separadas: (em "todas", abertos primeiro) sem prazo por último, prazo crescente, mais novo primeiro.
        usort($itens, static function (array $x, array $y) use ($visao): int {
            $vx = (string) ($x['r']['vencimento'] ?? '');
            $vy = (string) ($y['r']['vencimento'] ?? '');
            return [$visao === 'todas' && !$x['aberto'] ? 1 : 0, $vx === '' ? 1 : 0, $vx, -(int) $x['r']['id']]
                <=> [$visao === 'todas' && !$y['aberto'] ? 1 : 0, $vy === '' ? 1 : 0, $vy, -(int) $y['r']['id']];
        });

        $contagens = ['hoje' => 0, 'atrasadas' => 0, 'proximas' => 0, 'todas' => 0];
        foreach ([$comTarefas ? Repositorios::tarefas()->contagens($hoje, $limite) : [], $comChamados ? Repositorios::chamados()->contagens($hoje, $limite, $area ?: null) : []] as $parte) {
            foreach ($parte as $chave => $n) {
                $contagens[$chave] += $n;
            }
        }
        $filtros = array_filter(['tipo' => $tipo !== 'todos' && $area === 0 ? $tipo : null, 'area' => $area ?: null]);

        return View::pagina('tarefas/index', [
            'titulo'     => 'Tarefas e chamados',
            'visao'      => $visao,
            'itens'      => $itens,
            'contagens'  => $contagens,
            'tipo'       => $tipo,
            'area'       => $area,
            'areas'      => $areas,
            'filtros'    => $filtros,
            'voltar'     => '/tarefas?' . http_build_query(['visao' => $visao] + $filtros),
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
