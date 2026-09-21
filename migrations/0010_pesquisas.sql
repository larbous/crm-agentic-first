-- 0010 — Pesquisas de satisfação / NPS (Fase 12).
-- O construtor de formulários ganha o tipo 'pesquisa': o formulário define as perguntas e a configuração de disparo;
-- cada envio a um cliente é uma linha de `pesquisas`, com link individual (token) e a resposta tabulada nas colunas.
-- Só ADD COLUMN e tabela nova (nada é recriado).

ALTER TABLE formularios ADD COLUMN tipo TEXT NOT NULL DEFAULT 'captacao' CHECK (tipo IN ('captacao', 'pesquisa'));
-- Só para tipo 'pesquisa': quando criar pesquisas sozinho, com que frequência e por quanto tempo o link vale.
ALTER TABLE formularios ADD COLUMN gatilho_tipo TEXT NOT NULL DEFAULT 'manual' CHECK (gatilho_tipo IN ('manual', 'contrato_assinado', 'periodica'));
ALTER TABLE formularios ADD COLUMN gatilho_dias INTEGER;          -- contrato_assinado: dias depois da assinatura; periodica: intervalo em dias
ALTER TABLE formularios ADD COLUMN validade_dias INTEGER NOT NULL DEFAULT 30;
ALTER TABLE formularios ADD COLUMN tarefa_detrator INTEGER NOT NULL DEFAULT 1 CHECK (tarefa_detrator IN (0, 1));

CREATE TABLE pesquisas (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    formulario_id  INTEGER NOT NULL REFERENCES formularios (id),
    token          TEXT NOT NULL UNIQUE,                 -- link individual (/nps/{token}), 40 caracteres hex
    empresa_id     INTEGER NOT NULL REFERENCES empresas (id),
    contato_id     INTEGER REFERENCES contatos (id),
    contrato_id    INTEGER REFERENCES contratos (id),
    gatilho        TEXT NOT NULL DEFAULT 'manual' CHECK (gatilho IN ('manual', 'contrato_assinado', 'periodica')),
    status         TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'respondida', 'expirada', 'cancelada')),
    expira_em      TEXT NOT NULL,
    enviada_em     TEXT,                                 -- o operador marca quando entregou o link (a entrega automática vem com a Fase 14)
    respondida_em  TEXT,
    nota           INTEGER CHECK (nota IS NULL OR nota BETWEEN 0 AND 10),
    categoria      TEXT CHECK (categoria IS NULL OR categoria IN ('promotor', 'neutro', 'detrator')),
    comentario     TEXT,
    respostas      TEXT,                                 -- JSON: [{rotulo, valor}] das demais perguntas
    ip             TEXT,
    criado_em      TEXT NOT NULL,
    atualizado_em  TEXT NOT NULL,
    arquivado_em   TEXT,
    criado_por     TEXT NOT NULL DEFAULT 'humano'
);
CREATE INDEX idx_pesquisas_empresa ON pesquisas (empresa_id);
CREATE INDEX idx_pesquisas_form_status ON pesquisas (formulario_id, status);
CREATE INDEX idx_pesquisas_status_expira ON pesquisas (status, expira_em);
-- Uma pesquisa automática por contrato e por formulário (o worker roda a cada minuto e nunca duplica).
CREATE UNIQUE INDEX idx_pesquisas_contrato_unico ON pesquisas (formulario_id, contrato_id) WHERE contrato_id IS NOT NULL AND gatilho = 'contrato_assinado';
