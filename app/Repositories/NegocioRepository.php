<?php

declare(strict_types=1);

namespace App\Repositories;

final class NegocioRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'negocios';
    }

    protected function selectBase(): string
    {
        return "SELECT a.*, e.nome_fantasia AS empresa_nome, et.nome AS etapa_nome, et.cor AS etapa_cor, et.tipo AS etapa_tipo,
                    p.nome AS pipeline_nome, TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome,
                    o.nome AS origem_nome, mp.nome AS motivo_perda_nome,
                    (COALESCE(a.valor_estimado, 0) * COALESCE(a.probabilidade, 0) / 100) AS valor_ponderado
                FROM negocios a
                LEFT JOIN empresas e ON e.id = a.empresa_id
                LEFT JOIN etapas et ON et.id = a.etapa_id
                LEFT JOIN pipelines p ON p.id = a.pipeline_id
                LEFT JOIN contatos c ON c.id = a.contato_principal_id
                LEFT JOIN origens o ON o.id = a.origem_id
                LEFT JOIN motivos_perda mp ON mp.id = a.motivo_perda_id";
    }

    protected function ordenaveis(): array
    {
        return [
            'codigo' => 'a.codigo', 'titulo' => 'a.titulo COLLATE pt_br', 'empresa_nome' => 'e.nome_fantasia COLLATE pt_br',
            'etapa_nome' => 'et.ordem', 'valor_estimado' => 'a.valor_estimado', 'valor_ponderado' => 'valor_ponderado',
            'probabilidade' => 'a.probabilidade', 'previsao_fechamento' => 'a.previsao_fechamento', 'status' => 'a.status',
            'temperatura' => "CASE a.temperatura WHEN 'frio' THEN 1 WHEN 'morno' THEN 2 WHEN 'quente' THEN 3 WHEN 'fervendo' THEN 4 END", 'entrou_etapa_em' => 'a.entrou_etapa_em', 'criado_em' => 'a.criado_em',
        ];
    }

    protected function filtraveis(): array
    {
        return [
            'status' => 'a.status', 'etapa_id' => 'a.etapa_id', 'empresa_id' => 'a.empresa_id', 'origem_id' => 'a.origem_id',
            'temperatura' => 'a.temperatura', 'pipeline_id' => 'a.pipeline_id',
        ];
    }

    protected function buscaveis(): array
    {
        return ['a.titulo', 'a.codigo', 'e.nome_fantasia', 'a.proximo_passo'];
    }

    protected function ordemPadrao(): string
    {
        return 'a.criado_em DESC, a.id DESC';
    }

    /** Próximo código NEG-AAAA-NNNN (conta também os arquivados, para nunca repetir). */
    public function proximoCodigo(int $ano): string
    {
        $prefixo = sprintf('NEG-%04d-', $ano);
        $st = $this->pdo()->prepare('SELECT COALESCE(MAX(CAST(SUBSTR(codigo, 10) AS INTEGER)), 0) + 1 FROM negocios WHERE codigo LIKE :p');
        $st->execute(['p' => $prefixo . '%']);
        return $prefixo . str_pad((string) $st->fetchColumn(), 4, '0', STR_PAD_LEFT);
    }

    /** Negócios de um pipeline agrupados por etapa (kanban). Ganho/perdido limitados aos mais recentes. */
    public function kanban(int $pipelineId, int $limiteFechados = 50): array
    {
        $st = $this->pdo()->prepare(
            $this->selectBase() . ' WHERE a.pipeline_id = :p AND a.arquivado_em IS NULL
             ORDER BY a.entrou_etapa_em DESC, a.id DESC'
        );
        $st->execute(['p' => $pipelineId]);
        $porEtapa = [];
        foreach ($st->fetchAll() as $n) {
            $porEtapa[(int) $n['etapa_id']][] = $n;
        }
        return $porEtapa;
    }

    /**
     * Funil aberto de um pipeline, por etapa (só etapas abertas, mesmo vazias): qtd, valor estimado e valor ponderado
     * (estimado × probabilidade do negócio, a mesma conta da lista), em centavos.
     * @return list<array{id:int,nome:string,cor:?string,qtd:int,valor:int,ponderado:int}>
     */
    public function resumoPorEtapa(int $pipelineId): array
    {
        $st = $this->pdo()->prepare(
            "SELECT et.id, et.nome, et.cor, COUNT(a.id) AS qtd,
                    COALESCE(SUM(a.valor_estimado), 0) AS valor,
                    COALESCE(SUM(COALESCE(a.valor_estimado, 0) * COALESCE(a.probabilidade, 0) / 100), 0) AS ponderado
             FROM etapas et
             LEFT JOIN negocios a ON a.etapa_id = et.id AND a.arquivado_em IS NULL AND a.status = 'aberto'
             WHERE et.pipeline_id = :p AND et.arquivado_em IS NULL AND et.tipo = 'aberta'
             GROUP BY et.id ORDER BY et.ordem ASC, et.id ASC"
        );
        $st->execute(['p' => $pipelineId]);
        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'], 'nome' => $l['nome'], 'cor' => $l['cor'],
            'qtd' => (int) $l['qtd'], 'valor' => (int) $l['valor'], 'ponderado' => (int) $l['ponderado'],
        ], $st->fetchAll());
    }

    /**
     * Negócios abertos parados na mesma etapa há mais de $dias dias (mais antigos primeiro).
     * @return array{total:int,linhas:list<array>}
     */
    public function parados(int $dias = 14, int $limite = 8): array
    {
        $onde = "a.arquivado_em IS NULL AND a.status = 'aberto' AND et.tipo = 'aberta'
                 AND a.entrou_etapa_em IS NOT NULL AND a.entrou_etapa_em < :corte";
        $params = ['corte' => date('Y-m-d H:i:s', strtotime("-{$dias} days"))];
        $st = $this->pdo()->prepare("SELECT COUNT(*) FROM negocios a JOIN etapas et ON et.id = a.etapa_id WHERE {$onde}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $limite = max(1, min(50, $limite));
        $st = $this->pdo()->prepare($this->selectBase() . " WHERE {$onde} ORDER BY a.entrou_etapa_em ASC, a.id ASC LIMIT {$limite}");
        $st->execute($params);
        return ['total' => $total, 'linhas' => $st->fetchAll()];
    }

    public function porEmpresa(int $empresaId): array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.empresa_id = :e AND a.arquivado_em IS NULL ORDER BY a.criado_em DESC');
        $st->execute(['e' => $empresaId]);
        return $st->fetchAll();
    }

    /** Negócio mais recente da empresa com um destes status (aberto, pausado, ganho, perdido), ou null. */
    public function ultimoDaEmpresa(int $empresaId, array $status): ?array
    {
        if ($status === []) {
            return null;
        }
        $marcas = [];
        $params = ['e' => $empresaId];
        foreach (array_values($status) as $i => $s) {
            $marcas[] = ":s{$i}";
            $params["s{$i}"] = (string) $s;
        }
        $st = $this->pdo()->prepare(
            $this->selectBase() . ' WHERE a.empresa_id = :e AND a.arquivado_em IS NULL AND a.status IN (' . implode(', ', $marcas) . ')
             ORDER BY a.criado_em DESC, a.id DESC LIMIT 1'
        );
        $st->execute($params);
        return $st->fetch() ?: null;
    }

    /** Negócios em que o contato é principal, decisor ou está vinculado. */
    public function porContato(int $contatoId): array
    {
        $st = $this->pdo()->prepare(
            $this->selectBase() . ' WHERE a.arquivado_em IS NULL AND (a.contato_principal_id = :c OR a.decisor_id = :c
              OR EXISTS (SELECT 1 FROM negocio_contatos nc WHERE nc.negocio_id = a.id AND nc.contato_id = :c))
             ORDER BY a.criado_em DESC'
        );
        $st->execute(['c' => $contatoId]);
        return $st->fetchAll();
    }

    public function contarAtivosNaEtapa(int $etapaId): int
    {
        $st = $this->pdo()->prepare('SELECT COUNT(*) FROM negocios WHERE etapa_id = :e AND arquivado_em IS NULL');
        $st->execute(['e' => $etapaId]);
        return (int) $st->fetchColumn();
    }

    /** Opções para selects: id => "CÓDIGO — título". */
    public function opcoes(): array
    {
        $st = $this->pdo()->query("SELECT id, codigo, titulo FROM negocios WHERE arquivado_em IS NULL ORDER BY criado_em DESC");
        $out = [];
        foreach ($st->fetchAll() as $n) {
            $out[$n['id']] = trim(($n['codigo'] ?? '') . ' — ' . $n['titulo'], ' —');
        }
        return $out;
    }

    // ---- Vínculo negocio_contatos (tabela pivô: sem soft delete)

    /** @return list<array> */
    public function contatosVinculados(int $negocioId): array
    {
        $st = $this->pdo()->prepare(
            "SELECT nc.id AS vinculo_id, nc.papel, c.id, TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS nome_completo, c.email, c.whatsapp, c.cargo
             FROM negocio_contatos nc JOIN contatos c ON c.id = nc.contato_id
             WHERE nc.negocio_id = :n AND c.arquivado_em IS NULL ORDER BY nc.id"
        );
        $st->execute(['n' => $negocioId]);
        return $st->fetchAll();
    }

    public function encontrarVinculo(int $negocioId, int $contatoId): ?array
    {
        $st = $this->pdo()->prepare('SELECT * FROM negocio_contatos WHERE negocio_id = :n AND contato_id = :c');
        $st->execute(['n' => $negocioId, 'c' => $contatoId]);
        return $st->fetch() ?: null;
    }

    public function vincularContato(int $negocioId, int $contatoId, ?string $papel): void
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO negocio_contatos (negocio_id, contato_id, papel, criado_em) VALUES (:n, :c, :p, :q)
             ON CONFLICT (negocio_id, contato_id) DO UPDATE SET papel = excluded.papel'
        );
        $st->execute(['n' => $negocioId, 'c' => $contatoId, 'p' => $papel, 'q' => agora()]);
    }

    public function desvincularContato(int $negocioId, int $contatoId): void
    {
        $st = $this->pdo()->prepare('DELETE FROM negocio_contatos WHERE negocio_id = :n AND contato_id = :c');
        $st->execute(['n' => $negocioId, 'c' => $contatoId]);
    }
}
