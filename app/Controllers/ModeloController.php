<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\Html;
use App\Services\Opcoes;
use App\Services\Schema;
use App\Services\Variaveis;

/** Modelos de documento: editor com lista de variáveis e pré-visualização com registro de exemplo. */
final class ModeloController extends CrudController
{
    protected function entidade(): string
    {
        return 'modelos_documento';
    }

    protected function rota(): string
    {
        return '/modelos';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    public static function registrarExtras(Router $r): void
    {
        $r->post('/api/modelos/preview', [self::class, 'preview']);
    }

    protected function colunas(): array
    {
        return [
            'nome'          => ['rotulo' => 'Modelo', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/modelos/' . (int) $l['id'] . '/editar')) . '">' . e($l['nome']) . '</a>',
                'valor' => $l['nome'],
            ]],
            'tipo'          => ['rotulo' => 'Tipo', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => Schema::opcoes('tipo_modelo')[$l['tipo']] ?? $l['tipo']],
            'assunto'       => ['rotulo' => 'Assunto', 'padrao' => true, 'render' => static fn (array $l): string => (string) $l['assunto']],
            'ativo'         => ['rotulo' => 'Situação', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => (int) $l['ativo'] === 1 ? badge('Ativo', 'success') : badge('Inativo', 'secondary'),
            ]],
            'atualizado_em' => ['rotulo' => 'Atualizado em', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => datahora_br($l['atualizado_em'])],
        ];
    }

    protected function filtros(): array
    {
        return [
            'tipo'  => ['rotulo' => 'Tipo', 'opcoes' => Schema::opcoes('tipo_modelo')],
            'ativo' => ['rotulo' => 'Situação', 'opcoes' => ['1' => 'Ativos', '0' => 'Inativos']],
        ];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return $registro['nome'];
    }

    protected function dadosDetalhe(array $registro): array
    {
        return [];
    }

    protected function padroesNovo(): array
    {
        return ['tipo' => $_GET['tipo'] ?? 'proposta', 'ativo' => 1];
    }

    public function mostrar(array $p): Response
    {
        return Response::redirecionar(url('/modelos/' . (int) $p['id'] . '/editar'));
    }

    protected function destinoApos(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/modelos/' . $id . '/editar'));
    }

    /** Editor próprio: campos, variáveis clicáveis e pré-visualização. */
    protected function renderForm(?array $registro, array $valores, array $erros, int $status = 200): Response
    {
        $schema = Schema::entidade('modelos_documento');
        $editando = $registro !== null;
        return View::pagina('modelos/form', [
            'titulo'    => $editando ? 'Editar modelo' : 'Novo modelo',
            'registro'  => $registro,
            'acao'      => url('/modelos' . ($editando ? '/' . (int) $registro['id'] : '')),
            'cancelar'  => url('/modelos'),
            'valores'   => $valores,
            'erros'     => $erros,
            'catalogo'  => Variaveis::catalogo(),
            'negocios'  => Opcoes::para('negocios'),
            'schema'    => $schema,
        ], $status);
    }

    /** POST /api/modelos/preview {tipo, conteudo, negocio_id?} → HTML renderizado com dados reais do negócio ou de exemplo. */
    public function preview(): Response
    {
        $dados = json_decode((string) file_get_contents('php://input'), true);
        $dados = is_array($dados) ? $dados : $_POST;
        $tipo = (string) ($dados['tipo'] ?? 'proposta');
        $conteudo = (string) ($dados['conteudo'] ?? '');
        if (!array_key_exists($tipo, Schema::opcoes('tipo_modelo')) || mb_strlen($conteudo) > 200000) {
            return Response::json(['erro' => 'Dados inválidos.'], 422);
        }

        $ctx = Variaveis::exemplo();
        $real = false;
        if (ctype_digit((string) ($dados['negocio_id'] ?? '')) && (int) $dados['negocio_id'] > 0) {
            $negocioId = (int) $dados['negocio_id'];
            $reais = Variaveis::contexto(['negocio_id' => $negocioId]);
            $proposta = Repositorios::propostas()->ultimasPor('negocio_id', $negocioId)[0] ?? null;
            if ($proposta !== null) {
                $reais = Variaveis::contexto(['proposta_id' => (int) $proposta['id']]) + $reais;
            }
            $contrato = Repositorios::contratos()->por('negocio_id', $negocioId)[0] ?? null;
            if ($contrato !== null) {
                $reais['contrato'] = $contrato;
            }
            $ctx = $reais + $ctx;
            $real = $reais !== [];
        }

        $naoResolvidas = [];
        $html = $tipo === 'contrato';
        $saida = Variaveis::renderizar($conteudo, $ctx, $html, $naoResolvidas);
        return Response::json([
            'html'          => $html ? Html::sanitizar($saida) : Html::textoParaHtml($saida),
            'naoResolvidas' => array_values(array_unique($naoResolvidas)),
            'dadosReais'    => $real,
        ]);
    }
}
