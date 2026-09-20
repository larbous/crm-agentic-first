<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/**
 * Tabelas squads e squads_versoes. A definição completa fica em JSON; slug, nome, descrição, versão e ativo são
 * colunas para listar e buscar. Toda versão salva é guardada em squads_versoes (mesmo modelo dos agentes).
 */
final class SquadRepository
{
    /** @return list<array> squads com a definição já decodificada (`def`) */
    public function todos(bool $somenteAtivos = false): array
    {
        $sql = 'SELECT * FROM squads' . ($somenteAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY nome COLLATE pt_br';
        return array_map($this->decodificar(...), DB::conexao()->query($sql)->fetchAll());
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM squads WHERE id = :id');
        $st->execute(['id' => $id]);
        $l = $st->fetch();
        return $l ? $this->decodificar($l) : null;
    }

    public function porSlug(string $slug): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM squads WHERE slug = :s');
        $st->execute(['s' => $slug]);
        $l = $st->fetch();
        return $l ? $this->decodificar($l) : null;
    }

    /** Squads ativos que trabalham sobre a entidade (para os botões do detalhe). */
    public function ativosDaEntrada(string $entidade): array
    {
        return array_values(array_filter($this->todos(true), static fn (array $s): bool => ($s['def']['entrada'] ?? '') === $entidade));
    }

    public function inserir(array $definicao, bool $ativo = true): int
    {
        $pdo = DB::conexao();
        $json = AgenteRepository::json($definicao);
        $pdo->prepare(
            'INSERT INTO squads (slug, nome, descricao, definicao, versao, ativo, criado_em, atualizado_em)
             VALUES (:slug, :nome, :descricao, :definicao, :versao, :ativo, :agora, :agora)'
        )->execute([
            'slug' => $definicao['slug'], 'nome' => $definicao['nome'], 'descricao' => $definicao['descricao'] ?? '',
            'definicao' => $json, 'versao' => (int) $definicao['versao'], 'ativo' => $ativo ? 1 : 0, 'agora' => agora(),
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->registrarVersao($id, (int) $definicao['versao'], $json);
        return $id;
    }

    /** Grava uma nova versão de um squad existente (a definição já traz o número da versão). */
    public function novaVersao(int $id, array $definicao): void
    {
        $json = AgenteRepository::json($definicao);
        DB::conexao()->prepare(
            'UPDATE squads SET slug = :slug, nome = :nome, descricao = :descricao, definicao = :definicao, versao = :versao, atualizado_em = :agora WHERE id = :id'
        )->execute([
            'slug' => $definicao['slug'], 'nome' => $definicao['nome'], 'descricao' => $definicao['descricao'] ?? '',
            'definicao' => $json, 'versao' => (int) $definicao['versao'], 'agora' => agora(), 'id' => $id,
        ]);
        $this->registrarVersao($id, (int) $definicao['versao'], $json);
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        DB::conexao()->prepare('UPDATE squads SET ativo = :a, atualizado_em = :q WHERE id = :id')
            ->execute(['a' => $ativo ? 1 : 0, 'q' => agora(), 'id' => $id]);
    }

    /** @return list<array{versao:int,definicao:array,criado_em:string}> da mais nova para a mais antiga */
    public function versoes(int $squadId): array
    {
        $st = DB::conexao()->prepare('SELECT versao, definicao, criado_em FROM squads_versoes WHERE squad_id = :id ORDER BY versao DESC');
        $st->execute(['id' => $squadId]);
        return array_map(static fn (array $l): array => [
            'versao' => (int) $l['versao'], 'definicao' => (array) json_decode((string) $l['definicao'], true), 'criado_em' => (string) $l['criado_em'],
        ], $st->fetchAll());
    }

    public function versao(int $squadId, int $versao): ?array
    {
        foreach ($this->versoes($squadId) as $v) {
            if ($v['versao'] === $versao) {
                return $v['definicao'];
            }
        }
        return null;
    }

    private function registrarVersao(int $squadId, int $versao, string $json): void
    {
        DB::conexao()->prepare('INSERT INTO squads_versoes (squad_id, versao, definicao, criado_em) VALUES (:s, :v, :d, :q)')
            ->execute(['s' => $squadId, 'v' => $versao, 'd' => $json, 'q' => agora()]);
    }

    private function decodificar(array $linha): array
    {
        $linha['def'] = (array) json_decode((string) $linha['definicao'], true);
        return $linha;
    }
}
