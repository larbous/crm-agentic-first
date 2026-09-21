<?php

declare(strict_types=1);

namespace App\Services\Canais;

/** Canal de bytes de um servidor de e-mail (IMAP/SMTP). Trocado por um falso nos testes. */
interface Conexao
{
    /** Próxima linha sem o CRLF; null se a conexão acabou ou estourou o tempo. */
    public function linha(): ?string;

    /** Exatamente $n bytes (literais do IMAP). */
    public function ler(int $n): string;

    public function escrever(string $dados): void;

    /** Liga TLS numa conexão aberta em texto puro (STARTTLS). */
    public function ativarTls(): bool;

    public function fechar(): void;
}
