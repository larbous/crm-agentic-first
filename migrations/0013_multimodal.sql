-- 0013 — Processamento multimodal e resumo de conversas (Fase 15).
--
-- 1) `mensagens`: transcrição de áudios recebidos (texto, intenção, sentimento) e o andamento dela. O áudio em si não é
--    guardado; só o texto. `transcricao_status` é NULL em mensagens que não são áudio.
-- 2) `conversas`: resumo automático ao resolver (texto, quando foi gerado e a fila do worker).

ALTER TABLE mensagens ADD COLUMN transcricao_status TEXT CHECK (transcricao_status IS NULL OR transcricao_status IN ('pendente', 'concluida', 'falhou'));
ALTER TABLE mensagens ADD COLUMN transcricao TEXT;
ALTER TABLE mensagens ADD COLUMN intencao TEXT;
ALTER TABLE mensagens ADD COLUMN sentimento TEXT CHECK (sentimento IS NULL OR sentimento IN ('positivo', 'neutro', 'negativo'));
ALTER TABLE mensagens ADD COLUMN transcricao_erro TEXT;
ALTER TABLE mensagens ADD COLUMN transcricao_tentativas INTEGER NOT NULL DEFAULT 0;
CREATE INDEX idx_mensagens_transcricao ON mensagens (transcricao_status) WHERE transcricao_status = 'pendente';

ALTER TABLE conversas ADD COLUMN resumo TEXT;
ALTER TABLE conversas ADD COLUMN resumo_em TEXT;
ALTER TABLE conversas ADD COLUMN resumo_pendente INTEGER NOT NULL DEFAULT 0;
ALTER TABLE conversas ADD COLUMN resumo_tentativas INTEGER NOT NULL DEFAULT 0;
CREATE INDEX idx_conversas_resumo ON conversas (resumo_pendente) WHERE resumo_pendente = 1;
