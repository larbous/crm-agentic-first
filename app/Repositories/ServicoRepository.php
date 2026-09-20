<?php

declare(strict_types=1);

namespace App\Repositories;

final class ServicoRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'servicos';
    }

    protected function ordenaveis(): array
    {
        return [
            'nome' => 'a.nome COLLATE pt_br', 'categoria' => 'a.categoria', 'unidade' => 'a.unidade', 'preco_base' => 'a.preco_base',
            'ativo' => 'a.ativo', 'criado_em' => 'a.criado_em',
        ];
    }

    protected function filtraveis(): array
    {
        return ['categoria' => 'a.categoria', 'ativo' => 'a.ativo', 'unidade' => 'a.unidade'];
    }

    protected function buscaveis(): array
    {
        return ['a.nome', 'a.descricao', 'a.entregaveis'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.ativo DESC, a.nome COLLATE pt_br ASC';
    }

    /** Serviços ativos com os dados usados para preencher itens de proposta. */
    public function ativos(): array
    {
        return $this->pdo()->query(
            'SELECT id, nome, descricao, unidade, preco_base, recorrente FROM servicos WHERE arquivado_em IS NULL AND ativo = 1 ORDER BY nome COLLATE pt_br'
        )->fetchAll();
    }

    /** id => nome dos serviços ativos. */
    public function opcoes(): array
    {
        return array_column($this->ativos(), 'nome', 'id');
    }
}
