<?php

declare(strict_types=1);

namespace App\Repositories;

final class EtapaRepository extends BaseRepository
{
    public function tabela(): string
    {
        return 'etapas';
    }

    protected function selectBase(): string
    {
        return 'SELECT a.*, p.nome AS pipeline_nome FROM etapas a JOIN pipelines p ON p.id = a.pipeline_id';
    }

    protected function ordemPadrao(): string
    {
        return 'p.padrao DESC, p.id ASC, a.ordem ASC, a.id ASC';
    }

    /** Etapas ativas de um pipeline, em ordem. */
    public function doPipeline(int $pipelineId): array
    {
        $st = $this->pdo()->prepare($this->selectBase() . ' WHERE a.pipeline_id = :p AND a.arquivado_em IS NULL ORDER BY a.ordem ASC, a.id ASC');
        $st->execute(['p' => $pipelineId]);
        return $st->fetchAll();
    }

    /** Primeira etapa aberta do pipeline (destino padrão de novos negócios). */
    public function primeiraAberta(int $pipelineId): ?array
    {
        foreach ($this->doPipeline($pipelineId) as $e) {
            if ($e['tipo'] === 'aberta') {
                return $e;
            }
        }
        return null;
    }

    public function proximaOrdem(int $pipelineId): int
    {
        $st = $this->pdo()->prepare('SELECT COALESCE(MAX(ordem), 0) + 1 FROM etapas WHERE pipeline_id = :p');
        $st->execute(['p' => $pipelineId]);
        return (int) $st->fetchColumn();
    }

    /** id => "Pipeline · Etapa" (ou só a etapa quando há um único pipeline). */
    public function opcoes(): array
    {
        $todas = $this->todas();
        $pipelines = array_unique(array_column($todas, 'pipeline_id'));
        $out = [];
        foreach ($todas as $e) {
            $out[$e['id']] = count($pipelines) > 1 ? $e['pipeline_nome'] . ' · ' . $e['nome'] : $e['nome'];
        }
        return $out;
    }
}
