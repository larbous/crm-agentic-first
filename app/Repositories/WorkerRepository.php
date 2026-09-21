<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/**
 * Consultas do worker (SPEC §11): o que está vencido, expirado ou para recorrer, e as marcas de avisos já emitidos.
 * Só leitura de negócio; toda escrita nas entidades passa pelo ActionExecutor.
 */
final class WorkerRepository
{
    /**
     * Registra a marca e diz se ela era nova. Falso = o aviso já foi emitido antes (não repetir).
     */
    public function marcar(string $chave): bool
    {
        $st = DB::conexao()->prepare('INSERT OR IGNORE INTO worker_marcas (chave, criado_em) VALUES (:c, :q)');
        $st->execute(['c' => $chave, 'q' => agora()]);
        return $st->rowCount() === 1;
    }

    /** A marca já foi registrada? (consulta sem registrar) */
    public function marcada(string $chave): bool
    {
        $st = DB::conexao()->prepare('SELECT 1 FROM worker_marcas WHERE chave = :c');
        $st->execute(['c' => $chave]);
        return $st->fetchColumn() !== false;
    }

    /** Tarefas concluídas com recorrência ainda sem a próxima ocorrência gerada. */
    public function tarefasRecorrentesConcluidas(int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT t.* FROM tarefas t
             WHERE t.arquivado_em IS NULL AND t.status = 'concluida' AND t.recorrencia <> 'nenhuma'
               AND NOT EXISTS (SELECT 1 FROM worker_marcas m WHERE m.chave = 'tarefa.proxima:' || t.id)
             ORDER BY t.id LIMIT " . max(1, $limite)
        );
        $st->execute();
        return $st->fetchAll();
    }

    /**
     * Tarefas abertas vencidas: vencimento só com data vence no dia seguinte; com hora, ao passar da hora.
     * @param string $hoje AAAA-MM-DD
     * @param string $agora AAAA-MM-DD HH:MM:SS
     */
    public function tarefasVencidas(string $hoje, string $agora, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT t.* FROM tarefas t
             WHERE t.arquivado_em IS NULL AND t.status IN ('pendente', 'andamento') AND t.vencimento IS NOT NULL
               AND ((length(t.vencimento) = 10 AND t.vencimento < :hoje) OR (length(t.vencimento) > 10 AND t.vencimento < :agora))
             ORDER BY t.vencimento, t.id LIMIT " . max(1, $limite)
        );
        $st->execute(['hoje' => $hoje, 'agora' => $agora]);
        return $st->fetchAll();
    }

    /** Propostas enviadas ou visualizadas cuja validade já passou. */
    public function propostasExpiradas(string $hoje, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT * FROM propostas WHERE arquivado_em IS NULL AND status IN ('enviada', 'visualizada')
               AND validade IS NOT NULL AND validade < :hoje ORDER BY validade, id LIMIT " . max(1, $limite)
        );
        $st->execute(['hoje' => $hoje]);
        return $st->fetchAll();
    }

    /** Contratos vigentes que vencem dentro do aviso de renovação de cada um (data_fim de hoje em diante). */
    public function contratosVencendo(string $hoje, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT * FROM contratos WHERE arquivado_em IS NULL AND status IN ('assinado', 'ativo') AND data_fim IS NOT NULL
               AND data_fim >= :hoje AND data_fim <= date(:hoje, '+' || aviso_renovacao_dias || ' days')
             ORDER BY data_fim, id LIMIT " . max(1, $limite)
        );
        $st->execute(['hoje' => $hoje]);
        return $st->fetchAll();
    }

    /** Contratos vigentes cuja data de fim já passou. */
    public function contratosVencidos(string $hoje, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT * FROM contratos WHERE arquivado_em IS NULL AND status IN ('assinado', 'ativo') AND data_fim IS NOT NULL
               AND data_fim < :hoje ORDER BY data_fim, id LIMIT " . max(1, $limite)
        );
        $st->execute(['hoje' => $hoje]);
        return $st->fetchAll();
    }

    /** Negócio aberto mais recente da empresa (alvo de agentes de negócio em squads que partem de uma empresa). */
    public function negocioAbertoDaEmpresa(int $empresaId): ?int
    {
        $st = DB::conexao()->prepare(
            "SELECT id FROM negocios WHERE empresa_id = :e AND arquivado_em IS NULL AND status = 'aberto' ORDER BY id DESC LIMIT 1"
        );
        $st->execute(['e' => $empresaId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Negócios abertos sem interação real (ligação, WhatsApp, e-mail, Instagram, reunião, visita, proposta) desde `$corte`, com a data
     * e a direção da última. A interação vale se for do negócio, da empresa ou do contato principal; sem nenhuma, conta a criação do negócio.
     * Notas e atividades do sistema não contam (o rascunho do follow-up é uma nota e não pode zerar o silêncio).
     * @param string $corte AAAA-MM-DD HH:MM:SS
     * @return list<array{id:int|string,titulo:string,ultima:string,ultima_direcao:?string}>
     */
    public function negociosSemInteracao(string $corte, int $limite): array
    {
        $casa = "a.arquivado_em IS NULL AND a.tipo IN ('ligacao', 'whatsapp', 'email', 'instagram', 'reuniao', 'visita', 'proposta')
                 AND (a.negocio_id = n.id OR (n.empresa_id IS NOT NULL AND a.empresa_id = n.empresa_id)
                      OR (n.contato_principal_id IS NOT NULL AND a.contato_id = n.contato_principal_id))";
        $st = DB::conexao()->prepare(
            "SELECT * FROM (
                SELECT n.id, n.titulo,
                       COALESCE((SELECT MAX(a.data_hora) FROM atividades a WHERE {$casa}), n.criado_em) AS ultima,
                       (SELECT a.direcao FROM atividades a WHERE {$casa} ORDER BY a.data_hora DESC, a.id DESC LIMIT 1) AS ultima_direcao
                FROM negocios n WHERE n.arquivado_em IS NULL AND n.status = 'aberto'
             ) WHERE ultima <= :corte ORDER BY ultima, id LIMIT " . max(1, $limite)
        );
        $st->execute(['corte' => $corte]);
        return $st->fetchAll();
    }
}
