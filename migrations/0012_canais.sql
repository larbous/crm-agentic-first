-- @fk-off
-- 0012 — Caixa de entrada unificada (Fase 14): WhatsApp, Instagram Direct e e-mail numa tela de conversas.
--
-- 1) `atividades.tipo` ganha 'instagram' (o SQLite não altera CHECK: recria-se a tabela, com a marca "@fk-off" para o
--    runner desligar foreign_keys, conferir foreign_key_check e religar).
-- 2) `conversas`: uma por canal e pessoa (WhatsApp: número; Instagram: id do usuário; e-mail: endereço).
-- 3) `mensagens`: cada mensagem recebida ou enviada, com id externo único (idempotência dos webhooks).
-- As credenciais dos canais ficam em config.local.php, nunca no banco.

CREATE TABLE atividades_nova (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo          TEXT NOT NULL CHECK (tipo IN
                  ('nota', 'ligacao', 'whatsapp', 'email', 'instagram', 'reuniao', 'visita', 'proposta', 'contrato', 'sistema')),
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
INSERT INTO atividades_nova (id, tipo, assunto, descricao, data_hora, duracao_min, direcao, resultado, empresa_id, contato_id, negocio_id, criado_em, atualizado_em, arquivado_em, criado_por)
SELECT id, tipo, assunto, descricao, data_hora, duracao_min, direcao, resultado, empresa_id, contato_id, negocio_id, criado_em, atualizado_em, arquivado_em, criado_por FROM atividades;
DROP TABLE atividades;
ALTER TABLE atividades_nova RENAME TO atividades;
CREATE INDEX idx_atividades_empresa ON atividades (empresa_id, data_hora);
CREATE INDEX idx_atividades_contato ON atividades (contato_id, data_hora);
CREATE INDEX idx_atividades_negocio ON atividades (negocio_id, data_hora);

CREATE TABLE conversas (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    canal              TEXT NOT NULL CHECK (canal IN ('whatsapp', 'instagram', 'email')),
    identificador      TEXT NOT NULL,            -- whatsapp: dígitos com DDI; instagram: id do usuário; email: endereço em minúsculas
    nome               TEXT,                     -- nome informado pelo canal (perfil do WhatsApp, nome do remetente)
    contato_id         INTEGER REFERENCES contatos (id),
    empresa_id         INTEGER REFERENCES empresas (id),
    assunto            TEXT,                     -- e-mail: assunto da última mensagem
    status             TEXT NOT NULL DEFAULT 'aberta' CHECK (status IN ('aberta', 'resolvida')),
    nao_lidas          INTEGER NOT NULL DEFAULT 0,
    ultima_mensagem_em TEXT,
    ultima_entrada_em  TEXT,                     -- última mensagem do cliente (janela de 24h do WhatsApp e do Instagram)
    ultima_direcao     TEXT CHECK (ultima_direcao IS NULL OR ultima_direcao IN ('entrada', 'saida')),
    ultima_previa      TEXT,
    criado_em          TEXT NOT NULL,
    atualizado_em      TEXT NOT NULL,
    arquivado_em       TEXT,
    criado_por         TEXT NOT NULL DEFAULT 'sistema',
    UNIQUE (canal, identificador)
);
CREATE INDEX idx_conversas_lista ON conversas (status, ultima_mensagem_em);
CREATE INDEX idx_conversas_contato ON conversas (contato_id);
CREATE INDEX idx_conversas_empresa ON conversas (empresa_id);

CREATE TABLE mensagens (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    conversa_id INTEGER NOT NULL REFERENCES conversas (id),
    direcao     TEXT NOT NULL CHECK (direcao IN ('entrada', 'saida')),
    tipo        TEXT NOT NULL DEFAULT 'texto' CHECK (tipo IN ('texto', 'audio', 'imagem', 'video', 'documento', 'sticker', 'localizacao', 'outro')),
    texto       TEXT,
    midia       TEXT,                             -- JSON: {id, mime, nome} (o download e a transcrição são da Fase 15)
    id_externo  TEXT,                             -- id do provedor com prefixo do canal (wa:, ig:, em:); único
    status      TEXT NOT NULL DEFAULT 'recebida' CHECK (status IN ('recebida', 'enviando', 'enviada', 'entregue', 'lida', 'falhou')),
    erro        TEXT,
    data_hora   TEXT NOT NULL,
    atividade_id INTEGER REFERENCES atividades (id),
    criado_em   TEXT NOT NULL,
    criado_por  TEXT NOT NULL DEFAULT 'sistema'
);
CREATE INDEX idx_mensagens_conversa ON mensagens (conversa_id, data_hora);
CREATE UNIQUE INDEX idx_mensagens_externo ON mensagens (id_externo) WHERE id_externo IS NOT NULL;
