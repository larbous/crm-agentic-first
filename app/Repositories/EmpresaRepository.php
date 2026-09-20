<?php

declare(strict_types=1);

namespace App\Repositories;

final class EmpresaRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'empresas';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, o.nome AS origem_nome,
                    (SELECT COALESCE(SUM(n.valor_fechado), 0) FROM negocios n
                      WHERE n.empresa_id = a.id AND n.status = 'ganho' AND n.arquivado_em IS NULL) AS ltv,
                    (SELECT COALESCE(SUM(c.valor_mensal), 0) FROM contratos c
                      WHERE c.empresa_id = a.id AND c.status IN ('assinado', 'ativo') AND c.arquivado_em IS NULL) AS mrr
                FROM empresas a LEFT JOIN origens o ON o.id = a.origem_id";
    }

    protected function ordenaveis(): array
    {
        return [
            'nome_fantasia' => 'a.nome_fantasia COLLATE pt_br', 'status' => 'a.status', 'classificacao' => 'a.classificacao',
            'cidade' => 'a.cidade COLLATE pt_br', 'segmento' => 'a.segmento COLLATE pt_br', 'origem_nome' => 'o.nome COLLATE pt_br',
            'ltv' => 'ltv', 'mrr' => 'mrr', 'criado_em' => 'a.criado_em', 'telefone' => 'a.telefone', 'email_geral' => 'a.email_geral',
            'ticket_potencial' => 'a.ticket_potencial',
        ];
    }

    protected function filtraveis(): array
    {
        return ['status' => 'a.status', 'classificacao' => 'a.classificacao', 'origem_id' => 'a.origem_id'];
    }

    protected function buscaveis(): array
    {
        return ['a.nome_fantasia', 'a.razao_social', 'a.email_geral', 'a.telefone', 'a.whatsapp', 'a.cnpj', 'a.dominio', 'a.cidade', 'a.segmento'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.nome_fantasia COLLATE pt_br ASC';
    }

    public function porCnpj(string $cnpj, ?int $ignorarId = null): ?array
    {
        $st = $this->pdo()->prepare(
            'SELECT id, nome_fantasia FROM empresas WHERE cnpj = :c AND arquivado_em IS NULL AND id <> :i LIMIT 1'
        );
        $st->execute(['c' => $cnpj, 'i' => $ignorarId ?? 0]);
        return $st->fetch() ?: null;
    }

    /** Opções para selects: id => nome fantasia. */
    public function opcoes(): array
    {
        $st = $this->pdo()->query('SELECT id, nome_fantasia FROM empresas WHERE arquivado_em IS NULL ORDER BY nome_fantasia COLLATE pt_br');
        return array_column($st->fetchAll(), 'nome_fantasia', 'id');
    }
}
