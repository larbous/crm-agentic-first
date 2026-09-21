<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela mensagens (Fase 14): cada mensagem recebida ou enviada de uma conversa. */
final class MensagemRepository
{
    public function inserir(array $dados): int
    {
        $cols = array_keys($dados);
        DB::conexao()->prepare('INSERT INTO mensagens (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($dados);
        return (int) DB::conexao()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($dados)));
        DB::conexao()->prepare("UPDATE mensagens SET {$sets} WHERE id = :__id")->execute($dados + ['__id' => $id]);
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM mensagens WHERE id = :id');
        $st->execute(['id' => $id]);
        return $this->decodificar($st->fetch() ?: null);
    }

    public function porIdExterno(string $idExterno): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM mensagens WHERE id_externo = :i');
        $st->execute(['i' => $idExterno]);
        return $this->decodificar($st->fetch() ?: null);
    }

    /** @return list<array> mensagens da conversa em ordem cronológica (as $limite mais recentes) */
    public function daConversa(int $conversaId, int $limite = 300): array
    {
        $st = DB::conexao()->prepare('SELECT * FROM (SELECT * FROM mensagens WHERE conversa_id = :c ORDER BY data_hora DESC, id DESC LIMIT ' . (int) $limite . ') ORDER BY data_hora ASC, id ASC');
        $st->execute(['c' => $conversaId]);
        return array_map($this->decodificar(...), $st->fetchAll());
    }

    /** Mensagens que ainda não viraram atividade na timeline (a conversa ainda não tinha contato ou empresa). */
    public function semAtividade(int $conversaId): array
    {
        $st = DB::conexao()->prepare('SELECT * FROM mensagens WHERE conversa_id = :c AND atividade_id IS NULL AND status <> \'falhou\' ORDER BY data_hora ASC, id ASC');
        $st->execute(['c' => $conversaId]);
        return array_map($this->decodificar(...), $st->fetchAll());
    }

    /** Áudios recebidos à espera de transcrição (com o canal da conversa), dos mais antigos para os mais novos. @return list<array> */
    public function paraTranscrever(int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT m.*, c.canal FROM mensagens m JOIN conversas c ON c.id = m.conversa_id
             WHERE m.transcricao_status = 'pendente' ORDER BY m.id LIMIT " . max(1, $limite)
        );
        $st->execute();
        return array_map($this->decodificar(...), $st->fetchAll());
    }

    private function decodificar(?array $linha): ?array
    {
        if ($linha === null) {
            return null;
        }
        $linha['midia'] = $linha['midia'] !== null && $linha['midia'] !== '' ? (array) json_decode((string) $linha['midia'], true) : null;
        return $linha;
    }
}
