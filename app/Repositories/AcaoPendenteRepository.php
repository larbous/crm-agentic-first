<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela acoes_pendentes: ações de agentes aguardando aprovação do operador. */
final class AcaoPendenteRepository
{
    public function inserir(int $execucaoId, array $acao, string $resumo): int
    {
        DB::conexao()->prepare(
            'INSERT INTO acoes_pendentes (execucao_id, acao, resumo, status, criado_em) VALUES (:e, :a, :r, \'pendente\', :q)'
        )->execute([
            'e' => $execucaoId, 'a' => json_encode($acao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'r' => mb_substr($resumo, 0, 500), 'q' => agora(),
        ]);
        return (int) DB::conexao()->lastInsertId();
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare($this->select() . ' WHERE p.id = :id');
        $st->execute(['id' => $id]);
        $l = $st->fetch();
        return $l ? $this->decodificar($l) : null;
    }

    /** @return list<array> pendentes (mais antigas primeiro), com agente e alvo da execução */
    public function pendentes(): array
    {
        return array_map($this->decodificar(...), DB::conexao()->query(
            $this->select() . " WHERE p.status = 'pendente' ORDER BY p.execucao_id ASC, p.id ASC"
        )->fetchAll());
    }

    public function contarPendentes(): int
    {
        return (int) DB::conexao()->query("SELECT COUNT(*) FROM acoes_pendentes WHERE status = 'pendente'")->fetchColumn();
    }

    /** @return list<array> últimas decididas (histórico curto na própria tela) */
    public function decididas(int $limite = 15): array
    {
        $limite = max(1, min(100, $limite));
        return array_map($this->decodificar(...), DB::conexao()->query(
            $this->select() . " WHERE p.status <> 'pendente' ORDER BY p.decidido_em DESC, p.id DESC LIMIT {$limite}"
        )->fetchAll());
    }

    /** @return list<array> ações geradas por uma execução (qualquer status) */
    public function daExecucao(int $execucaoId): array
    {
        $st = DB::conexao()->prepare($this->select() . ' WHERE p.execucao_id = :e ORDER BY p.id');
        $st->execute(['e' => $execucaoId]);
        return array_map($this->decodificar(...), $st->fetchAll());
    }

    public function decidir(int $id, string $status): void
    {
        DB::conexao()->prepare('UPDATE acoes_pendentes SET status = :s, decidido_em = :q, erro = NULL WHERE id = :id')
            ->execute(['s' => $status, 'q' => agora(), 'id' => $id]);
    }

    public function registrarErro(int $id, string $erro): void
    {
        DB::conexao()->prepare('UPDATE acoes_pendentes SET erro = :e WHERE id = :id')->execute(['e' => mb_substr($erro, 0, 500), 'id' => $id]);
    }

    private function select(): string
    {
        return 'SELECT p.*, x.agente_id, x.entidade AS alvo_entidade, x.registro_id AS alvo_id, a.nome AS agente_nome, a.slug AS agente_slug
                FROM acoes_pendentes p JOIN execucoes x ON x.id = p.execucao_id LEFT JOIN agentes a ON a.id = x.agente_id';
    }

    private function decodificar(array $linha): array
    {
        $linha['def'] = (array) json_decode((string) $linha['acao'], true);
        return $linha;
    }
}
