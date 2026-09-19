<?php

declare(strict_types=1);

namespace App\Repositories;

final class AnexoRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'anexos';
    }

    protected function ordemPadrao(): string
    {
        return 'a.criado_em DESC, a.id DESC';
    }

    public function porRegistro(string $entidade, int $registroId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT a.* FROM anexos a WHERE a.entidade = :e AND a.registro_id = :r AND a.arquivado_em IS NULL ORDER BY a.criado_em DESC, a.id DESC'
        );
        $st->execute(['e' => $entidade, 'r' => $registroId]);
        return $st->fetchAll();
    }
}
