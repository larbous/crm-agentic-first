<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\Schema;

/** Catálogo de serviços (SPEC §4.6). */
final class ServicoController extends CrudController
{
    protected function entidade(): string
    {
        return 'servicos';
    }

    protected function rota(): string
    {
        return '/servicos';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    protected function colunas(): array
    {
        return [
            'nome'         => ['rotulo' => 'Serviço', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/servicos/' . (int) $l['id'] . '/editar')) . '">' . e($l['nome']) . '</a>',
                'valor' => $l['nome'],
            ]],
            'categoria'    => ['rotulo' => 'Categoria', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => Schema::opcoes('categoria_servico')[$l['categoria']] ?? $l['categoria']],
            'unidade'      => ['rotulo' => 'Unidade', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => Schema::opcoes('unidade_servico')[$l['unidade']] ?? $l['unidade']],
            'preco_base'   => ['rotulo' => 'Preço base', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => [
                'html' => $l['preco_base'] !== null ? e(moeda((int) $l['preco_base'])) : '', 'valor' => (int) $l['preco_base'],
            ]],
            'preco_minimo' => ['rotulo' => 'Preço mínimo', 'alinhar' => 'direita', 'render' => static fn (array $l): string => $l['preco_minimo'] !== null ? moeda((int) $l['preco_minimo']) : ''],
            'recorrente'   => ['rotulo' => 'Recorrente', 'padrao' => true, 'render' => static fn (array $l): array => ['html' => (int) $l['recorrente'] === 1 ? badge('Recorrente', 'info') : '']],
            'prazo'        => ['rotulo' => 'Prazo padrão', 'render' => static fn (array $l): string => $l['prazo_padrao_dias'] !== null ? $l['prazo_padrao_dias'] . ' dias' : ''],
            'ativo'        => ['rotulo' => 'Situação', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => (int) $l['ativo'] === 1 ? badge('Ativo', 'success') : badge('Inativo', 'secondary'),
            ]],
        ];
    }

    protected function filtros(): array
    {
        return [
            'categoria' => ['rotulo' => 'Categoria', 'opcoes' => Schema::opcoes('categoria_servico')],
            'unidade'   => ['rotulo' => 'Unidade', 'opcoes' => Schema::opcoes('unidade_servico')],
            'ativo'     => ['rotulo' => 'Situação', 'opcoes' => ['1' => 'Ativos', '0' => 'Inativos']],
        ];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados do serviço'];
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
        return parent::padroesNovo() + ['categoria' => 'outro', 'unidade' => 'projeto', 'ativo' => 1, 'recorrente' => 0];
    }

    /** O catálogo não tem tela de detalhe: abre a edição. */
    public function mostrar(array $p): Response
    {
        return Response::redirecionar(url('/servicos/' . (int) $p['id'] . '/editar'));
    }

    protected function destinoApos(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/servicos'));
    }
}
