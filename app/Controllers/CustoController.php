<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;

/** Custos (Fase 17): lançamento manual vinculado a empresa e/ou negócio, usado no DRE por cliente. Sem tela de detalhe própria. */
final class CustoController extends CrudController
{
    protected function entidade(): string
    {
        return 'custos';
    }

    protected function rota(): string
    {
        return '/financeiro/custos';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    protected function colunas(): array
    {
        return [
            'descricao'  => ['rotulo' => 'Descrição', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/financeiro/custos/' . (int) $l['id'] . '/editar')) . '">' . e((string) $l['descricao']) . '</a>', 'valor' => $l['descricao'],
            ]],
            'empresa'    => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['empresa_id'] ? link_para('/empresas/' . (int) $l['empresa_id'], (string) $l['empresa_nome']) : '', 'valor' => (string) $l['empresa_nome'],
            ]],
            'negocio'    => ['rotulo' => 'Negócio', 'render' => static fn (array $l): array => [
                'html' => $l['negocio_id'] ? link_para('/negocios/' . (int) $l['negocio_id'], (string) $l['negocio_titulo']) : '',
            ]],
            'categoria'  => ['rotulo' => 'Categoria', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => (string) ($l['categoria'] ?? '')],
            'valor'      => ['rotulo' => 'Valor', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => ['html' => e(moeda((int) $l['valor'])), 'valor' => (int) $l['valor']]],
            'data'       => ['rotulo' => 'Data', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => data_br((string) $l['data'])],
            'recorrente' => ['rotulo' => 'Recorrente', 'padrao' => true, 'render' => static fn (array $l): array => ['html' => (int) $l['recorrente'] === 1 ? badge('Recorrente', 'info') : '']],
        ];
    }

    protected function filtros(): array
    {
        return ['recorrente' => ['rotulo' => 'Recorrência', 'opcoes' => ['1' => 'Recorrentes', '0' => 'Únicos']]];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return (string) $registro['descricao'];
    }

    protected function dadosDetalhe(array $registro): array
    {
        return [];
    }

    protected function padroesNovo(): array
    {
        return parent::padroesNovo() + ['data' => hoje(), 'recorrente' => 0];
    }

    /** Sem tela de detalhe própria: abre a edição, como o catálogo de serviços. */
    public function mostrar(array $p): Response
    {
        return Response::redirecionar(url('/financeiro/custos/' . (int) $p['id'] . '/editar'));
    }

    protected function destinoApos(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/financeiro/custos'));
    }
}
