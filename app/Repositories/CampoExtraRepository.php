<?php

declare(strict_types=1);

namespace App\Repositories;

/** Definições dos campos extras (empresas, contatos e negócios). */
final class CampoExtraRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'campos_extras_def';
    }

    protected function ordemPadrao(): string
    {
        return 'a.entidade ASC, a.ordem ASC, a.id ASC';
    }

    /** Definições da entidade, na ordem de exibição. Com $somenteAtivos, só as que aparecem em formulários e filtros. */
    public function daEntidade(string $entidade, bool $somenteAtivos = true): array
    {
        $st = $this->pdo()->prepare(
            'SELECT * FROM campos_extras_def WHERE entidade = :e AND arquivado_em IS NULL'
            . ($somenteAtivos ? ' AND ativo = 1' : '') . ' ORDER BY ordem ASC, id ASC'
        );
        $st->execute(['e' => $entidade]);
        return array_map(self::decodificar(...), $st->fetchAll());
    }

    /** Definição ativa e não arquivada da chave (ou null). */
    public function ativaPorChave(string $entidade, string $chave): ?array
    {
        $st = $this->pdo()->prepare(
            'SELECT * FROM campos_extras_def WHERE entidade = :e AND chave = :c AND ativo = 1 AND arquivado_em IS NULL'
        );
        $st->execute(['e' => $entidade, 'c' => $chave]);
        $linha = $st->fetch();
        return $linha ? self::decodificar($linha) : null;
    }

    /** Existe definição (mesmo arquivada) com esta chave? A restrição UNIQUE(entidade, chave) vale para todas. */
    public function chaveEmUso(string $entidade, string $chave): bool
    {
        $st = $this->pdo()->prepare('SELECT 1 FROM campos_extras_def WHERE entidade = :e AND chave = :c');
        $st->execute(['e' => $entidade, 'c' => $chave]);
        return $st->fetchColumn() !== false;
    }

    public function proximaOrdem(string $entidade): int
    {
        $st = $this->pdo()->prepare('SELECT COALESCE(MAX(ordem), 0) + 10 FROM campos_extras_def WHERE entidade = :e');
        $st->execute(['e' => $entidade]);
        return (int) $st->fetchColumn();
    }

    /** `opcoes` (JSON) vira lista de textos. */
    private static function decodificar(array $linha): array
    {
        $opcoes = $linha['opcoes'] !== null && $linha['opcoes'] !== '' ? json_decode((string) $linha['opcoes'], true) : [];
        $linha['opcoes'] = is_array($opcoes) ? array_values(array_map('strval', $opcoes)) : [];
        return $linha;
    }
}
