<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Consultas de leitura que alimentam o contexto dos agentes (ContextBuilder) e a resolução do registro-alvo. */
final class ContextoAgenteRepository
{
    /** Proposta do negócio: a aceita mais recente ou, se não houver, a mais recente. Só a última versão de cada número. */
    public function propostaDoNegocio(int $negocioId): ?array
    {
        $st = DB::conexao()->prepare(
            "SELECT * FROM propostas WHERE negocio_id = :id AND arquivado_em IS NULL
             AND versao = (SELECT MAX(p2.versao) FROM propostas p2 WHERE p2.numero = propostas.numero AND p2.arquivado_em IS NULL)
             ORDER BY (status = 'aceita') DESC, id DESC LIMIT 1"
        );
        $st->execute(['id' => $negocioId]);
        return $st->fetch() ?: null;
    }

    public function contratoDoNegocio(int $negocioId): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM contratos WHERE negocio_id = :id AND arquivado_em IS NULL ORDER BY id DESC LIMIT 1');
        $st->execute(['id' => $negocioId]);
        return $st->fetch() ?: null;
    }

    /** Negócios abertos parados na mesma etapa há mais de $dias dias. */
    public function negociosParados(string $limiteIso, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT n.codigo, n.titulo, n.valor_estimado, n.entrou_etapa_em, e.nome AS etapa, em.nome_fantasia AS empresa
             FROM negocios n LEFT JOIN etapas e ON e.id = n.etapa_id LEFT JOIN empresas em ON em.id = n.empresa_id
             WHERE n.arquivado_em IS NULL AND n.status = 'aberto' AND n.entrou_etapa_em IS NOT NULL AND n.entrou_etapa_em < :lim
             ORDER BY n.entrou_etapa_em ASC LIMIT " . max(1, min(50, $limite))
        );
        $st->execute(['lim' => $limiteIso]);
        return $st->fetchAll();
    }

    /** Negócios abertos com previsão de fechamento anterior a hoje. */
    public function previsoesVencidas(string $hoje, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT n.codigo, n.titulo, n.valor_estimado, n.previsao_fechamento, e.nome AS etapa, em.nome_fantasia AS empresa
             FROM negocios n LEFT JOIN etapas e ON e.id = n.etapa_id LEFT JOIN empresas em ON em.id = n.empresa_id
             WHERE n.arquivado_em IS NULL AND n.status = 'aberto' AND n.previsao_fechamento IS NOT NULL AND n.previsao_fechamento < :hoje
             ORDER BY n.previsao_fechamento ASC LIMIT " . max(1, min(50, $limite))
        );
        $st->execute(['hoje' => $hoje]);
        return $st->fetchAll();
    }

    /** Tarefas abertas com vencimento anterior a hoje. */
    public function tarefasAtrasadas(string $hoje, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT t.titulo, t.vencimento, t.prioridade, n.codigo AS negocio, e.nome_fantasia AS empresa
             FROM tarefas t LEFT JOIN negocios n ON n.id = t.negocio_id LEFT JOIN empresas e ON e.id = t.empresa_id
             WHERE t.arquivado_em IS NULL AND t.status IN ('pendente', 'andamento') AND t.vencimento IS NOT NULL AND substr(t.vencimento, 1, 10) < :hoje
             ORDER BY t.vencimento ASC LIMIT " . max(1, min(50, $limite))
        );
        $st->execute(['hoje' => $hoje]);
        return $st->fetchAll();
    }

    /** Modelos ativos de um tipo (ou de qualquer tipo de texto) mais recentes primeiro. */
    public function modelos(?string $tipo, int $limite): array
    {
        $onde = $tipo !== null ? 'AND tipo = :t' : '';
        $st = DB::conexao()->prepare(
            "SELECT nome, tipo, assunto, conteudo FROM modelos_documento WHERE ativo = 1 AND arquivado_em IS NULL {$onde}
             ORDER BY atualizado_em DESC, id DESC LIMIT " . max(1, min(10, $limite))
        );
        $st->execute($tipo !== null ? ['t' => $tipo] : []);
        return $st->fetchAll();
    }

    /** Negócio pelo código exato (ex.: NEG-2026-0003), para tarefas de agentes sem registro-alvo. */
    public function negocioPorCodigo(string $codigo): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM negocios WHERE codigo = :c AND arquivado_em IS NULL');
        $st->execute(['c' => $codigo]);
        return $st->fetch() ?: null;
    }
}
