-- 0025 — Assinatura digital de contratos pelo link público: assinatura desenhada, e-mail, navegador e hash do texto
-- assinado (prova de integridade), mais os comentários trocados entre cliente e equipe no próprio contrato.

ALTER TABLE contratos ADD COLUMN assinatura_email TEXT;
ALTER TABLE contratos ADD COLUMN assinatura_imagem TEXT;          -- PNG desenhado pelo cliente (data URL)
ALTER TABLE contratos ADD COLUMN assinatura_hash TEXT;            -- SHA-256 do texto assinado
ALTER TABLE contratos ADD COLUMN assinatura_user_agent TEXT;

-- Conversa do contrato (não é entidade de negócio: sem soft delete). autor: cliente | equipe.
CREATE TABLE contrato_comentarios (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    contrato_id INTEGER NOT NULL REFERENCES contratos (id),
    autor       TEXT NOT NULL CHECK (autor IN ('cliente', 'equipe')),
    nome        TEXT NOT NULL,
    texto       TEXT NOT NULL,
    ip          TEXT,
    criado_em   TEXT NOT NULL
);
CREATE INDEX idx_contrato_comentarios ON contrato_comentarios (contrato_id, id);
