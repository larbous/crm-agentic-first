<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;
use App\Services\Opcoes;
use App\Services\Schema;
use App\Services\Variaveis;

/** Propostas: editor com itens e totais em tempo real, versões, envio, impressão e link público. */
final class PropostaController extends CrudController
{
    protected function entidade(): string
    {
        return 'propostas';
    }

    protected function rota(): string
    {
        return '/propostas';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    public static function registrarExtras(Router $r): void
    {
        $r->post('/propostas/{id:\d+}/enviar', [self::class, 'enviar']);
        $r->post('/propostas/{id:\d+}/nova-versao', [self::class, 'novaVersao']);
        $r->get('/propostas/{id:\d+}/imprimir', [self::class, 'imprimir']);
    }

    protected function colunas(): array
    {
        return [
            'numero'       => ['rotulo' => 'Número', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/propostas/' . (int) $l['id'])) . '">' . e($l['numero']) . '</a>'
                    . ' <span class="text-muted-foreground text-xs">v' . (int) $l['versao'] . '</span>',
                'valor' => $l['numero'],
            ]],
            'titulo'       => ['rotulo' => 'Título', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => $l['titulo']],
            'empresa_nome' => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['empresa_id'] ? link_para('/empresas/' . (int) $l['empresa_id'], (string) $l['empresa_nome']) : '', 'valor' => (string) $l['empresa_nome'],
            ]],
            'negocio'      => ['rotulo' => 'Negócio', 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['negocio_id'] ? link_para('/negocios/' . (int) $l['negocio_id'], (string) $l['negocio_codigo']) : '',
            ]],
            'status'       => ['rotulo' => 'Status', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_proposta($l)]],
            'total'        => ['rotulo' => 'Total', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => ['html' => e(moeda((int) $l['total'])), 'valor' => (int) $l['total']]],
            'validade'     => ['rotulo' => 'Validade', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => data_br($l['validade'])],
            'enviada_em'   => ['rotulo' => 'Enviada em', 'ordenavel' => true, 'render' => static fn (array $l): string => datahora_br($l['enviada_em'])],
            'criado_em'    => ['rotulo' => 'Criada em', 'ordenavel' => true, 'render' => static fn (array $l): string => data_br($l['criado_em'])],
        ];
    }

    protected function filtros(): array
    {
        return ['status' => ['rotulo' => 'Status', 'opcoes' => Schema::opcoes('status_proposta')]];
    }

    protected function abasForm(): array
    {
        return ['basico' => 'Dados', 'itens' => 'Itens', 'condicoes' => 'Condições e desconto', 'conteudo' => 'Conteúdo'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return $registro['numero'] . ' v' . $registro['versao'] . ' — ' . $registro['titulo'];
    }

    protected function ocultarNoForm(?array $registro): array
    {
        return $registro !== null ? ['modelo_id'] : [];
    }

    protected function opcoesForm(?array $registro): array
    {
        return ['modelo_id' => Repositorios::modelos()->opcoesPorTipo('proposta')];
    }

    protected function padroesNovo(): array
    {
        $padrao = parent::padroesNovo() + ['desconto_tipo' => 'valor', 'data_emissao' => hoje(), 'itens' => []];
        if (!empty($padrao['negocio_id']) && ($n = Repositorios::negocios()->encontrar((int) $padrao['negocio_id'])) !== null) {
            $padrao += [
                'titulo'     => 'Proposta — ' . $n['titulo'],
                'empresa_id' => $n['empresa_id'],
                'contato_id' => $n['contato_principal_id'],
            ];
        }
        return $padrao;
    }

    /** Itens vêm do editor como itens[n][campo]; a chave "itens" é tratada pelo ActionExecutor. */
    protected function prepararEntrada(array $entrada): array
    {
        $itens = $_POST['itens'] ?? [];
        $entrada['itens'] = is_array($itens) ? array_values(array_filter($itens, 'is_array')) : [];
        return $entrada;
    }

    protected function valoresParaForm(array $valores): array
    {
        if (isset($valores['id']) && !isset($valores['itens'])) {
            $valores['itens'] = array_map(static fn (array $i): array => [
                'servico_id' => $i['servico_id'], 'descricao' => $i['descricao'],
                'quantidade' => rtrim(rtrim(number_format((float) $i['quantidade'], 3, ',', ''), '0'), ','),
                'unidade' => $i['unidade'], 'valor_unitario' => centavos_para_texto((int) $i['valor_unitario']),
                'desconto' => (int) $i['desconto'] > 0 ? centavos_para_texto((int) $i['desconto']) : '', 'recorrente' => (int) $i['recorrente'],
            ], (new ItemPropostaRepository())->porProposta((int) $valores['id']));
        }
        return $valores;
    }

    protected function extraAba(string $grupo, array $valores, array $erros): string
    {
        if ($grupo !== 'itens') {
            return '';
        }
        return View::partial('propostas/editor_itens', [
            'itens'    => $valores['itens'] ?? [],
            'servicos' => Repositorios::servicos()->ativos(),
            'erro'     => $erros['itens'] ?? null,
        ]);
    }

    // ---- Detalhe e ações -----------------------------------------------------------------

    protected function dadosDetalhe(array $registro): array
    {
        $id = (int) $registro['id'];
        $versoes = Repositorios::propostas()->versoes($registro['numero']);
        return [
            'itens'       => (new ItemPropostaRepository())->porProposta($id),
            'versoes'     => $versoes,
            'ehUltima'    => (int) $versoes[0]['id'] === $id,
            'historico'   => (new AuditoriaRepository())->listar(['entidade' => 'propostas', 'registro_id' => (string) $id], 1, 30)['linhas'],
            'contratos'   => Repositorios::contratos()->por('proposta_id', $id),
            'linkPublico' => url_publica('/p/' . $registro['token_publico']),
        ];
    }

    public function enviar(array $p): Response
    {
        $this->flash($this->executor()->enviarProposta((int) $p['id'], 'humano'));
        return Response::redirecionar(url('/propostas/' . (int) $p['id']));
    }

    public function novaVersao(array $p): Response
    {
        $r = $this->executor()->novaVersaoProposta((int) $p['id'], 'humano');
        $this->flash($r);
        return Response::redirecionar(url('/propostas/' . ($r->ok ? (int) $r->id . '/editar' : (int) $p['id'])));
    }

    /** Versão para impressão/PDF pelo navegador (A4). */
    public function imprimir(array $p): Response
    {
        $proposta = Repositorios::propostas()->encontrar((int) $p['id']);
        if ($proposta === null) {
            return $this->naoEncontrado();
        }
        return View::pagina('documentos/imprimir_proposta', DocumentoDados::proposta($proposta) + ['titulo' => $proposta['numero'] . ' v' . $proposta['versao']], 200, 'layouts/impressao');
    }
}
