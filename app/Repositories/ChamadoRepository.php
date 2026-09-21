<?php

declare(strict_types=1);

namespace App\Repositories;

use InvalidArgumentException;

/** Chamados (Fase 13): demandas de execução por área. Só SQL; escritas passam pelo ActionExecutor. */
final class ChamadoRepository extends BaseRepository
{
    /** Status que ainda pedem trabalho. */
    public const ABERTOS = "a.status IN ('aberto', 'andamento', 'aguardando')";

    public function tabela(): string
    {
        return 'chamados';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, ar.nome AS area_nome, e.nome_fantasia AS empresa_nome, TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome,
                    n.titulo AS negocio_titulo, ct.numero AS contrato_numero
                FROM chamados a
                JOIN areas ar ON ar.id = a.area_id
                LEFT JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN contatos c ON c.id = a.contato_id
                LEFT JOIN negocios n ON n.id = a.negocio_id
                LEFT JOIN contratos ct ON ct.id = a.contrato_id";
    }

    protected function ordenaveis(): array
    {
        return [
            'codigo' => 'a.codigo', 'titulo' => 'a.titulo COLLATE pt_br', 'area' => 'ar.nome COLLATE pt_br', 'empresa' => 'e.nome_fantasia COLLATE pt_br',
            'prioridade' => "CASE a.prioridade WHEN 'baixa' THEN 1 WHEN 'media' THEN 2 WHEN 'alta' THEN 3 WHEN 'urgente' THEN 4 END",
            'status' => 'a.status', 'vencimento' => 'a.vencimento', 'criado_em' => 'a.criado_em',
        ];
    }

    protected function filtraveis(): array
    {
        return ['area_id' => 'a.area_id', 'status' => 'a.status', 'prioridade' => 'a.prioridade', 'empresa_id' => 'a.empresa_id'];
    }

    protected function buscaveis(): array
    {
        return ['a.titulo', 'a.codigo', 'e.nome_fantasia', 'ar.nome'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.criado_em DESC, a.id DESC';
    }

    /** Filtro "abertos": só os que ainda pedem trabalho (aberto, em andamento, aguardando). */
    protected function filtroEspecial(string $chave, mixed $valor, array &$params): ?string
    {
        return $chave === 'situacao' ? ($valor === 'abertos' ? self::ABERTOS : ($valor === 'fechados' ? "a.status IN ('concluido', 'cancelado')" : null)) : null;
    }

    /** Próximo código CH-AAAA-NNNN (conta também os arquivados, para nunca repetir). */
    public function proximoCodigo(int $ano): string
    {
        $prefixo = sprintf('CH-%04d-', $ano);
        $st = $this->pdo()->prepare('SELECT MAX(CAST(substr(codigo, :n) AS INTEGER)) FROM chamados WHERE codigo LIKE :p');
        $st->execute(['n' => strlen($prefixo) + 1, 'p' => $prefixo . '%']);
        return $prefixo . sprintf('%04d', ((int) $st->fetchColumn()) + 1);
    }

    /**
     * Chamados abertos de um grupo da tela de tarefas (hoje | atrasadas | proximas | todas), filtrados por área.
     * Sem vencimento só aparecem em "todas". $hoje e $limite (hoje + 7 dias) em ISO.
     */
    public function grupo(string $grupo, string $hoje, string $limite, ?int $areaId = null): array
    {
        $venc = 'substr(a.vencimento, 1, 10)';
        [$onde, $params] = match ($grupo) {
            'hoje'      => [self::ABERTOS . " AND {$venc} = :hoje", ['hoje' => $hoje]],
            'atrasadas' => [self::ABERTOS . " AND {$venc} < :hoje", ['hoje' => $hoje]],
            'proximas'  => [self::ABERTOS . " AND {$venc} > :hoje AND {$venc} <= :limite", ['hoje' => $hoje, 'limite' => $limite]],
            'todas'     => ['1 = 1', []],
            default     => throw new InvalidArgumentException("Grupo de chamados inválido: {$grupo}"),
        };
        if ($areaId !== null) {
            $onde .= ' AND a.area_id = :area';
            $params['area'] = $areaId;
        }
        $ordem = $grupo === 'todas'
            ? 'CASE WHEN ' . self::ABERTOS . ' THEN 0 ELSE 1 END, a.vencimento IS NULL, a.vencimento ASC, a.id DESC'
            : 'a.vencimento ASC, a.id DESC';
        $st = $this->pdo()->prepare($this->selectBase() . " WHERE a.arquivado_em IS NULL AND {$onde} ORDER BY {$ordem} LIMIT 500");
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array{hoje:int,atrasadas:int,proximas:int,todas:int} */
    public function contagens(string $hoje, string $limite, ?int $areaId = null): array
    {
        $venc = 'substr(vencimento, 1, 10)';
        $ab = "status IN ('aberto', 'andamento', 'aguardando') AND arquivado_em IS NULL";
        $st = $this->pdo()->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN {$ab} AND {$venc} = :hoje THEN 1 ELSE 0 END), 0) AS hoje,
               COALESCE(SUM(CASE WHEN {$ab} AND {$venc} < :hoje THEN 1 ELSE 0 END), 0) AS atrasadas,
               COALESCE(SUM(CASE WHEN {$ab} AND {$venc} > :hoje AND {$venc} <= :limite THEN 1 ELSE 0 END), 0) AS proximas,
               COALESCE(SUM(CASE WHEN arquivado_em IS NULL THEN 1 ELSE 0 END), 0) AS todas
             FROM chamados WHERE (:area IS NULL OR area_id = :area)"
        );
        $st->execute(['hoje' => $hoje, 'limite' => $limite, 'area' => $areaId]);
        $r = $st->fetch();
        return ['hoje' => (int) $r['hoje'], 'atrasadas' => (int) $r['atrasadas'], 'proximas' => (int) $r['proximas'], 'todas' => (int) $r['todas']];
    }

    /** Chamados ligados a uma empresa; abertos primeiro. */
    public function daEmpresa(int $empresaId, int $limite = 50): array
    {
        $limite = max(1, min(200, $limite));
        $st = $this->pdo()->prepare(
            $this->selectBase() . " WHERE a.empresa_id = :id AND a.arquivado_em IS NULL
             ORDER BY CASE WHEN " . self::ABERTOS . " THEN 0 ELSE 1 END, a.vencimento IS NULL, a.vencimento ASC, a.id DESC LIMIT {$limite}"
        );
        $st->execute(['id' => $empresaId]);
        return $st->fetchAll();
    }

    /** Quantos chamados abertos há por área (para o resumo por área da tela). @return array<int,int> area_id => quantidade */
    public function abertosPorArea(): array
    {
        $st = $this->pdo()->query("SELECT area_id, COUNT(*) AS n FROM chamados a WHERE a.arquivado_em IS NULL AND " . self::ABERTOS . ' GROUP BY area_id');
        return array_map('intval', array_column($st->fetchAll(), 'n', 'area_id'));
    }
}
