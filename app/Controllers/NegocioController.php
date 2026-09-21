<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\Opcoes;
use App\Services\Schema;

final class NegocioController extends CrudController
{
    protected function entidade(): string
    {
        return 'negocios';
    }

    protected function rota(): string
    {
        return '/negocios';
    }

    public static function registrarExtras(Router $r): void
    {
        $r->get('/negocios/kanban', [self::class, 'kanban']);
        $r->post('/negocios/{id:\d+}/contatos', [self::class, 'vincular']);
        $r->post('/negocios/{id:\d+}/contatos/{contato:\d+}/remover', [self::class, 'desvincular']);
        $r->post('/api/negocios/{id:\d+}/mover', [self::class, 'mover']);
    }

    protected function colunas(): array
    {
        $simples = static fn (string $campo) => static fn (array $l): string => (string) ($l[$campo] ?? '');
        return [
            'codigo'              => ['rotulo' => 'Código', 'ordenavel' => true, 'padrao' => true, 'render' => $simples('codigo')],
            'titulo'              => ['rotulo' => 'Negócio', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/negocios/' . (int) $l['id'])) . '">' . e($l['titulo']) . '</a>',
                'valor' => $l['titulo'],
            ]],
            'empresa_nome'        => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['empresa_id'] ? link_para('/empresas/' . (int) $l['empresa_id'], (string) $l['empresa_nome']) : '', 'valor' => (string) $l['empresa_nome'],
            ]],
            'etapa_nome'          => ['rotulo' => 'Etapa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => pill_etapa((string) $l['etapa_nome'], $l['etapa_cor'])]],
            'status'              => ['rotulo' => 'Status', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_status('status_negocio', $l['status'])]],
            'valor_estimado'      => ['rotulo' => 'Valor estimado', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => [
                'html' => $l['valor_estimado'] !== null ? e(moeda((int) $l['valor_estimado'])) : '', 'valor' => (int) $l['valor_estimado'],
            ]],
            'probabilidade'       => ['rotulo' => 'Prob.', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): string => $l['probabilidade'] !== null ? $l['probabilidade'] . '%' : ''],
            'valor_ponderado'     => ['rotulo' => 'Ponderado', 'ordenavel' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): string => moeda((int) $l['valor_ponderado'])],
            'previsao_fechamento' => ['rotulo' => 'Previsão', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => data_br($l['previsao_fechamento'])],
            'temperatura'         => ['rotulo' => 'Temperatura', 'ordenavel' => true, 'render' => static fn (array $l): string => Schema::opcoes('temperatura')[$l['temperatura']] ?? ''],
            'dias_na_etapa'       => ['rotulo' => 'Dias na etapa', 'alinhar' => 'direita', 'render' => static fn (array $l): string => (string) (dias_entre($l['entrou_etapa_em'], hoje()) ?? '')],
            'tags'                => ['rotulo' => 'Tags', 'render' => static fn (array $l, array $ctx): array => ['html' => chips_tags($ctx['tags'][(int) $l['id']] ?? [])]],
            'criado_em'           => ['rotulo' => 'Criado em', 'ordenavel' => true, 'render' => static fn (array $l): string => data_br($l['criado_em'])],
        ];
    }

    protected function filtros(): array
    {
        return [
            'status'      => ['rotulo' => 'Status', 'opcoes' => Schema::opcoes('status_negocio')],
            'etapa_id'    => ['rotulo' => 'Etapa', 'opcoes' => Opcoes::para('etapas')],
            'temperatura' => ['rotulo' => 'Temperatura', 'opcoes' => Schema::opcoes('temperatura')],
            'origem_id'   => ['rotulo' => 'Origem', 'opcoes' => Opcoes::para('origens')],
        ];
    }

    protected function acoesExtrasLista(): string
    {
        return '<div class="button-group">' . botao('Lista', ['variante' => 'primary', 'icone' => 'list-checks', 'attrs' => ['aria-current' => 'page']])
            . botao('Kanban', ['variante' => 'outline', 'icone' => 'layout-dashboard', 'href' => url('/negocios/kanban')]) . '</div>';
    }

    protected function abasForm(): array
    {
        return ['basico' => 'Básico', 'valores' => 'Valores', 'qualificacao' => 'Qualificação', 'andamento' => 'Andamento', 'aquisicao' => 'Aquisição', 'extras' => 'Extras'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return ($registro['codigo'] ? $registro['codigo'] . ' — ' : '') . $registro['titulo'];
    }

    protected function padroesNovo(): array
    {
        $padrao = ['tipo_receita' => 'unico'];
        $pipeline = Repositorios::pipelines()->padrao();
        if ($pipeline !== null && ($etapa = Repositorios::etapas()->primeiraAberta((int) $pipeline['id'])) !== null) {
            $padrao['etapa_id'] = (int) $etapa['id'];
        }
        return parent::padroesNovo() + $padrao;
    }

    protected function ocultarNoForm(?array $registro): array
    {
        // Status só é editável entre aberto/pausado; ganho/perdido vêm da etapa.
        return $registro === null || !in_array($registro['status'], ['aberto', 'pausado'], true) ? ['status'] : [];
    }

    protected function opcoesForm(?array $registro): array
    {
        return ['status' => ['aberto' => 'Aberto', 'pausado' => 'Pausado']];
    }

    protected function prepararEntrada(array $entrada): array
    {
        if (($entrada['etapa_id'] ?? '') === '') {
            unset($entrada['etapa_id']);
        }
        return $entrada;
    }

    protected function dadosDetalhe(array $registro): array
    {
        $id = (int) $registro['id'];
        return [
            'etapas'      => $registro['pipeline_id'] ? Repositorios::etapas()->doPipeline((int) $registro['pipeline_id']) : [],
            'vinculados'  => Repositorios::negocios()->contatosVinculados($id),
            'tarefas'     => Repositorios::tarefas()->porVinculo('negocio_id', $id),
            'atividades'  => Repositorios::atividades()->timeline('negocio_id', $id),
            'propostas'   => Repositorios::propostas()->ultimasPor('negocio_id', $id),
            'contratos'   => Repositorios::contratos()->por('negocio_id', $id),
            'diasNaEtapa' => dias_entre($registro['entrou_etapa_em'], hoje()),
            'contatosOpcoes' => Opcoes::para('contatos'),
            'motivosPerda'   => Opcoes::para('motivos_perda'),
        ];
    }

    // ---- Kanban ----------------------------------------------------------------------

    public function kanban(): Response
    {
        $pipelines = Repositorios::pipelines()->todas();
        $escolhido = ctype_digit((string) ($_GET['pipeline'] ?? '')) ? (int) $_GET['pipeline'] : 0;
        $pipeline = null;
        foreach ($pipelines as $p) {
            if ((int) $p['id'] === $escolhido) {
                $pipeline = $p;
            }
        }
        $pipeline ??= Repositorios::pipelines()->padrao();

        $temperatura = (string) ($_GET['temperatura'] ?? '');
        $temperatura = isset(Schema::opcoes('temperatura')[$temperatura]) ? $temperatura : '';
        $porEtapa = $pipeline ? Repositorios::negocios()->kanban((int) $pipeline['id']) : [];
        if ($temperatura !== '') {
            $porEtapa = array_map(static fn (array $cards): array => array_values(array_filter($cards, static fn (array $n): bool => $n['temperatura'] === $temperatura)), $porEtapa);
        }

        return View::pagina('negocios/kanban', [
            'titulo'       => 'Negócios — Kanban',
            'pipelines'    => array_column($pipelines, 'nome', 'id'),
            'pipeline'     => $pipeline,
            'etapas'       => $pipeline ? Repositorios::etapas()->doPipeline((int) $pipeline['id']) : [],
            'porEtapa'     => $porEtapa,
            'temperatura'  => $temperatura,
            'motivosPerda' => Opcoes::para('motivos_perda'),
        ]);
    }

    /** API do kanban e da tela de detalhe: mover negócio de etapa (ganho pede valor; perdido pede motivo). */
    public function mover(array $p): Response
    {
        $dados = json_decode((string) file_get_contents('php://input'), true);
        $dados = is_array($dados) ? $dados : $_POST;
        $r = $this->executor()->moverEtapa((int) $p['id'], $dados, 'humano');
        if ($r->ok && $r->mensagem !== '' && $r->mensagem !== 'Nada a alterar.') {
            Session::flash('success', 'Negócio movido de etapa.');
        }
        return Response::json(['ok' => $r->ok, 'mensagem' => $r->mensagem, 'erros' => $r->erros], $r->ok ? 200 : 422);
    }

    // ---- Contatos vinculados ---------------------------------------------------------

    public function vincular(array $p): Response
    {
        $r = $this->executor()->vincularContato((int) $p['id'], (int) ($_POST['contato_id'] ?? 0), $_POST['papel'] ?? null, 'humano');
        $this->flash($r);
        return Response::redirecionar(url('/negocios/' . (int) $p['id']));
    }

    public function desvincular(array $p): Response
    {
        $r = $this->executor()->desvincularContato((int) $p['id'], (int) $p['contato'], 'humano');
        $this->flash($r);
        return Response::redirecionar(url('/negocios/' . (int) $p['id']));
    }
}
