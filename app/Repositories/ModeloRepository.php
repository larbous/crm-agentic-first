<?php

declare(strict_types=1);

namespace App\Repositories;

final class ModeloRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'modelos_documento';
    }

    protected function ordenaveis(): array
    {
        return ['nome' => 'a.nome COLLATE pt_br', 'tipo' => 'a.tipo', 'ativo' => 'a.ativo', 'atualizado_em' => 'a.atualizado_em'];
    }

    protected function filtraveis(): array
    {
        return ['tipo' => 'a.tipo', 'ativo' => 'a.ativo'];
    }

    protected function buscaveis(): array
    {
        return ['a.nome', 'a.assunto', 'a.conteudo'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.tipo ASC, a.nome COLLATE pt_br ASC';
    }

    /** id => nome dos modelos ativos de um tipo. */
    public function opcoesPorTipo(string $tipo): array
    {
        $st = $this->pdo()->prepare(
            'SELECT id, nome FROM modelos_documento WHERE tipo = :t AND ativo = 1 AND arquivado_em IS NULL ORDER BY nome COLLATE pt_br'
        );
        $st->execute(['t' => $tipo]);
        return array_column($st->fetchAll(), 'nome', 'id');
    }
}
