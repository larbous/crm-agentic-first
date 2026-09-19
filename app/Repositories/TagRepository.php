<?php

declare(strict_types=1);

namespace App\Repositories;

final class TagRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'tags';
    }

    protected function ordemPadrao(): string
    {
        return 'a.nome COLLATE pt_br ASC';
    }

    /** id => nome das tags ativas. */
    public function opcoes(): array
    {
        return array_column($this->todas(), 'nome', 'id');
    }

    /** Tags de um registro (linhas de tags). */
    public function tagsDe(string $entidade, int $registroId): array
    {
        $st = $this->pdo()->prepare(
            'SELECT t.* FROM taggables g JOIN tags t ON t.id = g.tag_id
             WHERE g.entidade = :e AND g.registro_id = :r AND t.arquivado_em IS NULL ORDER BY t.nome COLLATE pt_br'
        );
        $st->execute(['e' => $entidade, 'r' => $registroId]);
        return $st->fetchAll();
    }

    /** @return list<int> */
    public function idsDe(string $entidade, int $registroId): array
    {
        return array_map(static fn (array $t) => (int) $t['id'], $this->tagsDe($entidade, $registroId));
    }

    /** Tags de vários registros de uma vez: [registro_id => [linhas de tags]]. */
    public function tagsDeVarios(string $entidade, array $registroIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $registroIds)));
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo()->prepare(
            "SELECT g.registro_id, t.id, t.nome, t.cor FROM taggables g JOIN tags t ON t.id = g.tag_id
             WHERE g.entidade = ? AND g.registro_id IN ({$marcas}) AND t.arquivado_em IS NULL ORDER BY t.nome COLLATE pt_br"
        );
        $st->execute([$entidade, ...$ids]);
        $out = [];
        foreach ($st->fetchAll() as $linha) {
            $out[(int) $linha['registro_id']][] = $linha;
        }
        return $out;
    }

    /** Substitui as tags do registro (tabela pivô: sem soft delete). */
    public function substituir(string $entidade, int $registroId, array $tagIds): void
    {
        $pdo = $this->pdo();
        $pdo->prepare('DELETE FROM taggables WHERE entidade = :e AND registro_id = :r')->execute(['e' => $entidade, 'r' => $registroId]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO taggables (tag_id, entidade, registro_id, criado_em) VALUES (:t, :e, :r, :q)');
        foreach ($tagIds as $tagId) {
            $ins->execute(['t' => (int) $tagId, 'e' => $entidade, 'r' => $registroId, 'q' => agora()]);
        }
    }
}
