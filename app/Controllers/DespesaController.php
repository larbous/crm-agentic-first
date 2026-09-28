<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\Repositorios;
use App\Services\Opcoes;
use App\Services\Schema;

/** Despesas da estrutura: contas a pagar do próprio negócio (aluguel, software, impostos...), sem cliente. Sem tela de detalhe própria. */
final class DespesaController extends CrudController
{
    protected function entidade(): string
    {
        return 'despesas';
    }

    protected function rota(): string
    {
        return '/financeiro/despesas';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    protected function colunas(): array
    {
        return [
            'descricao'  => ['rotulo' => 'Descrição', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/financeiro/despesas/' . (int) $l['id'] . '/editar')) . '">' . e((string) $l['descricao']) . '</a>'
                    . ((string) ($l['fornecedor'] ?? '') !== '' ? '<div class="text-muted-foreground text-xs">' . e((string) $l['fornecedor']) . '</div>' : ''),
                'valor' => $l['descricao'],
            ]],
            'categoria'  => ['rotulo' => 'Categoria', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => (string) $l['categoria_nome']],
            'vencimento' => ['rotulo' => 'Vencimento', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => data_br((string) $l['vencimento'])],
            'valor'      => ['rotulo' => 'Valor', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => ['html' => e(moeda((int) $l['valor'])), 'valor' => (int) $l['valor']]],
            'meio'       => ['rotulo' => 'Meio', 'padrao' => true, 'render' => static fn (array $l): string => Schema::opcoes('meio_pagamento')[(string) $l['meio_pagamento']] ?? ''],
            'forma'      => ['rotulo' => 'Forma', 'padrao' => true, 'render' => static fn (array $l): string => Schema::opcoes('forma_pagamento_despesa')[(string) $l['forma_pagamento']] ?? ''],
            'status'     => ['rotulo' => 'Situação', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => self::seloSituacao($l)]],
            'pago_em'    => ['rotulo' => 'Pago em', 'render' => static fn (array $l): string => data_br((string) ($l['data_pagamento'] ?? ''))],
        ];
    }

    /** A pagar / Vencida / Paga / Cancelada: "vencida" é a despesa a pagar cujo vencimento já passou. */
    private static function seloSituacao(array $d): string
    {
        if ($d['status'] === 'pendente' && (string) $d['vencimento'] < hoje()) {
            return badge('Vencida', 'destructive');
        }
        $variantes = ['pendente' => 'warning', 'pago' => 'success', 'cancelado' => 'secondary'];
        return badge(Schema::opcoes('status_despesa')[$d['status']] ?? (string) $d['status'], $variantes[$d['status']] ?? 'secondary');
    }

    protected function filtros(): array
    {
        return [
            'status'          => ['rotulo' => 'Situação', 'opcoes' => Schema::opcoes('status_despesa')],
            'situacao'        => ['rotulo' => 'Vencimento', 'opcoes' => ['vencidas' => 'Vencidas']],
            'categoria_id'    => ['rotulo' => 'Categoria', 'opcoes' => Opcoes::para('categorias_despesa')],
            'meio_pagamento'  => ['rotulo' => 'Meio', 'opcoes' => Schema::opcoes('meio_pagamento')],
            'forma_pagamento' => ['rotulo' => 'Forma', 'opcoes' => Schema::opcoes('forma_pagamento_despesa')],
        ];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados', 'pagamento' => 'Pagamento'];
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
        return parent::padroesNovo() + ['vencimento' => hoje(), 'status' => 'pendente', 'forma_pagamento' => 'a_vista'];
    }

    /** No topo da lista: quanto há a pagar, quanto já venceu e quanto foi pago neste mês. */
    protected function acoesExtrasLista(): string
    {
        $r = Repositorios::despesas()->resumo(hoje(), date('Y-m-01'), date('Y-m-t'));
        return '<span class="text-muted-foreground text-sm">A pagar <strong class="text-foreground">' . e(moeda($r['a_pagar'])) . '</strong></span>'
            . ($r['vencidas'] > 0 ? badge('Vencidas ' . moeda($r['vencidas']), 'destructive') : '')
            . '<span class="text-muted-foreground mr-2 text-sm">Pago no mês <strong class="text-foreground">' . e(moeda($r['pago_no_mes'])) . '</strong></span>';
    }

    /** Sem tela de detalhe própria: abre a edição, como custos e serviços. */
    public function mostrar(array $p): Response
    {
        return Response::redirecionar(url('/financeiro/despesas/' . (int) $p['id'] . '/editar'));
    }

    protected function destinoApos(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/financeiro/despesas'));
    }
}
