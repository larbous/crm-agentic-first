-- 0001 — Núcleo: usuários, configurações, auditoria, apoio, empresas, contatos, negócios,
-- atividades, tarefas, anexos e campos extras (SPEC §4.1–4.5, 4.12, 4.13).
-- Datas em texto ISO 8601 (America/Sao_Paulo, preenchidas pela aplicação); valores monetários em centavos.
-- Parte do CRM Lárbous (github.com/larbous/crm-agentic-first) — MIT.

------------------------------------------------------------------------------
-- Sistema
------------------------------------------------------------------------------

CREATE TABLE usuarios (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    nome            TEXT NOT NULL,
    email           TEXT NOT NULL UNIQUE,
    senha_hash      TEXT NOT NULL,
    ultimo_login_em TEXT,
    criado_em       TEXT NOT NULL,
    atualizado_em   TEXT NOT NULL,
    arquivado_em    TEXT
);

CREATE TABLE configuracoes (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    chave         TEXT NOT NULL UNIQUE,
    valor         TEXT,
    atualizado_em TEXT NOT NULL
);

CREATE TABLE log_auditoria (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    data         TEXT NOT NULL,
    origem       TEXT NOT NULL,              -- humano | ia | agente:<slug> | formulario:<id> | sistema
    entidade     TEXT NOT NULL,
    registro_id  INTEGER,
    acao         TEXT NOT NULL,
    antes        TEXT,                       -- JSON
    depois       TEXT,                       -- JSON
    execucao_id  INTEGER,                    -- FK lógica para execucoes (tabela criada na Fase 4)
    desfeito_em  TEXT
);
CREATE INDEX idx_auditoria_registro ON log_auditoria (entidade, registro_id);
CREATE INDEX idx_auditoria_data ON log_auditoria (data);

------------------------------------------------------------------------------
-- Apoio
------------------------------------------------------------------------------

CREATE TABLE tags (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    cor           TEXT,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_tags_nome ON tags (nome) WHERE arquivado_em IS NULL;

CREATE TABLE taggables (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_id      INTEGER NOT NULL REFERENCES tags (id),
    entidade    TEXT NOT NULL CHECK (entidade IN ('empresas', 'contatos', 'negocios')),
    registro_id INTEGER NOT NULL,
    criado_em   TEXT NOT NULL,
    UNIQUE (tag_id, entidade, registro_id)
);
CREATE INDEX idx_taggables_registro ON taggables (entidade, registro_id);

CREATE TABLE origens (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_origens_nome ON origens (nome) WHERE arquivado_em IS NULL;

CREATE TABLE motivos_perda (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_motivos_perda_nome ON motivos_perda (nome) WHERE arquivado_em IS NULL;

CREATE TABLE pipelines (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    padrao        INTEGER NOT NULL DEFAULT 0 CHECK (padrao IN (0, 1)),
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);

CREATE TABLE etapas (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    pipeline_id          INTEGER NOT NULL REFERENCES pipelines (id),
    nome                 TEXT NOT NULL,
    ordem                INTEGER NOT NULL DEFAULT 0,
    probabilidade_padrao INTEGER NOT NULL DEFAULT 0 CHECK (probabilidade_padrao BETWEEN 0 AND 100),
    cor                  TEXT,
    tipo                 TEXT NOT NULL DEFAULT 'aberta' CHECK (tipo IN ('aberta', 'ganho', 'perdido')),
    criado_em            TEXT NOT NULL,
    atualizado_em        TEXT NOT NULL,
    arquivado_em         TEXT,
    criado_por           TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_etapas_pipeline ON etapas (pipeline_id, ordem);

CREATE TABLE campos_extras_def (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    entidade      TEXT NOT NULL CHECK (entidade IN ('empresas', 'contatos', 'negocios')),
    chave         TEXT NOT NULL,
    rotulo        TEXT NOT NULL,
    tipo          TEXT NOT NULL DEFAULT 'texto'
                  CHECK (tipo IN ('texto', 'numero', 'data', 'select', 'checkbox', 'url', 'textarea')),
    opcoes        TEXT,                      -- JSON
    obrigatorio   INTEGER NOT NULL DEFAULT 0 CHECK (obrigatorio IN (0, 1)),
    ordem         INTEGER NOT NULL DEFAULT 0,
    ativo         INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano',
    UNIQUE (entidade, chave)
);

------------------------------------------------------------------------------
-- Empresas (SPEC §4.1)
------------------------------------------------------------------------------

CREATE TABLE empresas (
    id                         INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Identificação
    razao_social               TEXT,
    nome_fantasia              TEXT NOT NULL,
    cnpj                       TEXT,
    inscricao_estadual         TEXT,
    inscricao_municipal        TEXT,
    porte                      TEXT CHECK (porte IS NULL OR porte IN ('mei', 'me', 'epp', 'medio', 'grande')),
    segmento                   TEXT,
    cnae_principal             TEXT,
    data_fundacao              TEXT,
    logo                       INTEGER REFERENCES anexos (id),
    -- Contato
    email_geral                TEXT,
    telefone                   TEXT,
    whatsapp                   TEXT,
    site                       TEXT,
    instagram                  TEXT,
    facebook                   TEXT,
    linkedin                   TEXT,
    tiktok                     TEXT,
    youtube                    TEXT,
    google_meu_negocio         TEXT,
    -- Endereço
    cep                        TEXT,
    logradouro                 TEXT,
    numero                     TEXT,
    complemento                TEXT,
    bairro                     TEXT,
    cidade                     TEXT,
    uf                         TEXT,
    pais                       TEXT NOT NULL DEFAULT 'Brasil',
    -- Comercial
    status                     TEXT NOT NULL DEFAULT 'lead'
                               CHECK (status IN ('lead', 'prospect', 'cliente', 'ex_cliente', 'inativo')),
    origem_id                  INTEGER REFERENCES origens (id),
    indicado_por               TEXT,
    classificacao              TEXT CHECK (classificacao IS NULL OR classificacao IN ('A', 'B', 'C')),
    faixa_faturamento          TEXT,
    faixa_funcionarios         TEXT,
    ticket_potencial           INTEGER,      -- centavos
    cliente_desde              TEXT,
    -- Presença digital
    dominio                    TEXT,
    registrador_dominio        TEXT,
    vencimento_dominio         TEXT,
    hospedagem_atual           TEXT,
    plataforma_site            TEXT,
    tem_ecommerce              INTEGER NOT NULL DEFAULT 0 CHECK (tem_ecommerce IN (0, 1)),
    plataforma_ecommerce       TEXT,
    erp                        TEXT,
    ferramenta_email_marketing TEXT,
    usa_trafego_pago           INTEGER NOT NULL DEFAULT 0 CHECK (usa_trafego_pago IN (0, 1)),
    observacoes_digitais       TEXT,
    -- Aquisição (formulario_id/submissao_id passam a ter FK na Fase 8)
    formulario_id              INTEGER,
    submissao_id               INTEGER,
    utm_source                 TEXT,
    utm_medium                 TEXT,
    utm_campaign               TEXT,
    utm_term                   TEXT,
    utm_content                TEXT,
    -- Controle
    notas                      TEXT,
    campos_extras              TEXT,         -- JSON
    criado_em                  TEXT NOT NULL,
    atualizado_em              TEXT NOT NULL,
    arquivado_em               TEXT,
    criado_por                 TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_empresas_cnpj ON empresas (cnpj)
    WHERE cnpj IS NOT NULL AND cnpj <> '' AND arquivado_em IS NULL;
CREATE INDEX idx_empresas_nome ON empresas (nome_fantasia);
CREATE INDEX idx_empresas_status ON empresas (status);
CREATE INDEX idx_empresas_origem ON empresas (origem_id);

------------------------------------------------------------------------------
-- Contatos (SPEC §4.2)
------------------------------------------------------------------------------

CREATE TABLE contatos (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Pessoal
    nome                  TEXT NOT NULL,
    sobrenome             TEXT,
    apelido               TEXT,
    cpf                   TEXT,
    data_nascimento       TEXT,
    foto                  INTEGER REFERENCES anexos (id),
    -- Profissional
    empresa_id            INTEGER REFERENCES empresas (id),
    cargo                 TEXT,
    departamento          TEXT,
    papel_decisao         TEXT CHECK (papel_decisao IS NULL OR papel_decisao IN
                          ('decisor', 'influenciador', 'financeiro', 'tecnico', 'usuario')),
    nivel_hierarquico     TEXT,
    -- Canais
    email                 TEXT,
    email_secundario      TEXT,
    telefone              TEXT,
    whatsapp              TEXT,
    linkedin              TEXT,
    instagram             TEXT,
    canal_preferido       TEXT CHECK (canal_preferido IS NULL OR canal_preferido IN ('whatsapp', 'email', 'telefone')),
    melhor_horario        TEXT,
    -- LGPD
    opt_in_marketing      INTEGER NOT NULL DEFAULT 0 CHECK (opt_in_marketing IN (0, 1)),
    base_legal            TEXT CHECK (base_legal IS NULL OR base_legal IN ('consentimento', 'contrato', 'legitimo_interesse')),
    data_consentimento    TEXT,
    origem_consentimento  TEXT,
    -- Relacionamento
    status                TEXT NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'inativo')),
    origem_id             INTEGER REFERENCES origens (id),
    ultimo_contato_em     TEXT,
    proximo_contato_em    TEXT,
    nivel_relacionamento  INTEGER CHECK (nivel_relacionamento IS NULL OR nivel_relacionamento BETWEEN 1 AND 5),
    interesses            TEXT,
    -- Controle
    notas                 TEXT,
    campos_extras         TEXT,              -- JSON
    criado_em             TEXT NOT NULL,
    atualizado_em         TEXT NOT NULL,
    arquivado_em          TEXT,
    criado_por            TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_contatos_empresa ON contatos (empresa_id);
CREATE INDEX idx_contatos_nome ON contatos (nome);
CREATE INDEX idx_contatos_email ON contatos (email);

------------------------------------------------------------------------------
-- Negócios (SPEC §4.3)
------------------------------------------------------------------------------

CREATE TABLE negocios (
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
    temperatura           TEXT CHECK (temperatura IS NULL OR temperatura IN ('frio', 'morno', 'quente')),
    prioridade            TEXT CHECK (prioridade IS NULL OR prioridade IN ('baixa', 'media', 'alta')),
    dor_principal         TEXT,
    objetivo_cliente      TEXT,
    orcamento_cliente     TEXT,
    prazo_desejado        TEXT,
    criterio_decisao      TEXT,
    concorrentes          TEXT,
    -- Andamento
    proximo_passo         TEXT,
    proximo_passo_em      TEXT,
    motivo_perda_id       INTEGER REFERENCES motivos_perda (id),
    detalhe_perda         TEXT,
    -- Aquisição (formulario_id/submissao_id passam a ter FK na Fase 8)
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
CREATE INDEX idx_negocios_empresa ON negocios (empresa_id);
CREATE INDEX idx_negocios_etapa ON negocios (etapa_id);
CREATE INDEX idx_negocios_status ON negocios (status);

CREATE TABLE negocio_contatos (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    negocio_id INTEGER NOT NULL REFERENCES negocios (id),
    contato_id INTEGER NOT NULL REFERENCES contatos (id),
    papel      TEXT,
    criado_em  TEXT NOT NULL,
    UNIQUE (negocio_id, contato_id)
);

------------------------------------------------------------------------------
-- Atividades e tarefas (SPEC §4.4, §4.5)
------------------------------------------------------------------------------

CREATE TABLE atividades (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo          TEXT NOT NULL CHECK (tipo IN
                  ('nota', 'ligacao', 'whatsapp', 'email', 'reuniao', 'visita', 'proposta', 'contrato', 'sistema')),
    assunto       TEXT,
    descricao     TEXT,
    data_hora     TEXT NOT NULL,
    duracao_min   INTEGER,
    direcao       TEXT CHECK (direcao IS NULL OR direcao IN ('entrada', 'saida')),
    resultado     TEXT,
    empresa_id    INTEGER REFERENCES empresas (id),
    contato_id    INTEGER REFERENCES contatos (id),
    negocio_id    INTEGER REFERENCES negocios (id),
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_atividades_empresa ON atividades (empresa_id, data_hora);
CREATE INDEX idx_atividades_contato ON atividades (contato_id, data_hora);
CREATE INDEX idx_atividades_negocio ON atividades (negocio_id, data_hora);

CREATE TABLE tarefas (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    titulo        TEXT NOT NULL,
    descricao     TEXT,
    tipo          TEXT NOT NULL DEFAULT 'outro' CHECK (tipo IN ('ligar', 'enviar', 'reuniao', 'followup', 'interno', 'outro')),
    prioridade    TEXT NOT NULL DEFAULT 'media' CHECK (prioridade IN ('baixa', 'media', 'alta', 'urgente')),
    status        TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'andamento', 'concluida', 'cancelada')),
    vencimento    TEXT,                      -- data ou data+hora
    lembrete_em   TEXT,
    concluida_em  TEXT,
    recorrencia   TEXT NOT NULL DEFAULT 'nenhuma' CHECK (recorrencia IN ('nenhuma', 'diaria', 'semanal', 'mensal', 'anual')),
    empresa_id    INTEGER REFERENCES empresas (id),
    contato_id    INTEGER REFERENCES contatos (id),
    negocio_id    INTEGER REFERENCES negocios (id),
    contrato_id   INTEGER,                   -- FK adicionada com a tabela contratos (Fase 3)
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_tarefas_status_venc ON tarefas (status, vencimento);

------------------------------------------------------------------------------
-- Anexos (SPEC §4.12)
------------------------------------------------------------------------------

CREATE TABLE anexos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    entidade      TEXT NOT NULL,
    registro_id   INTEGER,
    nome_original TEXT NOT NULL,
    caminho       TEXT NOT NULL,             -- nome aleatório dentro de /storage/uploads
    mime          TEXT NOT NULL,
    tamanho       INTEGER NOT NULL,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_anexos_registro ON anexos (entidade, registro_id);
