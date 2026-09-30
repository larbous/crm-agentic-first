<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Agregações somente-leitura dos indicadores do Início (não é uma entidade; nada é gravado aqui). */
final class DashboardRepository
{
    /**
     * Últimos $meses meses (o atual por último), no formato AAAA-MM.
     * @return list<string>
     */
    public static function ultimosMeses(int $meses): array
    {
        $lista = [];
        $base = new \DateTimeImmutable('first day of this month');
        for ($i = $meses - 1; $i >= 0; $i--) {
            $lista[] = $base->modify("-{$i} months")->format('Y-m');
        }
        return $lista;
    }

    /**
     * Negócios ganhos por mês de fechamento: quantidade e valor fechado (centavos).
     * @param list<string> $meses AAAA-MM
     * @return array<string,array{qtd:int,valor:int}>
     */
    public function ganhosPorMes(array $meses): array
    {
        $st = DB::conexao()->prepare(
            "SELECT substr(data_fechamento, 1, 7) AS mes, COUNT(*) AS qtd, COALESCE(SUM(COALESCE(valor_fechado, valor_estimado, 0)), 0) AS valor
             FROM negocios WHERE arquivado_em IS NULL AND status = 'ganho' AND data_fechamento >= :de
             GROUP BY mes"
        );
        $st->execute(['de' => $meses[0] . '-01']);
        $lidos = [];
        foreach ($st->fetchAll() as $l) {
            $lidos[(string) $l['mes']] = ['qtd' => (int) $l['qtd'], 'valor' => (int) $l['valor']];
        }
        $saida = [];
        foreach ($meses as $m) {
            $saida[$m] = $lidos[$m] ?? ['qtd' => 0, 'valor' => 0];
        }
        return $saida;
    }

    /**
     * Recebido (cobranças pagas) e pago (despesas pagas) por mês de pagamento, em centavos.
     * @param list<string> $meses AAAA-MM
     * @return array<string,array{recebido:int,pago:int}>
     */
    public function fluxoPorMes(array $meses): array
    {
        $de = $meses[0] . '-01';
        $saida = array_fill_keys($meses, ['recebido' => 0, 'pago' => 0]);
        foreach (['recebido' => 'cobrancas', 'pago' => 'despesas'] as $chave => $tabela) {
            $st = DB::conexao()->prepare(
                "SELECT substr(data_pagamento, 1, 7) AS mes, COALESCE(SUM(valor), 0) AS total FROM {$tabela}
                 WHERE arquivado_em IS NULL AND status = 'pago' AND data_pagamento >= :de GROUP BY mes"
            );
            $st->execute(['de' => $de]);
            foreach ($st->fetchAll() as $l) {
                if (isset($saida[$l['mes']])) {
                    $saida[$l['mes']][$chave] = (int) $l['total'];
                }
            }
        }
        return $saida;
    }

    /**
     * Cobranças em aberto em centavos: vencidas, vencem em até 7 dias e vencem depois; e o total pago no mês.
     * @return array{vencidas:int,semana:int,depois:int,pago_mes:int}
     */
    public function cobrancasResumo(string $hoje, string $limiteSemana, string $inicioMes, string $fimMes): array
    {
        $st = DB::conexao()->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN status IN ('pendente', 'vencido') AND vencimento < :hoje THEN valor END), 0) AS vencidas,
               COALESCE(SUM(CASE WHEN status = 'pendente' AND vencimento >= :hoje AND vencimento <= :semana THEN valor END), 0) AS semana,
               COALESCE(SUM(CASE WHEN status = 'pendente' AND vencimento > :semana THEN valor END), 0) AS depois,
               COALESCE(SUM(CASE WHEN status = 'pago' AND data_pagamento BETWEEN :de AND :ate THEN valor END), 0) AS pago_mes
             FROM cobrancas WHERE arquivado_em IS NULL"
        );
        $st->execute(['hoje' => $hoje, 'semana' => $limiteSemana, 'de' => $inicioMes, 'ate' => $fimMes . ' 23:59:59']);
        return array_map('intval', $st->fetch());
    }

    /**
     * Propostas por situação (todas as versões não arquivadas).
     * @return array<string,int>
     */
    public function propostasPorStatus(): array
    {
        $st = DB::conexao()->query('SELECT status, COUNT(*) AS qtd FROM propostas WHERE arquivado_em IS NULL GROUP BY status');
        return array_map('intval', array_column($st->fetchAll(), 'qtd', 'status'));
    }

    /**
     * Valor em propostas enviadas/visualizadas ainda sem resposta, em centavos, e quantas são.
     * @return array{qtd:int,valor:int}
     */
    public function propostasEmAberto(): array
    {
        $r = DB::conexao()->query(
            "SELECT COUNT(*) AS qtd, COALESCE(SUM(total), 0) AS valor FROM propostas
             WHERE arquivado_em IS NULL AND status IN ('enviada', 'visualizada')"
        )->fetch();
        return ['qtd' => (int) $r['qtd'], 'valor' => (int) $r['valor']];
    }

    /**
     * Contratos por situação (ativos, vencidos etc.) e a receita mensal recorrente dos ativos (centavos).
     * @return array{por_status:array<string,int>,mrr:int}
     */
    public function contratos(): array
    {
        $pdo = DB::conexao();
        $porStatus = array_map('intval', array_column(
            $pdo->query('SELECT status, COUNT(*) AS qtd FROM contratos WHERE arquivado_em IS NULL GROUP BY status')->fetchAll(),
            'qtd',
            'status'
        ));
        $mrr = (int) $pdo->query(
            "SELECT COALESCE(SUM(valor_mensal), 0) FROM contratos
             WHERE arquivado_em IS NULL AND status IN ('assinado', 'ativo') AND recorrencia = 'mensal'"
        )->fetchColumn();
        return ['por_status' => $porStatus, 'mrr' => $mrr];
    }

    /**
     * Respostas de NPS dos últimos 90 dias por categoria e a nota média.
     * @return array{promotor:int,neutro:int,detrator:int}
     */
    public function npsRecente(string $desde): array
    {
        $st = DB::conexao()->prepare(
            "SELECT categoria, COUNT(*) AS qtd FROM pesquisas
             WHERE arquivado_em IS NULL AND status = 'respondida' AND categoria IS NOT NULL AND respondida_em >= :d GROUP BY categoria"
        );
        $st->execute(['d' => $desde]);
        $lidos = array_map('intval', array_column($st->fetchAll(), 'qtd', 'categoria'));
        return ['promotor' => $lidos['promotor'] ?? 0, 'neutro' => $lidos['neutro'] ?? 0, 'detrator' => $lidos['detrator'] ?? 0];
    }

    /**
     * Execuções de IA por dia (concluídas × com erro) nos últimos dias, e as aguardando aprovação no total.
     * @param list<string> $dias AAAA-MM-DD
     * @return array<string,array{ok:int,erro:int}>
     */
    public function execucoesPorDia(array $dias): array
    {
        $st = DB::conexao()->prepare(
            "SELECT substr(COALESCE(iniciado_em, concluido_em), 1, 10) AS dia,
                    SUM(CASE WHEN status IN ('concluida', 'aguardando_aprovacao') THEN 1 ELSE 0 END) AS ok,
                    SUM(CASE WHEN status = 'erro' THEN 1 ELSE 0 END) AS erro
             FROM execucoes WHERE COALESCE(iniciado_em, concluido_em) >= :de GROUP BY dia"
        );
        $st->execute(['de' => $dias[0]]);
        $lidos = [];
        foreach ($st->fetchAll() as $l) {
            $lidos[(string) $l['dia']] = ['ok' => (int) $l['ok'], 'erro' => (int) $l['erro']];
        }
        $saida = [];
        foreach ($dias as $d) {
            $saida[$d] = $lidos[$d] ?? ['ok' => 0, 'erro' => 0];
        }
        return $saida;
    }

    /** Negócios abertos criados nos últimos 30 dias (leads novos) e total de conversas abertas com não lidas. */
    public function novosNegocios(string $desde): int
    {
        $st = DB::conexao()->prepare('SELECT COUNT(*) FROM negocios WHERE arquivado_em IS NULL AND criado_em >= :d');
        $st->execute(['d' => $desde]);
        return (int) $st->fetchColumn();
    }

    /**
     * Negócios perdidos e ganhos nos últimos 90 dias, para a taxa de conversão.
     * @return array{ganhos:int,perdidos:int}
     */
    public function conversao(string $desde): array
    {
        $st = DB::conexao()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN status = 'ganho' THEN 1 ELSE 0 END), 0) AS ganhos,
                    COALESCE(SUM(CASE WHEN status = 'perdido' THEN 1 ELSE 0 END), 0) AS perdidos
             FROM negocios WHERE arquivado_em IS NULL AND data_fechamento >= :d"
        );
        $st->execute(['d' => $desde]);
        $r = $st->fetch();
        return ['ganhos' => (int) $r['ganhos'], 'perdidos' => (int) $r['perdidos']];
    }
}
