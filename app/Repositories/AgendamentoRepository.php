<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela agendamentos_execucao: gatilhos "agendado" de agentes e squads com o próximo disparo já calculado. */
final class AgendamentoRepository
{
    /** @return list<array> todos, com tipo (agente|squad) e o id do dono */
    public function todos(): array
    {
        return array_map(static function (array $l): array {
            $l['tipo'] = $l['agente_id'] !== null ? 'agente' : 'squad';
            $l['dono_id'] = (int) ($l['agente_id'] ?? $l['squad_id']);
            return $l;
        }, DB::conexao()->query('SELECT * FROM agendamentos_execucao ORDER BY id')->fetchAll());
    }

    public function salvar(string $tipo, int $donoId, string $cron, string $proximo): void
    {
        $coluna = $tipo === 'agente' ? 'agente_id' : 'squad_id';
        $st = DB::conexao()->prepare("SELECT id FROM agendamentos_execucao WHERE {$coluna} = :d");
        $st->execute(['d' => $donoId]);
        $id = $st->fetchColumn();
        if ($id === false) {
            DB::conexao()->prepare("INSERT INTO agendamentos_execucao ({$coluna}, cron, proximo_run_em) VALUES (:d, :c, :p)")
                ->execute(['d' => $donoId, 'c' => $cron, 'p' => $proximo]);
            return;
        }
        DB::conexao()->prepare('UPDATE agendamentos_execucao SET cron = :c, proximo_run_em = :p WHERE id = :id')
            ->execute(['c' => $cron, 'p' => $proximo, 'id' => (int) $id]);
    }

    public function remover(int $id): void
    {
        DB::conexao()->prepare('DELETE FROM agendamentos_execucao WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return list<array> agendamentos cujo próximo disparo já chegou */
    public function vencidos(string $agora): array
    {
        $st = DB::conexao()->prepare('SELECT * FROM agendamentos_execucao WHERE proximo_run_em <= :a ORDER BY proximo_run_em, id');
        $st->execute(['a' => $agora]);
        return $st->fetchAll();
    }

    public function registrarDisparo(int $id, string $ultimo, string $proximo): void
    {
        DB::conexao()->prepare('UPDATE agendamentos_execucao SET ultimo_run_em = :u, proximo_run_em = :p WHERE id = :id')
            ->execute(['u' => $ultimo, 'p' => $proximo, 'id' => $id]);
    }

    /** Próximo disparo (ou null) de um agente ou squad, para as telas. */
    public function proximoDe(string $tipo, int $donoId): ?array
    {
        $coluna = $tipo === 'agente' ? 'agente_id' : 'squad_id';
        $st = DB::conexao()->prepare("SELECT * FROM agendamentos_execucao WHERE {$coluna} = :d");
        $st->execute(['d' => $donoId]);
        return $st->fetch() ?: null;
    }
}
