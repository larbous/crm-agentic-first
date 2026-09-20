<?php

declare(strict_types=1);

namespace App\Repositories;

final class ContatoRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'contatos';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, TRIM(a.nome || ' ' || COALESCE(a.sobrenome, '')) AS nome_completo,
                    e.nome_fantasia AS empresa_nome, o.nome AS origem_nome
                FROM contatos a
                LEFT JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN origens o ON o.id = a.origem_id";
    }

    protected function ordenaveis(): array
    {
        return [
            'nome' => 'a.nome COLLATE pt_br', 'empresa_nome' => 'e.nome_fantasia COLLATE pt_br', 'cargo' => 'a.cargo COLLATE pt_br',
            'email' => 'a.email', 'status' => 'a.status', 'ultimo_contato_em' => 'a.ultimo_contato_em',
            'proximo_contato_em' => 'a.proximo_contato_em', 'origem_nome' => 'o.nome COLLATE pt_br', 'criado_em' => 'a.criado_em',
        ];
    }

    protected function filtraveis(): array
    {
        return ['status' => 'a.status', 'empresa_id' => 'a.empresa_id', 'origem_id' => 'a.origem_id', 'papel_decisao' => 'a.papel_decisao'];
    }

    protected function buscaveis(): array
    {
        return ["a.nome || ' ' || COALESCE(a.sobrenome, '')", 'a.apelido', 'a.email', 'a.telefone', 'a.whatsapp', 'a.cargo', 'e.nome_fantasia'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.nome COLLATE pt_br ASC';
    }

    public function porEmpresa(int $empresaId): array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.empresa_id = :e AND a.arquivado_em IS NULL ORDER BY a.nome COLLATE pt_br');
        $st->execute(['e' => $empresaId]);
        return $st->fetchAll();
    }

    /** Opções para selects: id => "Nome — Empresa". */
    public function opcoes(): array
    {
        $out = [];
        foreach ($this->todas('nome') as $c) {
            $out[$c['id']] = $c['nome_completo'] . ($c['empresa_nome'] ? ' — ' . $c['empresa_nome'] : '');
        }
        return $out;
    }

    /** Contato ativo com este e-mail (principal ou secundário); o mais antigo. */
    public function porEmail(string $email): ?array
    {
        $st = $this->pdo()->prepare(
            $this->selectBase() . ' WHERE a.arquivado_em IS NULL AND (a.email = :e OR a.email_secundario = :e) ORDER BY a.id LIMIT 1'
        );
        $st->execute(['e' => mb_strtolower(trim($email))]);
        return $st->fetch() ?: null;
    }

    /** Contato ativo cujo WhatsApp ou telefone tem estes dígitos (com ou sem o DDI 55). */
    public function porTelefone(string $digitos): ?array
    {
        $digitos = preg_replace('/^55(?=\d{10,11}$)/', '', $digitos) ?? $digitos;
        if (strlen($digitos) < 8) {
            return null;
        }
        $st = $this->pdo()->prepare(
            $this->selectBase() . ' WHERE a.arquivado_em IS NULL AND (so_digitos(a.whatsapp) IN (:d, :d55) OR so_digitos(a.telefone) IN (:d, :d55)) ORDER BY a.id LIMIT 1'
        );
        $st->execute(['d' => $digitos, 'd55' => '55' . $digitos]);
        return $st->fetch() ?: null;
    }

    public function atualizarUltimoContato(int $id, string $quando): void
    {
        $st = $this->pdo()->prepare(
            'UPDATE contatos SET ultimo_contato_em = :q WHERE id = :id AND (ultimo_contato_em IS NULL OR ultimo_contato_em < :q)'
        );
        $st->execute(['q' => $quando, 'id' => $id]);
    }
}
