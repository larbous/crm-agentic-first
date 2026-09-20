-- 0003 — Chat e execuções de IA (SPEC §4.13).
-- Tabelas de sistema (sem soft delete). agente_id/squad_id são referências lógicas: as tabelas
-- agentes e squads entram nas Fases 5 e 6.

CREATE TABLE chat_mensagens (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    papel     TEXT NOT NULL CHECK (papel IN ('operador', 'sistema')),
    conteudo  TEXT NOT NULL,
    payload   TEXT,                 -- JSON: tipo da resposta, link, opções, confirmação pendente…
    contexto  TEXT,                 -- JSON: operador = {tela, ultima_ref}; sistema = {ultima_ref}
    criado_em TEXT NOT NULL
);

CREATE TABLE execucoes (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    agente_id         INTEGER,
    squad_id          INTEGER,
    squad_execucao_id INTEGER,
    etapa_ordem       INTEGER,
    entidade          TEXT,
    registro_id       INTEGER,
    entrada           TEXT,
    saida             TEXT,
    status            TEXT NOT NULL DEFAULT 'fila'
                      CHECK (status IN ('fila', 'rodando', 'concluida', 'erro', 'aguardando_aprovacao', 'cancelada')),
    modelo            TEXT,
    tokens_entrada    INTEGER,
    tokens_saida      INTEGER,
    duracao_ms        INTEGER,
    erro              TEXT,
    iniciado_em       TEXT,
    concluido_em      TEXT
);
CREATE INDEX idx_execucoes_status ON execucoes (status);
CREATE INDEX idx_execucoes_agente ON execucoes (agente_id);
