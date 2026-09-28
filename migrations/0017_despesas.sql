-- 0017 — Despesas da estrutura (contas a pagar do próprio negócio, sem vínculo com cliente) e categorias de despesa.
-- Complementa `custos` (0014), que é por cliente/negócio e alimenta o DRE por cliente. Valores em centavos; datas ISO.

CREATE TABLE categorias_despesa (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    criado_em     TEXT NOT NULL,
    atualizado_em TEXT NOT NULL,
    arquivado_em  TEXT,
    criado_por    TEXT NOT NULL DEFAULT 'humano'
);
CREATE UNIQUE INDEX idx_categorias_despesa_nome ON categorias_despesa (nome) WHERE arquivado_em IS NULL;

CREATE TABLE despesas (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    descricao       TEXT NOT NULL,
    categoria_id    INTEGER NOT NULL REFERENCES categorias_despesa (id),
    fornecedor      TEXT,
    valor           INTEGER NOT NULL,                 -- centavos
    vencimento      TEXT NOT NULL,                    -- data (para despesa já paga à vista, a própria data da compra)
    status          TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'pago', 'cancelado')),
    data_pagamento  TEXT,
    meio_pagamento  TEXT CHECK (meio_pagamento IS NULL OR meio_pagamento IN
                    ('dinheiro', 'pix', 'boleto', 'cartao_credito', 'cartao_debito', 'transferencia', 'debito_automatico', 'outro')),
    forma_pagamento TEXT NOT NULL DEFAULT 'a_vista' CHECK (forma_pagamento IN ('a_vista', 'parcelado', 'recorrente')),
    notas           TEXT,
    criado_em       TEXT NOT NULL,
    atualizado_em   TEXT NOT NULL,
    arquivado_em    TEXT,
    criado_por      TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_despesas_status_venc ON despesas (status, vencimento);
CREATE INDEX idx_despesas_categoria ON despesas (categoria_id);
CREATE INDEX idx_despesas_vencimento ON despesas (vencimento);

-- Categorias padrão para pequenas empresas (editáveis em Configurações). Semeadas aqui, e não no Semeador, para
-- também chegarem a instâncias já instaladas (o seed só roda na instalação). Horário de Brasília (UTC-3).
INSERT INTO categorias_despesa (nome, criado_em, atualizado_em, criado_por)
SELECT nome, strftime('%Y-%m-%d %H:%M:%S', 'now', '-3 hours'), strftime('%Y-%m-%d %H:%M:%S', 'now', '-3 hours'), 'sistema'
FROM (
    SELECT 'Aluguel e condomínio' AS nome UNION ALL
    SELECT 'Água, luz, internet e telefone' UNION ALL
    SELECT 'Salários e encargos' UNION ALL
    SELECT 'Pró-labore' UNION ALL
    SELECT 'Impostos e taxas' UNION ALL
    SELECT 'Contabilidade' UNION ALL
    SELECT 'Software e assinaturas' UNION ALL
    SELECT 'Marketing e publicidade' UNION ALL
    SELECT 'Fornecedores e materiais' UNION ALL
    SELECT 'Serviços terceirizados' UNION ALL
    SELECT 'Equipamentos e manutenção' UNION ALL
    SELECT 'Tarifas bancárias' UNION ALL
    SELECT 'Transporte e combustível' UNION ALL
    SELECT 'Outras despesas'
);
