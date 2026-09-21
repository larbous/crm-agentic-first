-- 0011 — Chamados e demandas operacionais por área (Fase 13).
-- Chamado é uma demanda de execução da agência (ex.: "criar campanha", "arte do post"), separada de Tarefa (o que o
-- operador precisa fazer em relação ao cliente). Tem área/departamento, status próprio, código e checklist de execução.

-- Áreas/departamentos (lista configurável em Configurações, como origens e tipos de contrato).
CREATE TABLE areas (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_areas_nome ON areas (nome) WHERE arquivado_em IS NULL;

CREATE TABLE chamados (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo        TEXT UNIQUE,                 -- CH-AAAA-NNNN, gerado pela aplicação
    titulo        TEXT NOT NULL,
    descricao     TEXT,
    area_id       INTEGER NOT NULL REFERENCES areas (id),
    prioridade    TEXT NOT NULL DEFAULT 'media' CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente')),
    status        TEXT NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'andamento', 'aguardando', 'concluido', 'cancelado')),
    vencimento    TEXT,                        -- data ou data+hora (prazo de entrega)
    empresa_id    INTEGER REFERENCES empresas (id),
    contato_id    INTEGER REFERENCES contatos (id),
    negocio_id    INTEGER REFERENCES negocios (id),
    contrato_id   INTEGER REFERENCES contratos (id),
    checklist     TEXT,                        -- JSON: [{"texto": "...", "feito": 0|1}]
    resolucao     TEXT,                        -- o que foi entregue/decidido ao fechar
    concluido_em  TEXT,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_chamados_status_venc ON chamados (status, vencimento);
CREATE INDEX idx_chamados_area ON chamados (area_id, status);
CREATE INDEX idx_chamados_empresa ON chamados (empresa_id);
