<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela chat_mensagens (só exibição/histórico do chat; não é entidade de negócio). */
final class ChatRepository
{
    public function inserir(string $papel, string $conteudo, ?array $payload = null, ?array $contexto = null): int
    {
        $json = static fn (?array $d): ?string => $d === null ? null : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::conexao()->prepare(
            'INSERT INTO chat_mensagens (papel, conteudo, payload, contexto, criado_em) VALUES (:papel, :conteudo, :payload, :contexto, :criado_em)'
        )->execute([
            'papel' => $papel, 'conteudo' => $conteudo, 'payload' => $json($payload), 'contexto' => $json($contexto), 'criado_em' => agora(),
        ]);
        return (int) DB::conexao()->lastInsertId();
    }

    /** @return array{id:int,papel:string,conteudo:string,payload:?array,contexto:?array,criado_em:string}|null */
    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM chat_mensagens WHERE id = :id');
        $st->execute(['id' => $id]);
        $linha = $st->fetch();
        return $linha ? $this->decodificar($linha) : null;
    }

    /** Últimas mensagens em ordem cronológica. */
    public function recentes(int $limite = 40): array
    {
        $limite = max(1, min(200, $limite));
        $linhas = DB::conexao()->query("SELECT * FROM chat_mensagens ORDER BY id DESC LIMIT {$limite}")->fetchAll();
        return array_map($this->decodificar(...), array_reverse($linhas));
    }

    public function atualizarPayload(int $id, array $payload): void
    {
        DB::conexao()->prepare('UPDATE chat_mensagens SET payload = :p WHERE id = :id')->execute([
            'p' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'id' => $id,
        ]);
    }

    /** Última referência (id + nome) registrada por uma resposta do sistema. */
    public function ultimaRef(): ?array
    {
        $json = DB::conexao()->query(
            "SELECT contexto FROM chat_mensagens WHERE papel = 'sistema' AND contexto IS NOT NULL ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $contexto = is_string($json) ? json_decode($json, true) : null;
        return is_array($contexto) && isset($contexto['ultima_ref']) && is_array($contexto['ultima_ref']) ? $contexto['ultima_ref'] : null;
    }

    private function decodificar(array $linha): array
    {
        foreach (['payload', 'contexto'] as $campo) {
            $linha[$campo] = $linha[$campo] !== null ? json_decode((string) $linha[$campo], true) : null;
        }
        $linha['id'] = (int) $linha['id'];
        return $linha;
    }
}
