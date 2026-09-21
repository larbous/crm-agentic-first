<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Controle das migrações aplicadas (tabela migracoes) e execução do SQL das migrações. */
final class MigracaoRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function garantirTabela(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migracoes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL UNIQUE,
                aplicada_em TEXT NOT NULL
            )'
        );
    }

    /** @return list<string> */
    public function aplicadas(): array
    {
        return $this->pdo->query('SELECT nome FROM migracoes ORDER BY nome')->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Executa o SQL da migração e a registra, tudo em uma transação.
     * Uma migração que começa com a linha `-- @fk-off` recria tabelas (o SQLite não altera CHECK): o runner desliga
     * foreign_keys antes (o PRAGMA não vale dentro de transação), confere `foreign_key_check` antes do commit e religa.
     */
    public function aplicar(string $nome, string $sql): void
    {
        $semFk = str_starts_with(ltrim($sql), '-- @fk-off');
        if ($semFk) {
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($sql);
            if ($semFk && ($violacoes = $this->pdo->query('PRAGMA foreign_key_check')->fetchAll()) !== []) {
                throw new \RuntimeException('A migração deixou ' . count($violacoes) . ' violação(ões) de chave estrangeira; nada foi aplicado.');
            }
            $st = $this->pdo->prepare('INSERT INTO migracoes (nome, aplicada_em) VALUES (:nome, :quando)');
            $st->execute(['nome' => $nome, 'quando' => agora()]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            if ($semFk) {
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }
}
