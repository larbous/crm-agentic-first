<?php

declare(strict_types=1);

namespace App\Repositories;

final class ContratoRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'contratos';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, e.nome_fantasia AS empresa_nome, t.nome AS tipo_nome, n.titulo AS negocio_titulo,
                    TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome,
                    p.numero AS proposta_numero, o.numero AS origem_numero
                FROM contratos a
                LEFT JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN contrato_tipos t ON t.id = a.tipo_id
                LEFT JOIN negocios n ON n.id = a.negocio_id
                LEFT JOIN contatos c ON c.id = a.contato_id
                LEFT JOIN propostas p ON p.id = a.proposta_id
                LEFT JOIN contratos o ON o.id = a.contrato_origem_id";
    }

    protected function ordenaveis(): array
    {
        return [
            'numero' => 'a.numero', 'titulo' => 'a.titulo COLLATE pt_br', 'empresa_nome' => 'e.nome_fantasia COLLATE pt_br',
            'tipo_nome' => 't.nome COLLATE pt_br', 'status' => 'a.status', 'valor_total' => 'a.valor_total', 'valor_mensal' => 'a.valor_mensal',
            'data_inicio' => 'a.data_inicio', 'data_fim' => 'a.data_fim', 'criado_em' => 'a.criado_em',
        ];
    }

    protected function filtraveis(): array
    {
        return ['status' => 'a.status', 'tipo_id' => 'a.tipo_id', 'empresa_id' => 'a.empresa_id', 'negocio_id' => 'a.negocio_id'];
    }

    /** Filtro "vencimento": vencendo (ativos que terminam em até 60 dias) ou vencidos (data_fim passada, ainda vigentes). */
    protected function filtroEspecial(string $chave, mixed $valor, array &$params): ?string
    {
        if ($chave !== 'vencimento') {
            return null;
        }
        $condicao = match ($valor) {
            'vencendo' => "a.status IN ('assinado', 'ativo') AND a.data_fim IS NOT NULL AND a.data_fim >= :venc_hoje AND a.data_fim <= date(:venc_hoje, '+60 days')",
            'vencidos' => "a.status IN ('assinado', 'ativo', 'vencido') AND a.data_fim IS NOT NULL AND a.data_fim < :venc_hoje",
            default    => null,
        };
        if ($condicao !== null) {
            $params['venc_hoje'] = hoje();
        }
        return $condicao;
    }

    protected function buscaveis(): array
    {
        return ['a.numero', 'a.titulo', 'e.nome_fantasia', 'n.titulo'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.criado_em DESC, a.id DESC';
    }

    /**
     * Contratos assinados/ativos que terminam nos próximos $dias dias (os que acabam antes primeiro).
     * @return array{total:int,linhas:list<array>}
     */
    public function vencendo(int $dias = 30, int $limite = 8): array
    {
        $onde = "a.arquivado_em IS NULL AND a.status IN ('assinado', 'ativo') AND a.data_fim IS NOT NULL
                 AND a.data_fim >= :hoje AND a.data_fim <= :limite";
        $params = ['hoje' => hoje(), 'limite' => date('Y-m-d', strtotime("+{$dias} days"))];
        $st = $this->pdo()->prepare("SELECT COUNT(*) FROM contratos a WHERE {$onde}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $limite = max(1, min(50, $limite));
        $st = $this->pdo()->prepare($this->selectBase() . " WHERE {$onde} ORDER BY a.data_fim ASC, a.id ASC LIMIT {$limite}");
        $st->execute($params);
        return ['total' => $total, 'linhas' => $st->fetchAll()];
    }

    public function proximoNumero(int $ano): string
    {
        $prefixo = sprintf('CT-%04d-', $ano);
        $st = $this->pdo()->prepare('SELECT COALESCE(MAX(CAST(SUBSTR(numero, 9) AS INTEGER)), 0) + 1 FROM contratos WHERE numero LIKE :p');
        $st->execute(['p' => $prefixo . '%']);
        return $prefixo . str_pad((string) $st->fetchColumn(), 4, '0', STR_PAD_LEFT);
    }

    public function porToken(string $token): ?array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.token_publico = :t AND a.arquivado_em IS NULL');
        $st->execute(['t' => $token]);
        return $st->fetch() ?: null;
    }

    /** $campo: empresa_id | negocio_id | proposta_id */
    public function por(string $campo, int $id): array
    {
        if (!in_array($campo, ['empresa_id', 'negocio_id', 'proposta_id'], true)) {
            throw new \InvalidArgumentException("Campo inválido: {$campo}");
        }
        $st = $this->pdo()->prepare($this->selectBase() . " WHERE a.{$campo} = :id AND a.arquivado_em IS NULL ORDER BY a.criado_em DESC");
        $st->execute(['id' => $id]);
        return $st->fetchAll();
    }
}
