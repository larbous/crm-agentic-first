-- 0008 — Infraestrutura de IA resiliente (Fase 10): multi-provedor com failover, limiar de confiança.
-- Só ADD COLUMN e tabela nova: migrações aplicadas nunca são editadas.

-- Qual provedor respondeu, quantas tentativas (>1 = houve retentativa ou failover) e a confiança declarada pelo agente (0–1).
ALTER TABLE execucoes ADD COLUMN provedor TEXT;
ALTER TABLE execucoes ADD COLUMN tentativas INTEGER;
ALTER TABLE execucoes ADD COLUMN confianca REAL;

-- Por que uma ação foi para aprovação além da configuração do agente (ex.: confiança abaixo do limiar).
ALTER TABLE acoes_pendentes ADD COLUMN motivo TEXT;

-- Estado do disjuntor por provedor. Persistido porque cada requisição PHP começa sem memória.
CREATE TABLE ia_saude_provedor (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    provedor          TEXT NOT NULL UNIQUE,
    falhas_seguidas   INTEGER NOT NULL DEFAULT 0,
    primeira_falha_em TEXT,      -- início da janela de contagem das falhas seguidas
    aberto_ate        TEXT,      -- disjuntor aberto até este instante (chamadas vão direto ao outro provedor)
    ultimo_erro       TEXT,
    atualizado_em     TEXT NOT NULL
);
