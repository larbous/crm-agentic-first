<?php

declare(strict_types=1);

namespace App\Repositories;

final class PropostaRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'propostas';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, e.nome_fantasia AS empresa_nome, n.titulo AS negocio_titulo, n.codigo AS negocio_codigo,
                    TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome, m.nome AS modelo_nome
                FROM propostas a
                LEFT JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN negocios n ON n.id = a.negocio_id
                LEFT JOIN contatos c ON c.id = a.contato_id
                LEFT JOIN modelos_documento m ON m.id = a.modelo_id";
    }

    /** A lista mostra só a versão mais recente de cada proposta. */
    protected function condicoesBase(): array
    {
        return ['a.versao = (SELECT MAX(p2.versao) FROM propostas p2 WHERE p2.numero = a.numero AND p2.arquivado_em IS NULL)'];
    }

    protected function ordenaveis(): array
    {
        return [
            'numero' => 'a.numero', 'titulo' => 'a.titulo COLLATE pt_br', 'empresa_nome' => 'e.nome_fantasia COLLATE pt_br',
            'status' => 'a.status', 'total' => 'a.total', 'validade' => 'a.validade', 'enviada_em' => 'a.enviada_em', 'criado_em' => 'a.criado_em',
        ];
    }

    protected function filtraveis(): array
    {
        return ['status' => 'a.status', 'empresa_id' => 'a.empresa_id', 'negocio_id' => 'a.negocio_id'];
    }

    protected function buscaveis(): array
    {
        return ['a.numero', 'a.titulo', 'e.nome_fantasia', 'n.titulo'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.criado_em DESC, a.id DESC';
    }

    public function proximoNumero(int $ano): string
    {
        $prefixo = sprintf('PROP-%04d-', $ano);
        $st = $this->pdo()->prepare('SELECT COALESCE(MAX(CAST(SUBSTR(numero, 11) AS INTEGER)), 0) + 1 FROM propostas WHERE numero LIKE :p');
        $st->execute(['p' => $prefixo . '%']);
        return $prefixo . str_pad((string) $st->fetchColumn(), 4, '0', STR_PAD_LEFT);
    }

    public function porToken(string $token): ?array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.token_publico = :t AND a.arquivado_em IS NULL');
        $st->execute(['t' => $token]);
        return $st->fetch() ?: null;
    }

    /** Todas as versões de uma proposta (número), da mais nova para a mais antiga. */
    public function versoes(string $numero): array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.numero = :n AND a.arquivado_em IS NULL ORDER BY a.versao DESC');
        $st->execute(['n' => $numero]);
        return $st->fetchAll();
    }

    public function maiorVersao(string $numero): int
    {
        $st = $this->pdo()->prepare('SELECT COALESCE(MAX(versao), 0) FROM propostas WHERE numero = :n');
        $st->execute(['n' => $numero]);
        return (int) $st->fetchColumn();
    }

    /** Últimas versões das propostas de um negócio ou empresa ($campo: negocio_id | empresa_id). */
    public function ultimasPor(string $campo, int $id): array
    {
        if (!in_array($campo, ['negocio_id', 'empresa_id'], true)) {
            throw new \InvalidArgumentException("Campo inválido: {$campo}");
        }
        $st = $this->pdo()->prepare(
            $this->selectBase() . " WHERE a.{$campo} = :id AND a.arquivado_em IS NULL
             AND a.versao = (SELECT MAX(p2.versao) FROM propostas p2 WHERE p2.numero = a.numero AND p2.arquivado_em IS NULL)
             ORDER BY a.criado_em DESC"
        );
        $st->execute(['id' => $id]);
        return $st->fetchAll();
    }

    /** Propostas aceitas (para criar contrato): id => "PROP-… — título". */
    public function opcoesAceitas(): array
    {
        $out = [];
        foreach ($this->pdo()->query("SELECT id, numero, versao, titulo FROM propostas WHERE status = 'aceita' AND arquivado_em IS NULL ORDER BY criado_em DESC")->fetchAll() as $p) {
            $out[$p['id']] = "{$p['numero']} v{$p['versao']} — {$p['titulo']}";
        }
        return $out;
    }
}
