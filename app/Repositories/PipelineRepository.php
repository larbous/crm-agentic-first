<?php

declare(strict_types=1);

namespace App\Repositories;

final class PipelineRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'pipelines';
    }

    protected function ordemPadrao(): string
    {
        return 'a.padrao DESC, a.id ASC';
    }

    public function padrao(): ?array
    {
        $r = $this->pdo()->query('SELECT * FROM pipelines WHERE arquivado_em IS NULL ORDER BY padrao DESC, id ASC LIMIT 1')->fetch();
        return $r ?: null;
    }

    /** id => nome. */
    public function opcoes(): array
    {
        return array_column($this->todas(), 'nome', 'id');
    }
}
