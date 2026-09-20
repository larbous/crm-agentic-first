<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Dados iniciais (pipeline/etapas, origens, motivos de perda, configurações).
 * Idempotente: só insere o que ainda não existe.
 */
final class SeedRepository
{
    private const CRIADO_POR = 'sistema';

    public function __construct(private PDO $pdo)
    {
    }

    /** @param list<array{0:string,1:int,2:string,3:string}> $etapas [nome, probabilidade, cor, tipo] */
    public function garantirPipeline(string $nome, array $etapas): int
    {
        $st = $this->pdo->prepare('SELECT id FROM pipelines WHERE nome = :nome LIMIT 1');
        $st->execute(['nome' => $nome]);
        $pipelineId = $st->fetchColumn();

        if ($pipelineId === false) {
            $agora = agora();
            $ins = $this->pdo->prepare(
                'INSERT INTO pipelines (nome, padrao, criado_em, atualizado_em, criado_por)
                 VALUES (:nome, 1, :agora, :agora, :por)'
            );
            $ins->execute(['nome' => $nome, 'agora' => $agora, 'por' => self::CRIADO_POR]);
            $pipelineId = (int) $this->pdo->lastInsertId();
        }
        $pipelineId = (int) $pipelineId;

        $existe = $this->pdo->prepare('SELECT 1 FROM etapas WHERE pipeline_id = :p AND nome = :nome');
        $ins = $this->pdo->prepare(
            'INSERT INTO etapas (pipeline_id, nome, ordem, probabilidade_padrao, cor, tipo, criado_em, atualizado_em, criado_por)
             VALUES (:p, :nome, :ordem, :prob, :cor, :tipo, :agora, :agora, :por)'
        );
        foreach ($etapas as $ordem => [$etapa, $prob, $cor, $tipo]) {
            $existe->execute(['p' => $pipelineId, 'nome' => $etapa]);
            if ($existe->fetchColumn() !== false) {
                continue;
            }
            $ins->execute([
                'p' => $pipelineId, 'nome' => $etapa, 'ordem' => $ordem + 1, 'prob' => $prob,
                'cor' => $cor, 'tipo' => $tipo, 'agora' => agora(), 'por' => self::CRIADO_POR,
            ]);
        }
        return $pipelineId;
    }

    /** @param list<string> $nomes */
    public function garantirNomes(string $tabela, array $nomes): void
    {
        if (!in_array($tabela, ['origens', 'motivos_perda', 'contrato_tipos'], true)) {
            throw new \InvalidArgumentException("Tabela não permitida no seed: {$tabela}");
        }
        $existe = $this->pdo->prepare("SELECT 1 FROM {$tabela} WHERE nome = :nome");
        $ins = $this->pdo->prepare(
            "INSERT INTO {$tabela} (nome, criado_em, atualizado_em, criado_por) VALUES (:nome, :agora, :agora, :por)"
        );
        foreach ($nomes as $nome) {
            $existe->execute(['nome' => $nome]);
            if ($existe->fetchColumn() === false) {
                $ins->execute(['nome' => $nome, 'agora' => agora(), 'por' => self::CRIADO_POR]);
            }
        }
    }

    /** Cria o modelo de documento se ainda não houver um do mesmo tipo e nome (não sobrescreve edições). */
    public function garantirModelo(string $tipo, string $nome, ?string $assunto, string $conteudo): void
    {
        $existe = $this->pdo->prepare('SELECT 1 FROM modelos_documento WHERE tipo = :t AND nome = :n');
        $existe->execute(['t' => $tipo, 'n' => $nome]);
        if ($existe->fetchColumn() !== false) {
            return;
        }
        $agora = agora();
        $this->pdo->prepare(
            'INSERT INTO modelos_documento (tipo, nome, assunto, conteudo, ativo, criado_em, atualizado_em, criado_por)
             VALUES (:tipo, :nome, :assunto, :conteudo, 1, :agora, :agora, :por)'
        )->execute(['tipo' => $tipo, 'nome' => $nome, 'assunto' => $assunto, 'conteudo' => $conteudo, 'agora' => $agora, 'por' => self::CRIADO_POR]);
    }

    /** Insere a configuração apenas se a chave ainda não existir. */
    public function garantirConfiguracao(string $chave, string $valor): void
    {
        $st = $this->pdo->prepare(
            'INSERT OR IGNORE INTO configuracoes (chave, valor, atualizado_em) VALUES (:chave, :valor, :agora)'
        );
        $st->execute(['chave' => $chave, 'valor' => $valor, 'agora' => agora()]);
    }
}
