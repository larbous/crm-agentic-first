<?php

declare(strict_types=1);

namespace App\Services\Canais;

use RuntimeException;

/** Cliente IMAP mínimo (só leitura): login, INBOX, busca por UID e download da mensagem. Sem a extensão imap. */
final class Imap
{
    private int $sequencia = 0;
    private int $uidNext = 1;

    public function __construct(private readonly Conexao $conexao)
    {
    }

    /** Lê a saudação, liga STARTTLS se pedido ('tls') e faz o login. */
    public function iniciar(string $usuario, string $senha, string $seguranca = 'ssl'): void
    {
        $saudacao = $this->conexao->linha();
        if ($saudacao === null || !str_starts_with($saudacao, '* OK')) {
            throw new RuntimeException('O servidor IMAP não respondeu como esperado.');
        }
        if ($seguranca === 'tls') {
            $this->exigir('STARTTLS', 'STARTTLS recusado pelo servidor.');
            if (!$this->conexao->ativarTls()) {
                throw new RuntimeException('Não foi possível ativar TLS na conexão IMAP.');
            }
        }
        $this->exigir('LOGIN ' . self::citar($usuario) . ' ' . self::citar($senha), 'Usuário ou senha do IMAP recusados.');
    }

    /** Seleciona a caixa e devolve o próximo UID previsto (UIDNEXT). */
    public function selecionar(string $caixa = 'INBOX'): int
    {
        $r = $this->exigir('SELECT ' . self::citar($caixa), 'Não foi possível abrir a caixa ' . $caixa . '.');
        foreach ($r['linhas'] as $linha) {
            if (preg_match('/\[UIDNEXT (\d+)\]/', $linha, $m) === 1) {
                $this->uidNext = (int) $m[1];
            }
        }
        return $this->uidNext;
    }

    /** Maior UID existente (0 se a caixa está vazia). Precisa de selecionar() antes. */
    public function maiorUid(): int
    {
        return max(0, $this->uidNext - 1);
    }

    /** @return list<int> UIDs maiores que $ultimo, em ordem crescente */
    public function uidsDesde(int $ultimo): array
    {
        $r = $this->exigir('UID SEARCH UID ' . ($ultimo + 1) . ':*', 'A busca no IMAP falhou.');
        $uids = [];
        foreach ($r['linhas'] as $linha) {
            if (str_starts_with($linha, '* SEARCH')) {
                foreach (preg_split('/\s+/', trim(substr($linha, 8))) ?: [] as $n) {
                    if ($n !== '' && ctype_digit($n) && (int) $n > $ultimo) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        sort($uids);
        return array_values(array_unique($uids));
    }

    /** Mensagem inteira, sem marcá-la como lida (BODY.PEEK). */
    public function baixar(int $uid): string
    {
        $r = $this->exigir("UID FETCH {$uid} BODY.PEEK[]", "Não foi possível baixar a mensagem {$uid}.");
        return $r['literais'][0] ?? '';
    }

    public function sair(): void
    {
        try {
            $this->comando('LOGOUT');
        } finally {
            $this->conexao->fechar();
        }
    }

    // ---- Interno --------------------------------------------------------------------------

    /** @return array{ok:bool,linhas:list<string>,literais:list<string>,resposta:string} */
    private function comando(string $comando): array
    {
        $tag = sprintf('A%03d', ++$this->sequencia);
        $this->conexao->escrever("{$tag} {$comando}\r\n");
        $linhas = $literais = [];
        while (true) {
            $linha = $this->conexao->linha();
            if ($linha === null) {
                throw new RuntimeException('A conexão IMAP foi encerrada durante o comando.');
            }
            if (str_starts_with($linha, $tag . ' ')) {
                return ['ok' => str_starts_with(substr($linha, strlen($tag) + 1), 'OK'), 'linhas' => $linhas, 'literais' => $literais, 'resposta' => $linha];
            }
            $linhas[] = $linha;
            if (preg_match('/\{(\d+)\}$/', $linha, $m) === 1) {
                $literais[] = $this->conexao->ler((int) $m[1]);
            }
        }
    }

    private function exigir(string $comando, string $mensagem): array
    {
        $r = $this->comando($comando);
        if (!$r['ok']) {
            throw new RuntimeException($mensagem);
        }
        return $r;
    }

    private static function citar(string $texto): string
    {
        return '"' . addcslashes($texto, "\\\"") . '"';
    }
}
