<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;

final class AtividadeRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'atividades';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome, n.titulo AS negocio_titulo,
                    e.nome_fantasia AS empresa_nome
                FROM atividades a
                LEFT JOIN contatos c ON c.id = a.contato_id
                LEFT JOIN negocios n ON n.id = a.negocio_id
                LEFT JOIN empresas e ON e.id = a.empresa_id";
    }

    protected function ordemPadrao(): string
    {
        return 'a.data_hora DESC, a.id DESC';
    }

    /** Timeline de um registro: $campo é empresa_id, contato_id ou negocio_id. */
    public function timeline(string $campo, int $id, int $limite = 100): array
    {
        if (!in_array($campo, ['empresa_id', 'contato_id', 'negocio_id'], true)) {
            throw new InvalidArgumentException("Campo de vínculo inválido: {$campo}");
        }
        $limite = max(1, min(500, $limite));
        $st = $this->pdo()->prepare(
            $this->selectBase() . " WHERE a.{$campo} = :id AND a.arquivado_em IS NULL ORDER BY a.data_hora DESC, a.id DESC LIMIT {$limite}"
        );
        $st->execute(['id' => $id]);
        return $st->fetchAll();
    }
}
