-- 0014 — Financeiro: cobranças (Asaas), custos por cliente e DRE (Fase 17).
-- Cobrança é a entidade ligada ao Asaas (avulsa ou recorrente); o status é sincronizado pelo webhook.
-- Custo é lançamento manual, vinculado a empresa e/ou negócio. DRE é relatório calculado (não tem tabela própria).

ALTER TABLE empresas ADD COLUMN asaas_customer_id TEXT; -- criado na primeira cobrança da empresa (Empresa Lárbous → Cliente Asaas)
CREATE UNIQUE INDEX idx_empresas_asaas_customer ON empresas (asaas_customer_id) WHERE asaas_customer_id IS NOT NULL;

CREATE TABLE cobrancas (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa_id          INTEGER NOT NULL REFERENCES empresas (id),
    negocio_id          INTEGER REFERENCES negocios (id),
    contrato_id         INTEGER REFERENCES contratos (id),
    tipo                TEXT NOT NULL CHECK (tipo IN ('avulsa', 'recorrente')),
    descricao           TEXT NOT NULL,
    valor               INTEGER NOT NULL,                 -- centavos; para recorrente, valor de cada ciclo
    ciclo               TEXT CHECK (ciclo IN ('mensal', 'anual')), -- só para tipo = recorrente
    forma_pagamento     TEXT NOT NULL CHECK (forma_pagamento IN ('boleto', 'pix', 'cartao', 'indefinido')),
    vencimento          TEXT NOT NULL,                     -- data (primeiro vencimento, no caso recorrente)
    status              TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'pago', 'vencido', 'cancelado')),
    asaas_customer_id   TEXT,                              -- cópia do cliente Asaas usado nesta cobrança
    asaas_id            TEXT,                              -- id da cobrança (payment) ou assinatura (subscription) no Asaas
    asaas_tipo          TEXT CHECK (asaas_tipo IN ('payment', 'subscription')),
    url_fatura          TEXT,
    data_pagamento      TEXT,
    nfe_status          TEXT,                              -- situação da NF-e emitida via Asaas (texto livre vindo do webhook)
    nfe_url             TEXT,
    notas               TEXT,
    criado_em           TEXT NOT NULL,
    atualizado_em       TEXT NOT NULL,
    arquivado_em        TEXT,
    criado_por          TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_cobrancas_asaas_id ON cobrancas (asaas_id) WHERE asaas_id IS NOT NULL;
CREATE INDEX idx_cobrancas_empresa ON cobrancas (empresa_id);
CREATE INDEX idx_cobrancas_negocio ON cobrancas (negocio_id);
CREATE INDEX idx_cobrancas_status_venc ON cobrancas (status, vencimento);

CREATE TABLE custos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    empresa_id    INTEGER REFERENCES empresas (id),
    negocio_id    INTEGER REFERENCES negocios (id),
    descricao     TEXT NOT NULL,
    valor         INTEGER NOT NULL,                        -- centavos
    data          TEXT NOT NULL,
    categoria     TEXT,
    recorrente    INTEGER NOT NULL DEFAULT 0,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_custos_empresa ON custos (empresa_id);
CREATE INDEX idx_custos_negocio ON custos (negocio_id);
CREATE INDEX idx_custos_data ON custos (data);

-- Idempotência do webhook do Asaas: hash do corpo bruto (o Asaas não garante um id de evento estável em todos os planos).
CREATE TABLE asaas_webhooks (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    hash          TEXT NOT NULL UNIQUE,
    evento        TEXT NOT NULL,
    payload       TEXT NOT NULL,
    erro          TEXT,
    recebido_em   TEXT NOT NULL
);
