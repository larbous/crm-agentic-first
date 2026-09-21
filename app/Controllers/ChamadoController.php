<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Repositories\AuditoriaRepository;
use App\Services\Checklist;
use App\Services\Opcoes;
use App\Services\Schema;

/**
 * Chamados (Fase 13): demandas de execução por área, com checklist. Aparecem na tela de Tarefas (TarefaController)
 * e têm lista, formulário e detalhe próprios. Toda escrita passa pelo ActionExecutor.
 */
final class ChamadoController extends CrudController
{
    protected function entidade(): string
    {
        return 'chamados';
    }

    protected function rota(): string
    {
        return '/chamados';
    }

    public static function registrarExtras(Router $r): void
    {
        $r->post('/chamados/{id:\d+}/status', [self::class, 'status']);
        $r->post('/chamados/{id:\d+}/checklist', [self::class, 'adicionarItem']);
        $r->post('/chamados/{id:\d+}/checklist/{indice:\d+}/alternar', [self::class, 'alternarItem']);
        $r->post('/chamados/{id:\d+}/checklist/{indice:\d+}/remover', [self::class, 'removerItem']);
    }

    protected function colunas(): array
    {
        return [
            'codigo'     => ['rotulo' => 'Código', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/chamados/' . (int) $l['id'])) . '">' . e((string) $l['codigo']) . '</a>', 'valor' => $l['codigo'],
            ]],
            'titulo'     => ['rotulo' => 'Chamado', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => (string) $l['titulo']],
            'area'       => ['rotulo' => 'Área', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => (string) $l['area_nome']],
            'empresa'    => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['empresa_id'] ? link_para('/empresas/' . (int) $l['empresa_id'], (string) $l['empresa_nome']) : '', 'valor' => (string) $l['empresa_nome'],
            ]],
            'prioridade' => ['rotulo' => 'Prioridade', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_prioridade((string) $l['prioridade'])]],
            'status'     => ['rotulo' => 'Status', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_chamado_status((string) $l['status'])]],
            'vencimento' => ['rotulo' => 'Prazo', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => e(vencimento_br($l['vencimento'])) . (chamado_atrasado($l) ? ' ' . badge('atrasado', 'destructive') : ''), 'valor' => (string) $l['vencimento'],
            ]],
            'checklist'  => ['rotulo' => 'Checklist', 'padrao' => true, 'render' => static fn (array $l): string => checklist_resumo($l['checklist'])],
        ];
    }

    protected function filtros(): array
    {
        return [
            'area_id'    => ['rotulo' => 'Área', 'opcoes' => Opcoes::para('areas')],
            'status'     => ['rotulo' => 'Status', 'opcoes' => Schema::opcoes('status_chamado')],
            'prioridade' => ['rotulo' => 'Prioridade', 'opcoes' => Schema::opcoes('prioridade_tar')],
            'situacao'   => ['rotulo' => 'Situação', 'opcoes' => ['abertos' => 'Abertos', 'fechados' => 'Fechados']],
        ];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados', 'vinculos' => 'Vínculos', 'checklist' => 'Checklist'];
    }

    protected function usaTags(): bool
    {
        return false;
    }

    protected function tituloRegistro(array $registro): string
    {
        return $registro['codigo'] . ' · ' . $registro['titulo'];
    }

    protected function dadosDetalhe(array $registro): array
    {
        return [
            'itens' => Checklist::itens($registro['checklist']),
            'historico' => (new AuditoriaRepository())->listar(['entidade' => 'chamados', 'registro_id' => (string) $registro['id']], 1, 30)['linhas'],
        ];
    }

    /** O prazo tem data e hora em campos separados e o checklist é editado como texto: ver extraAba(). */
    protected function ocultarNoForm(?array $registro): array
    {
        return ['vencimento', 'checklist', 'resolucao'];
    }

    protected function padroesNovo(): array
    {
        return parent::padroesNovo() + ['prioridade' => 'media', 'status' => 'aberto'];
    }

    protected function destinoApos(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/chamados/' . $id));
    }

    protected function prepararEntrada(array $entrada): array
    {
        $data = trim((string) ($_POST['vencimento_data'] ?? ''));
        $hora = trim((string) ($_POST['vencimento_hora'] ?? ''));
        $entrada['vencimento'] = $data === '' ? null : ($hora === '' ? $data : $data . ' ' . $hora);
        if (array_key_exists('checklist_texto', $_POST)) {
            $entrada['checklist'] = Checklist::deTexto((string) $_POST['checklist_texto']);
        }
        if (array_key_exists('resolucao', $_POST)) {
            $entrada['resolucao'] = $_POST['resolucao'];
        }
        return $entrada;
    }

    protected function valoresParaForm(array $valores): array
    {
        $venc = (string) ($valores['vencimento'] ?? '');
        $valores['vencimento_data'] = $venc !== '' ? substr($venc, 0, 10) : '';
        $valores['vencimento_hora'] = strlen($venc) >= 16 ? substr($venc, 11, 5) : '';
        if (!isset($valores['checklist_texto'])) {
            $c = $valores['checklist'] ?? null;
            $valores['checklist_texto'] = is_array($c) ? implode("\n", array_map(static fn (array $i): string => (($i['feito'] ?? 0) === 1 ? '[x] ' : '[ ] ') . $i['texto'], $c)) : Checklist::paraTexto($c);
        }
        return $valores;
    }

    protected function extraAba(string $grupo, array $valores, array $erros): string
    {
        if ($grupo === 'dados') {
            return '<div class="mt-4 grid gap-4 md:grid-cols-2">'
                . campo(['nome' => 'vencimento_data', 'rotulo' => 'Prazo de entrega', 'tipo' => 'date', 'valor' => $valores['vencimento_data'] ?? '', 'erro' => $erros['vencimento'] ?? null])
                . campo(['nome' => 'vencimento_hora', 'rotulo' => 'Hora (opcional)', 'tipo' => 'time', 'valor' => $valores['vencimento_hora'] ?? ''])
                . '<div class="md:col-span-2">' . campo(['nome' => 'resolucao', 'rotulo' => 'Resolução (o que foi entregue)', 'tipo' => 'textarea', 'ia' => true, 'linhas' => 3,
                    'valor' => (string) ($valores['resolucao'] ?? ''), 'erro' => $erros['resolucao'] ?? null, 'ajuda' => 'Preencha ao fechar o chamado.']) . '</div>'
                . '</div>';
        }
        if ($grupo === 'checklist') {
            return '<div class="mt-4">' . campo([
                'nome' => 'checklist_texto', 'rotulo' => 'Itens do checklist', 'tipo' => 'textarea', 'linhas' => 10, 'valor' => $valores['checklist_texto'] ?? '',
                'erro' => $erros['checklist'] ?? null,
                'ajuda' => 'Um item por linha. Comece a linha com "[x]" para marcar como feito. Também dá para marcar os itens direto na página do chamado.',
            ]) . '</div>';
        }
        return '';
    }

    // ---- Ações da página do chamado ------------------------------------------------------

    /** POST /chamados/{id}/status — status novo (e a resolução, ao concluir). */
    public function status(array $p): Response
    {
        $novo = (string) ($_POST['status'] ?? '');
        if (!isset(Schema::opcoes('status_chamado')[$novo])) {
            $this->flashErro('Status inválido.');
            return Response::redirecionar($this->voltarPara((int) $p['id']));
        }
        $resolucao = isset($_POST['resolucao']) ? (string) $_POST['resolucao'] : null;
        $this->flash($this->executor()->mudarStatusChamado((int) $p['id'], $novo, $resolucao, 'humano'));
        return Response::redirecionar($this->voltarPara((int) $p['id']));
    }

    public function adicionarItem(array $p): Response
    {
        $this->flash($this->executor()->adicionarItemChecklist((int) $p['id'], (string) ($_POST['texto'] ?? ''), 'humano'));
        return Response::redirecionar($this->voltarPara((int) $p['id']));
    }

    public function alternarItem(array $p): Response
    {
        $this->flash($this->executor()->alternarItemChecklist((int) $p['id'], (int) $p['indice'], 'humano'));
        return Response::redirecionar($this->voltarPara((int) $p['id']));
    }

    public function removerItem(array $p): Response
    {
        $this->flash($this->executor()->removerItemChecklist((int) $p['id'], (int) $p['indice'], 'humano'));
        return Response::redirecionar($this->voltarPara((int) $p['id']));
    }

    // ---- Apoio ----------------------------------------------------------------------------

    private function voltarPara(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/chamados/' . $id));
    }

    private function flashErro(string $mensagem): void
    {
        \App\Core\Session::flash('error', $mensagem);
    }
}
