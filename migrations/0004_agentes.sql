-- 0004 — Agentes de IA e ações pendentes de aprovação (SPEC §4.13, §6).
-- Tabelas de sistema (sem soft delete): um agente é desativado, não apagado. Cada versão salva fica em
-- agentes_versoes (a versão atual também), para histórico e restauração.

CREATE TABLE agentes (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    slug          TEXT NOT NULL UNIQUE,
    nome          TEXT NOT NULL,
    descricao     TEXT,
    definicao     TEXT NOT NULL,            -- JSON completo (.agent.json)
    versao        INTEGER NOT NULL DEFAULT 1,
    ativo         INTEGER NOT NULL DEFAULT 1,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL
);

CREATE TABLE agentes_versoes (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    agente_id  INTEGER NOT NULL REFERENCES agentes (id),
    versao     INTEGER NOT NULL,
    definicao  TEXT NOT NULL,
    criado_em  TEXT NOT NULL,
    UNIQUE (agente_id, versao)
);

CREATE TABLE acoes_pendentes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    execucao_id INTEGER NOT NULL REFERENCES execucoes (id),
    acao        TEXT NOT NULL,              -- JSON: ação no formato do roteador + "servidor" (alvo e dados prontos)
    resumo      TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'aprovada', 'rejeitada')),
    erro        TEXT,                       -- última falha ao aplicar (a ação continua pendente)
    criado_em   TEXT NOT NULL,
    decidido_em TEXT
);
CREATE INDEX idx_acoes_pendentes_status ON acoes_pendentes (status);
CREATE INDEX idx_acoes_pendentes_execucao ON acoes_pendentes (execucao_id);

-- Testes de agente (modo simulação) também consomem tokens e ficam registrados, mas não gravam nada.
ALTER TABLE execucoes ADD COLUMN simulacao INTEGER NOT NULL DEFAULT 0;
