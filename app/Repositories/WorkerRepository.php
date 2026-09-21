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
     * Clientes ativos sem interação real (ligação, WhatsApp, e-mail, Instagram, reunião, visita, proposta) desde `$corte`, com a data e a
     * direção da última. Conta a interação da empresa, de qualquer contato dela ou de qualquer negócio dela; sem nenhuma, vale a criação
     * da empresa. Notas e atividades do sistema não contam (o alerta e o rascunho de follow-up não podem zerar o silêncio).
     * @param string $corte AAAA-MM-DD HH:MM:SS
     * @return list<array{id:int|string,nome_fantasia:string,ultima:string,ultima_direcao:?string}>
     */
    public function clientesSemInteracao(string $corte, int $limite): array
    {
        $casa = "a.arquivado_em IS NULL AND a.tipo IN ('ligacao', 'whatsapp', 'email', 'instagram', 'reuniao', 'visita', 'proposta')
                 AND (a.empresa_id = e.id
                      OR a.contato_id IN (SELECT c.id FROM contatos c WHERE c.empresa_id = e.id)
                      OR a.negocio_id IN (SELECT n.id FROM negocios n WHERE n.empresa_id = e.id))";
        $st = DB::conexao()->prepare(
            "SELECT * FROM (
                SELECT e.id, e.nome_fantasia,
                       COALESCE((SELECT MAX(a.data_hora) FROM atividades a WHERE {$casa}), e.criado_em) AS ultima,
                       (SELECT a.direcao FROM atividades a WHERE {$casa} ORDER BY a.data_hora DESC, a.id DESC LIMIT 1) AS ultima_direcao
                FROM empresas e WHERE e.arquivado_em IS NULL AND e.status = 'cliente'
             ) WHERE ultima <= :corte ORDER BY ultima, id LIMIT " . max(1, $limite)
        );
        $st->execute(['corte' => $corte]);
        return $st->fetchAll();
    }

    /** Chamados em andamento (aberto, andamento ou aguardando) sem nenhuma alteração desde `$corte` (AAAA-MM-DD HH:MM:SS). */
    public function chamadosParados(string $corte, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT ch.*, a.nome AS area_nome FROM chamados ch LEFT JOIN areas a ON a.id = ch.area_id
             WHERE ch.arquivado_em IS NULL AND ch.status IN ('aberto', 'andamento', 'aguardando') AND ch.atualizado_em <= :corte
             ORDER BY ch.atualizado_em, ch.id LIMIT " . max(1, $limite)
        );
        $st->execute(['corte' => $corte]);
        return $st->fetchAll();
    }

    /**
     * Retrato da empresa para o briefing de risco: contratos vigentes, negócios abertos, chamados em andamento e a última pesquisa NPS respondida.
     * @return array{contratos:list<array>,negocios_abertos:int,negocios_valor:int,chamados_abertos:int,nps:?array}
     */
    public function retratoDaEmpresa(int $empresaId): array
    {
        $db = DB::conexao();
        $st = $db->prepare(
            "SELECT numero, titulo, valor_mensal, data_fim FROM contratos
             WHERE empresa_id = :e AND arquivado_em IS NULL AND status IN ('assinado', 'ativo') ORDER BY data_fim IS NULL, data_fim, id"
        );
        $st->execute(['e' => $empresaId]);
        $contratos = $st->fetchAll();

        $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(valor_estimado), 0) FROM negocios WHERE empresa_id = :e AND arquivado_em IS NULL AND status = 'aberto'");
        $st->execute(['e' => $empresaId]);
        [$negocios, $valor] = $st->fetch(\PDO::FETCH_NUM);

        $st = $db->prepare("SELECT COUNT(*) FROM chamados WHERE empresa_id = :e AND arquivado_em IS NULL AND status IN ('aberto', 'andamento', 'aguardando')");
        $st->execute(['e' => $empresaId]);
        $chamados = (int) $st->fetchColumn();

        $st = $db->prepare(
            "SELECT nota, categoria, respondida_em FROM pesquisas
             WHERE empresa_id = :e AND status = 'respondida' AND arquivado_em IS NULL ORDER BY respondida_em DESC, id DESC LIMIT 1"
        );
        $st->execute(['e' => $empresaId]);

        return [
            'contratos' => $contratos, 'negocios_abertos' => (int) $negocios, 'negocios_valor' => (int) $valor,
            'chamados_abertos' => $chamados, 'nps' => $st->fetch() ?: null,
        ];
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
