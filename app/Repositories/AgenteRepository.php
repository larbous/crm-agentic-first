<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/**
 * Tabelas agentes e agentes_versoes. A definição completa fica em JSON; slug, nome, descrição, versão e
 * ativo são colunas para listar e buscar. Toda versão salva é guardada em agentes_versoes.
 */
final class AgenteRepository
{
    /** @return list<array> agentes com a definição já decodificada */
    public function todos(bool $somenteAtivos = false): array
    {
        $sql = 'SELECT * FROM agentes' . ($somenteAtivos ? ' WHERE ativo = 1' : '') . ' ORDER BY nome COLLATE pt_br';
        return array_map($this->decodificar(...), DB::conexao()->query($sql)->fetchAll());
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM agentes WHERE id = :id');
        $st->execute(['id' => $id]);
        $l = $st->fetch();
        return $l ? $this->decodificar($l) : null;
    }

    public function porSlug(string $slug): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM agentes WHERE slug = :s');
        $st->execute(['s' => $slug]);
        $l = $st->fetch();
        return $l ? $this->decodificar($l) : null;
    }

    /** Agentes ativos que trabalham sobre a entidade (para os botões do detalhe). */
    public function ativosDaEntrada(string $entidade): array
    {
        return array_values(array_filter($this->todos(true), static fn (array $a): bool => ($a['def']['entrada'] ?? '') === $entidade));
    }

    /** Insere um agente novo (versão 1) e registra a versão. */
    public function inserir(array $definicao, bool $ativo = true): int
    {
        $pdo = DB::conexao();
        $agora = agora();
        $json = self::json($definicao);
        $pdo->prepare(
            'INSERT INTO agentes (slug, nome, descricao, definicao, versao, ativo, criado_em, atualizado_em)
             VALUES (:slug, :nome, :descricao, :definicao, :versao, :ativo, :agora, :agora)'
        )->execute([
            'slug' => $definicao['slug'], 'nome' => $definicao['nome'], 'descricao' => $definicao['descricao'] ?? '',
            'definicao' => $json, 'versao' => (int) $definicao['versao'], 'ativo' => $ativo ? 1 : 0, 'agora' => $agora,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->registrarVersao($id, (int) $definicao['versao'], $json);
        return $id;
    }

    /** Grava uma nova versão de um agente existente (a definição já traz o número da versão). */
    public function novaVersao(int $id, array $definicao): void
    {
        $json = self::json($definicao);
        DB::conexao()->prepare(
            'UPDATE agentes SET slug = :slug, nome = :nome, descricao = :descricao, definicao = :definicao, versao = :versao, atualizado_em = :agora WHERE id = :id'
        )->execute([
            'slug' => $definicao['slug'], 'nome' => $definicao['nome'], 'descricao' => $definicao['descricao'] ?? '',
            'definicao' => $json, 'versao' => (int) $definicao['versao'], 'agora' => agora(), 'id' => $id,
        ]);
        $this->registrarVersao($id, (int) $definicao['versao'], $json);
    }

    public function definirAtivo(int $id, bool $ativo): void
    {
        DB::conexao()->prepare('UPDATE agentes SET ativo = :a, atualizado_em = :q WHERE id = :id')
            ->execute(['a' => $ativo ? 1 : 0, 'q' => agora(), 'id' => $id]);
    }

    /** @return list<array{versao:int,definicao:array,criado_em:string}> da mais nova para a mais antiga */
    public function versoes(int $agenteId): array
    {
        $st = DB::conexao()->prepare('SELECT versao, definicao, criado_em FROM agentes_versoes WHERE agente_id = :id ORDER BY versao DESC');
        $st->execute(['id' => $agenteId]);
        return array_map(static fn (array $l): array => [
            'versao' => (int) $l['versao'], 'definicao' => (array) json_decode((string) $l['definicao'], true), 'criado_em' => (string) $l['criado_em'],
        ], $st->fetchAll());
    }

    public function versao(int $agenteId, int $versao): ?array
    {
        foreach ($this->versoes($agenteId) as $v) {
            if ($v['versao'] === $versao) {
                return $v['definicao'];
            }
        }
        return null;
    }

    public static function json(array $definicao): string
    {
        return json_encode($definicao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function registrarVersao(int $agenteId, int $versao, string $json): void
    {
        DB::conexao()->prepare('INSERT INTO agentes_versoes (agente_id, versao, definicao, criado_em) VALUES (:a, :v, :d, :q)')
            ->execute(['a' => $agenteId, 'v' => $versao, 'd' => $json, 'q' => agora()]);
    }

    /** Acrescenta `def` (definição decodificada) à linha. */
    private function decodificar(array $linha): array
    {
        $linha['def'] = (array) json_decode((string) $linha['definicao'], true);
        return $linha;
    }
}
