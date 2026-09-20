<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditoriaRepository;
use App\Repositories\Repositorios;
use App\Services\Opcoes;
use App\Services\Schema;

/** Contratos: criação a partir de modelo/proposta, envio, impressão, assinatura por link público e renovação. */
final class ContratoController extends CrudController
{
    protected function entidade(): string
    {
        return 'contratos';
    }

    protected function rota(): string
    {
        return '/contratos';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    public static function registrarExtras(Router $r): void
    {
        $r->post('/contratos/{id:\d+}/enviar', [self::class, 'enviar']);
        $r->post('/contratos/{id:\d+}/cancelar', [self::class, 'cancelar']);
        $r->post('/contratos/{id:\d+}/renovar', [self::class, 'renovar']);
        $r->get('/contratos/{id:\d+}/imprimir', [self::class, 'imprimir']);
    }

    protected function colunas(): array
    {
        return [
            'numero'       => ['rotulo' => 'Número', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/contratos/' . (int) $l['id'])) . '">' . e($l['numero']) . '</a>',
                'valor' => $l['numero'],
            ]],
            'titulo'       => ['rotulo' => 'Título', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => $l['titulo']],
            'empresa_nome' => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['empresa_id'] ? link_para('/empresas/' . (int) $l['empresa_id'], (string) $l['empresa_nome']) : '', 'valor' => (string) $l['empresa_nome'],
            ]],
            'tipo_nome'    => ['rotulo' => 'Tipo', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => (string) $l['tipo_nome']],
            'status'       => ['rotulo' => 'Status', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_contrato($l)]],
            'valor_total'  => ['rotulo' => 'Valor total', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => [
                'html' => $l['valor_total'] !== null ? e(moeda((int) $l['valor_total'])) : '', 'valor' => (int) $l['valor_total'],
            ]],
            'valor_mensal' => ['rotulo' => 'Mensal', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => [
                'html' => $l['valor_mensal'] !== null ? e(moeda((int) $l['valor_mensal'])) : '', 'valor' => (int) $l['valor_mensal'],
            ]],
            'data_inicio'  => ['rotulo' => 'Início', 'ordenavel' => true, 'render' => static fn (array $l): string => data_br($l['data_inicio'])],
            'data_fim'     => ['rotulo' => 'Fim', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => fim_vigencia_html($l)]],
            'criado_em'    => ['rotulo' => 'Criado em', 'ordenavel' => true, 'render' => static fn (array $l): string => data_br($l['criado_em'])],
        ];
    }

    protected function filtros(): array
    {
        return [
            'status'     => ['rotulo' => 'Status', 'opcoes' => Schema::opcoes('status_contrato')],
            'tipo_id'    => ['rotulo' => 'Tipo', 'opcoes' => Opcoes::para('contrato_tipos')],
            'vencimento' => ['rotulo' => 'Vencimento', 'opcoes' => ['vencendo' => 'Vencendo em 60 dias', 'vencidos' => 'Vencidos']],
        ];
    }

    protected function abasForm(): array
    {
        return ['basico' => 'Dados', 'valores' => 'Valores', 'vigencia' => 'Vigência e reajuste', 'conteudo' => 'Conteúdo', 'extras' => 'Notas'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return $registro['numero'] . ' — ' . $registro['titulo'];
    }

    protected function ocultarNoForm(?array $registro): array
    {
        return $registro !== null ? ['modelo_id', 'proposta_id'] : [];
    }

    protected function opcoesForm(?array $registro): array
    {
        return [
            'modelo_id'   => Repositorios::modelos()->opcoesPorTipo('contrato'),
            'proposta_id' => Repositorios::propostas()->opcoesAceitas(),
        ];
    }

    /** Novo contrato: vínculos e valores herdados da proposta aceita (?proposta_id=) ou do negócio (?negocio_id=). */
    protected function padroesNovo(): array
    {
        $padrao = parent::padroesNovo() + ['recorrencia' => 'unica', 'indice_reajuste' => 'nenhum', 'aviso_renovacao_dias' => 30, 'renovacao_automatica' => 0];
        if (!empty($padrao['proposta_id']) && ($p = Repositorios::propostas()->encontrar((int) $padrao['proposta_id'])) !== null) {
            $padrao += [
                'titulo' => 'Contrato — ' . preg_replace('/^Proposta\s*[—-]\s*/u', '', $p['titulo']),
                'negocio_id' => $p['negocio_id'], 'empresa_id' => $p['empresa_id'], 'contato_id' => $p['contato_id'],
                'valor_total' => (int) $p['total'],
            ];
            if ((int) $p['total_recorrente'] > 0) {
                $padrao['valor_mensal'] = (int) $p['total_recorrente'];
                $padrao['recorrencia'] = 'mensal';
            }
        } elseif (!empty($padrao['negocio_id']) && ($n = Repositorios::negocios()->encontrar((int) $padrao['negocio_id'])) !== null) {
            $padrao += ['titulo' => 'Contrato — ' . $n['titulo'], 'empresa_id' => $n['empresa_id'], 'contato_id' => $n['contato_principal_id']];
        }
        return $padrao;
    }

    protected function extraAba(string $grupo, array $valores, array $erros): string
    {
        return $grupo === 'conteudo'
            ? '<p class="text-muted-foreground mb-3 text-sm">Deixe o conteúdo em branco ao criar para gerá-lo a partir do modelo escolhido na aba Dados (as variáveis são preenchidas com os dados da empresa, do negócio e da proposta). Depois, edite o texto à vontade enquanto o contrato estiver em rascunho.</p>'
            : '';
    }

    protected function dadosDetalhe(array $registro): array
    {
        $id = (int) $registro['id'];
        return [
            'tarefas'    => Repositorios::tarefas()->porVinculo('contrato_id', $id),
            'renovacoes' => array_filter(Repositorios::contratos()->por('empresa_id', (int) $registro['empresa_id']), static fn (array $c) => (int) $c['contrato_origem_id'] === $id),
            'historico'  => (new AuditoriaRepository())->listar(['entidade' => 'contratos', 'registro_id' => (string) $id], 1, 30)['linhas'],
            'linkPublico' => url_publica('/c/' . $registro['token_publico']),
        ];
    }

    public function enviar(array $p): Response
    {
        $this->flash($this->executor()->enviarContrato((int) $p['id'], 'humano'));
        return Response::redirecionar(url('/contratos/' . (int) $p['id']));
    }

    public function cancelar(array $p): Response
    {
        $this->flash($this->executor()->cancelarContrato((int) $p['id'], 'humano'));
        return Response::redirecionar(url('/contratos/' . (int) $p['id']));
    }

    public function renovar(array $p): Response
    {
        $r = $this->executor()->renovarContrato((int) $p['id'], 'humano');
        $this->flash($r);
        return Response::redirecionar(url('/contratos/' . ($r->ok ? (int) $r->id . '/editar' : (int) $p['id'])));
    }

    public function imprimir(array $p): Response
    {
        $contrato = Repositorios::contratos()->encontrar((int) $p['id']);
        if ($contrato === null) {
            return $this->naoEncontrado();
        }
        return View::pagina('documentos/imprimir_contrato', DocumentoDados::contrato($contrato) + ['titulo' => $contrato['numero']], 200, 'layouts/impressao');
    }
}
