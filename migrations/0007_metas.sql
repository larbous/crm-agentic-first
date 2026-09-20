-- 0007 — Metas comerciais (SPEC §4.11) e marca das ações rápidas de IA em execucoes (SPEC §8).
-- valor_alvo: centavos para faturamento e mrr; quantidade para os demais tipos. data_fim é derivada de periodo +
-- data_inicio pelo ActionExecutor. O progresso não é gravado: é calculado na hora a partir dos registros.

CREATE TABLE metas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo           TEXT NOT NULL CHECK (tipo IN ('faturamento', 'mrr', 'novos_clientes', 'propostas_enviadas', 'negocios_ganhos')),
    periodo        TEXT NOT NULL CHECK (periodo IN ('mensal', 'trimestral', 'anual')),
    valor_alvo     INTEGER NOT NULL CHECK (valor_alvo > 0),
    data_inicio    TEXT NOT NULL,
    data_fim       TEXT NOT NULL,
    criado_em      TEXT NOT NULL,
    atualizado_em  TEXT NOT NULL,
    arquivado_em   TEXT,
    criado_por     TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_metas_vigencia ON metas (data_inicio, data_fim);

-- Ação rápida que gerou a chamada de IA (melhorar, formal, amigavel, resumir, resposta); NULL nas demais execuções.
ALTER TABLE execucoes ADD COLUMN acao_rapida TEXT;
