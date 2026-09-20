-- 0005 — Squads, agendamentos e marcas do worker (SPEC §4.13, §7, §11).
-- Tabelas de sistema (sem soft delete): um squad é desativado, não apagado. Cada versão salva fica em squads_versoes.
-- Uma execução de squad é uma linha "cabeçalho" em execucoes (squad_id preenchido, squad_execucao_id nulo) cujo
-- `saida` guarda o progresso das etapas (JSON); cada etapa de IA é outra linha em execucoes (squad_execucao_id = cabeçalho).

CREATE TABLE squads (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    slug          TEXT NOT NULL UNIQUE,
    nome          TEXT NOT NULL,
    descricao     TEXT,
    definicao     TEXT NOT NULL,            -- JSON completo (.squad.json)
    versao        INTEGER NOT NULL DEFAULT 1,
    ativo         INTEGER NOT NULL DEFAULT 1,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL
);

CREATE TABLE squads_versoes (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    squad_id  INTEGER NOT NULL REFERENCES squads (id),
    versao    INTEGER NOT NULL,
    definicao TEXT NOT NULL,
    criado_em TEXT NOT NULL,
    UNIQUE (squad_id, versao)
);

-- Gatilhos "agendado" de agentes e squads (sincronizados a partir das definições).
CREATE TABLE agendamentos_execucao (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    agente_id     INTEGER REFERENCES agentes (id),
    squad_id      INTEGER REFERENCES squads (id),
    cron          TEXT NOT NULL,
    ultimo_run_em TEXT,
    proximo_run_em TEXT NOT NULL,
    CHECK ((agente_id IS NULL) <> (squad_id IS NULL))
);
CREATE UNIQUE INDEX idx_agendamentos_agente ON agendamentos_execucao (agente_id) WHERE agente_id IS NOT NULL;
CREATE UNIQUE INDEX idx_agendamentos_squad ON agendamentos_execucao (squad_id) WHERE squad_id IS NOT NULL;
CREATE INDEX idx_agendamentos_proximo ON agendamentos_execucao (proximo_run_em);

-- Avisos do worker já emitidos (uma vez por chave): evita repetir tarefa.vencida, contrato.vencendo, recorrência…
CREATE TABLE worker_marcas (
    chave     TEXT PRIMARY KEY,
    criado_em TEXT NOT NULL
);

CREATE INDEX idx_execucoes_fila ON execucoes (status, id);
CREATE INDEX idx_execucoes_squad ON execucoes (squad_execucao_id);
