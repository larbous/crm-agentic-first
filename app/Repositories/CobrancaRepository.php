<?php

declare(strict_types=1);

namespace App\Repositories;

/** Cobranças (Fase 17): ligadas ao Asaas. Só SQL; escritas passam pelo ActionExecutor. */
final class CobrancaRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'cobrancas';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, e.nome_fantasia AS empresa_nome, n.titulo AS negocio_titulo, c.numero AS contrato_numero
                FROM cobrancas a
                JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN negocios n ON n.id = a.negocio_id
                LEFT JOIN contratos c ON c.id = a.contrato_id";
    }

    protected function ordenaveis(): array
    {
        return [
            'empresa' => 'e.nome_fantasia COLLATE pt_br', 'descricao' => 'a.descricao COLLATE pt_br', 'tipo' => 'a.tipo',
            'valor' => 'a.valor', 'vencimento' => 'a.vencimento', 'status' => 'a.status', 'criado_em' => 'a.criado_em',
        ];
    }

    protected function filtraveis(): array
    {
        return ['empresa_id' => 'a.empresa_id', 'negocio_id' => 'a.negocio_id', 'tipo' => 'a.tipo', 'status' => 'a.status', 'forma_pagamento' => 'a.forma_pagamento'];
    }

    protected function buscaveis(): array
    {
        return ['a.descricao', 'e.nome_fantasia'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.vencimento DESC, a.id DESC';
    }

    public function porAsaasId(string $asaasId): ?array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.asaas_id = :id');
        $st->execute(['id' => $asaasId]);
        return $st->fetch() ?: null;
    }

    /** Pelo id do pagamento (payment) mais recente do ciclo — é o que o webhook de NF-e referencia (invoice.payment), não a assinatura. */
    public function porAsaasPaymentId(string $paymentId): ?array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.asaas_payment_id = :id');
        $st->execute(['id' => $paymentId]);
        return $st->fetch() ?: null;
    }

    /** Cobranças pendentes (sem confirmação do Asaas) já vencidas: fallback local caso o webhook não chegue. */
    public function pendentesVencidas(string $hoje, int $limite = 100): array
    {
        $st = $this->pdo()->prepare(
            "SELECT * FROM cobrancas WHERE arquivado_em IS NULL AND status = 'pendente' AND vencimento < :hoje LIMIT {$limite}"
        );
        $st->execute(['hoje' => $hoje]);
        return $st->fetchAll();
    }

    public function daEmpresa(int $empresaId, int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        $st = $this->pdo()->prepare($this->selectBase() . " WHERE a.empresa_id = :id AND a.arquivado_em IS NULL ORDER BY a.vencimento DESC, a.id DESC LIMIT {$limite}");
        $st->execute(['id' => $empresaId]);
        return $st->fetchAll();
    }

    /** Soma de cobranças pagas por empresa, com data_pagamento no período [de, ate] (ISO, inclusive). Para o DRE. @return array<int,int> empresa_id => centavos */
    public function pagoPorEmpresaNoPeriodo(string $de, string $ate): array
    {
        $st = $this->pdo()->prepare(
            "SELECT empresa_id, COALESCE(SUM(valor), 0) AS total FROM cobrancas
             WHERE arquivado_em IS NULL AND status = 'pago' AND data_pagamento BETWEEN :de AND :ate
             GROUP BY empresa_id"
        );
        $st->execute(['de' => $de, 'ate' => $ate]);
        return array_map('intval', array_column($st->fetchAll(), 'total', 'empresa_id'));
    }
}
