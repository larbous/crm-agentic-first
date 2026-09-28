<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;

/** Tabelas de apoio só com nome (origens, motivos_perda). */
final class SimplesRepository extends BaseRepository
{
    public function __construct(private string $tabela)
    {
        if (!in_array($tabela, ['origens', 'motivos_perda', 'contrato_tipos', 'areas', 'categorias_despesa'], true)) {
            throw new InvalidArgumentException("Tabela não permitida: {$tabela}");
        }
    }

    public function tabela(): string
    {
        return $this->tabela;
    }

    protected function ordemPadrao(): string
    {
        return 'a.nome COLLATE pt_br ASC';
    }

    /** Registro ativo com este nome (sem acento/caixa), ou null. */
    public function porNome(string $nome): ?array
    {
        $st = $this->pdo()->prepare("SELECT * FROM {$this->tabela} WHERE arquivado_em IS NULL AND busca_norm(nome) = :n ORDER BY id LIMIT 1");
        $st->execute(['n' => normalizar_busca(trim($nome))]);
        return $st->fetch() ?: null;
    }

    /** id => nome. */
    public function opcoes(): array
    {
        return array_column($this->todas(), 'nome', 'id');
    }
}
