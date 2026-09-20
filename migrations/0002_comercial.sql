-- 0002 — Serviços, propostas, contratos e modelos de documento (SPEC §4.6–4.9).
-- Valores monetários em centavos; percentuais em centésimos de ponto percentual (7,5% = 750).

------------------------------------------------------------------------------
-- Catálogo de serviços (§4.6)
------------------------------------------------------------------------------

CREATE TABLE servicos (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    nome              TEXT NOT NULL,
    categoria         TEXT NOT NULL DEFAULT 'outro'
                      CHECK (categoria IN ('site', 'ecommerce', 'consultoria', 'manutencao', 'hospedagem', 'trafego', 'outro')),
    descricao         TEXT,
    entregaveis       TEXT,
    unidade           TEXT NOT NULL DEFAULT 'projeto' CHECK (unidade IN ('projeto', 'hora', 'mes', 'ano')),
    preco_base        INTEGER,                -- centavos
    preco_minimo      INTEGER,                -- centavos
    recorrente        INTEGER NOT NULL DEFAULT 0 CHECK (recorrente IN (0, 1)),
    prazo_padrao_dias INTEGER,
    ativo             INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
    criado_em         TEXT NOT NULL,
    atualizado_em     TEXT NOT NULL,
    arquivado_em      TEXT,
    criado_por        TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_servicos_categoria ON servicos (categoria);

------------------------------------------------------------------------------
-- Modelos de documento (§4.9) e tipos de contrato (§4.8)
------------------------------------------------------------------------------

CREATE TABLE modelos_documento (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo          TEXT NOT NULL CHECK (tipo IN ('contrato', 'proposta', 'email', 'whatsapp')),
    nome          TEXT NOT NULL,
    assunto       TEXT,
    conteudo      TEXT NOT NULL DEFAULT '',
    ativo         INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_modelos_tipo ON modelos_documento (tipo);

CREATE TABLE contrato_tipos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_contrato_tipos_nome ON contrato_tipos (nome) WHERE arquivado_em IS NULL;

------------------------------------------------------------------------------
-- Propostas (§4.7)
------------------------------------------------------------------------------

CREATE TABLE propostas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Básico
    negocio_id          INTEGER REFERENCES negocios (id),
    empresa_id          INTEGER REFERENCES empresas (id),
    contato_id          INTEGER REFERENCES contatos (id),
    numero              TEXT NOT NULL,           -- PROP-AAAA-NNNN (igual em todas as versões)
    versao              INTEGER NOT NULL DEFAULT 1,
    titulo              TEXT NOT NULL,
    modelo_id           INTEGER REFERENCES modelos_documento (id),
    status              TEXT NOT NULL DEFAULT 'rascunho'
                        CHECK (status IN ('rascunho', 'enviada', 'visualizada', 'aceita', 'recusada', 'expirada')),
    -- Datas
    data_emissao        TEXT,
    validade            TEXT,
    enviada_em          TEXT,
    visualizada_em      TEXT,
    respondida_em       TEXT,
    -- Valores (centavos)
    subtotal            INTEGER NOT NULL DEFAULT 0,
    desconto_tipo       TEXT NOT NULL DEFAULT 'valor' CHECK (desconto_tipo IN ('percentual', 'valor')),
    desconto_valor      INTEGER NOT NULL DEFAULT 0,  -- centavos, ou centésimos de % quando percentual
    total               INTEGER NOT NULL DEFAULT 0,
    total_recorrente    INTEGER NOT NULL DEFAULT 0,
    -- Condições
    forma_pagamento     TEXT,
    condicoes_pagamento TEXT,
    parcelas            INTEGER,
    entrada_percentual  INTEGER,
    prazo_entrega_dias  INTEGER,
    -- Conteúdo
    apresentacao        TEXT,
    escopo              TEXT,
    fora_escopo         TEXT,
    cronograma          TEXT,
    garantia            TEXT,
    observacoes         TEXT,
    -- Acesso público e aceite
    token_publico       TEXT NOT NULL UNIQUE,
    aceite_nome         TEXT,
    aceite_documento    TEXT,
    aceite_ip           TEXT,
    motivo_recusa       TEXT,
    criado_em           TEXT NOT NULL,
    atualizado_em       TEXT NOT NULL,
    arquivado_em        TEXT,
    criado_por          TEXT NOT NULL DEFAULT 'humano',
    UNIQUE (numero, versao)
);
CREATE INDEX idx_propostas_negocio ON propostas (negocio_id);
CREATE INDEX idx_propostas_empresa ON propostas (empresa_id);
CREATE INDEX idx_propostas_status ON propostas (status);

CREATE TABLE proposta_itens (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    proposta_id    INTEGER NOT NULL REFERENCES propostas (id),
    servico_id     INTEGER REFERENCES servicos (id),
    descricao      TEXT NOT NULL,
    quantidade     REAL NOT NULL DEFAULT 1,
    unidade        TEXT,
    valor_unitario INTEGER NOT NULL DEFAULT 0,   -- centavos
    desconto       INTEGER NOT NULL DEFAULT 0,   -- centavos
    total          INTEGER NOT NULL DEFAULT 0,   -- centavos: quantidade × valor_unitario − desconto
    recorrente     INTEGER NOT NULL DEFAULT 0 CHECK (recorrente IN (0, 1)),
    ordem          INTEGER NOT NULL DEFAULT 0,
    criado_em      TEXT NOT NULL,
    atualizado_em  TEXT NOT NULL
);
CREATE INDEX idx_proposta_itens_proposta ON proposta_itens (proposta_id, ordem);

------------------------------------------------------------------------------
-- Contratos (§4.8)
------------------------------------------------------------------------------

CREATE TABLE contratos (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    -- Básico
    numero                   TEXT NOT NULL UNIQUE,   -- CT-AAAA-NNNN
    titulo                   TEXT NOT NULL,
    tipo_id                  INTEGER REFERENCES contrato_tipos (id),
    empresa_id               INTEGER REFERENCES empresas (id),
    contato_id               INTEGER REFERENCES contatos (id),
    negocio_id               INTEGER REFERENCES negocios (id),
    proposta_id              INTEGER REFERENCES propostas (id),
    modelo_id                INTEGER REFERENCES modelos_documento (id),
    status                   TEXT NOT NULL DEFAULT 'rascunho'
                             CHECK (status IN ('rascunho', 'enviado', 'assinado', 'ativo', 'vencido', 'cancelado', 'renovado')),
    -- Valores
    valor_total              INTEGER,                -- centavos
    valor_mensal             INTEGER,                -- centavos
    recorrencia              TEXT NOT NULL DEFAULT 'unica' CHECK (recorrencia IN ('unica', 'mensal', 'anual')),
    dia_vencimento_pagamento INTEGER CHECK (dia_vencimento_pagamento IS NULL OR dia_vencimento_pagamento BETWEEN 1 AND 31),
    forma_pagamento          TEXT,
    -- Vigência
    data_inicio              TEXT,
    data_fim                 TEXT,
    renovacao_automatica     INTEGER NOT NULL DEFAULT 0 CHECK (renovacao_automatica IN (0, 1)),
    aviso_renovacao_dias     INTEGER NOT NULL DEFAULT 30,
    contrato_origem_id       INTEGER REFERENCES contratos (id),
    -- Reajuste e rescisão
    indice_reajuste          TEXT NOT NULL DEFAULT 'nenhum' CHECK (indice_reajuste IN ('ipca', 'igpm', 'fixo', 'nenhum')),
    percentual_reajuste      INTEGER,                -- centésimos de %
    data_proximo_reajuste    TEXT,
    multa_rescisoria         INTEGER,                -- centésimos de %
    aviso_previo_dias        INTEGER,
    -- Conteúdo
    conteudo                 TEXT,                   -- HTML (sanitizado)
    clausulas_especiais      TEXT,
    -- Aceite
    token_publico            TEXT NOT NULL UNIQUE,
    enviado_em               TEXT,
    visualizado_em           TEXT,
    assinado_em              TEXT,
    assinatura_nome          TEXT,
    assinatura_documento     TEXT,
    assinatura_ip            TEXT,
    -- Controle
    notas                    TEXT,
    campos_extras            TEXT,                   -- JSON
    criado_em                TEXT NOT NULL,
    atualizado_em            TEXT NOT NULL,
    arquivado_em             TEXT,
    criado_por               TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_contratos_empresa ON contratos (empresa_id);
CREATE INDEX idx_contratos_status ON contratos (status);
CREATE INDEX idx_contratos_fim ON contratos (data_fim);
