-- @fk-off
-- 0009 — Lead scoring (Fase 11): temperatura ganha o valor "fervendo" e o negócio ganha a qualificação CHAMP.
--
-- O SQLite não altera um CHECK: recria-se a tabela (procedimento de 12 passos da documentação do SQLite).
-- A marca "@fk-off" na primeira linha faz o runner desligar foreign_keys durante esta migração (o PRAGMA não vale
-- dentro de transação), conferir foreign_key_check antes do commit e religar em seguida.
--
-- CHAMP (Desafios, Autoridade, Dinheiro, Prioridade): cada dimensão é 'confirmado', 'parcial' ou 'nao_identificado'
-- (NULL = ainda não avaliada). A pontuação (0–8) e a temperatura derivada são calculadas pelo servidor.

CREATE TABLE negocios_novo (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Básico
    titulo                TEXT NOT NULL,
    codigo                TEXT UNIQUE,       -- NEG-AAAA-NNNN, gerado pela aplicação
    empresa_id            INTEGER REFERENCES empresas (id),
    contato_principal_id  INTEGER REFERENCES contatos (id),
    decisor_id            INTEGER REFERENCES contatos (id),
    pipeline_id           INTEGER REFERENCES pipelines (id),
    etapa_id              INTEGER REFERENCES etapas (id),
    status                TEXT NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto', 'ganho', 'perdido', 'pausado')),
    -- Valores (centavos)
    valor_estimado        INTEGER,
    valor_fechado         INTEGER,
    tipo_receita          TEXT CHECK (tipo_receita IS NULL OR tipo_receita IN ('unico', 'mensal', 'anual')),
    valor_recorrente      INTEGER,
    probabilidade         INTEGER CHECK (probabilidade IS NULL OR probabilidade BETWEEN 0 AND 100),
    -- Datas
    previsao_fechamento   TEXT,
    data_fechamento       TEXT,
    entrou_etapa_em       TEXT,
    -- Qualificação
    temperatura           TEXT CHECK (temperatura IS NULL OR temperatura IN ('frio', 'morno', 'quente', 'fervendo')),
    prioridade            TEXT CHECK (prioridade IS NULL OR prioridade IN ('baixa', 'media', 'alta')),
    dor_principal         TEXT,
    objetivo_cliente      TEXT,
    orcamento_cliente     TEXT,
    prazo_desejado        TEXT,
    criterio_decisao      TEXT,
    concorrentes          TEXT,
    -- Qualificação CHAMP
    champ_desafios        TEXT CHECK (champ_desafios IS NULL OR champ_desafios IN ('confirmado', 'parcial', 'nao_identificado')),
    champ_autoridade      TEXT CHECK (champ_autoridade IS NULL OR champ_autoridade IN ('confirmado', 'parcial', 'nao_identificado')),
    champ_dinheiro        TEXT CHECK (champ_dinheiro IS NULL OR champ_dinheiro IN ('confirmado', 'parcial', 'nao_identificado')),
    champ_prioridade      TEXT CHECK (champ_prioridade IS NULL OR champ_prioridade IN ('confirmado', 'parcial', 'nao_identificado')),
    champ_resumo          TEXT,
    champ_pontos          INTEGER CHECK (champ_pontos IS NULL OR champ_pontos BETWEEN 0 AND 8),
    champ_avaliado_em     TEXT,
    -- Andamento
    proximo_passo         TEXT,
    proximo_passo_em      TEXT,
    motivo_perda_id       INTEGER REFERENCES motivos_perda (id),
    detalhe_perda         TEXT,
    -- Aquisição
    origem_id             INTEGER REFERENCES origens (id),
    formulario_id         INTEGER,
    submissao_id          INTEGER,
    utm_source            TEXT,
    utm_medium            TEXT,
    utm_campaign          TEXT,
    utm_term              TEXT,
    utm_content           TEXT,
    -- Controle
    notas                 TEXT,
    campos_extras         TEXT,              -- JSON
    criado_em             TEXT NOT NULL,
    atualizado_em         TEXT NOT NULL,
    arquivado_em          TEXT,
    criado_por            TEXT NOT NULL DEFAULT 'humano'
);

INSERT INTO negocios_novo (
    id, titulo, codigo, empresa_id, contato_principal_id, decisor_id, pipeline_id, etapa_id, status,
    valor_estimado, valor_fechado, tipo_receita, valor_recorrente, probabilidade,
    previsao_fechamento, data_fechamento, entrou_etapa_em,
    temperatura, prioridade, dor_principal, objetivo_cliente, orcamento_cliente, prazo_desejado, criterio_decisao, concorrentes,
    proximo_passo, proximo_passo_em, motivo_perda_id, detalhe_perda,
    origem_id, formulario_id, submissao_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
    notas, campos_extras, criado_em, atualizado_em, arquivado_em, criado_por
) SELECT
    id, titulo, codigo, empresa_id, contato_principal_id, decisor_id, pipeline_id, etapa_id, status,
    valor_estimado, valor_fechado, tipo_receita, valor_recorrente, probabilidade,
    previsao_fechamento, data_fechamento, entrou_etapa_em,
    temperatura, prioridade, dor_principal, objetivo_cliente, orcamento_cliente, prazo_desejado, criterio_decisao, concorrentes,
    proximo_passo, proximo_passo_em, motivo_perda_id, detalhe_perda,
    origem_id, formulario_id, submissao_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
    notas, campos_extras, criado_em, atualizado_em, arquivado_em, criado_por
FROM negocios;

DROP TABLE negocios;
ALTER TABLE negocios_novo RENAME TO negocios;

CREATE INDEX idx_negocios_empresa ON negocios (empresa_id);
CREATE INDEX idx_negocios_etapa ON negocios (etapa_id);
CREATE INDEX idx_negocios_status ON negocios (status);
CREATE INDEX idx_negocios_temperatura ON negocios (temperatura);
