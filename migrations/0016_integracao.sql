-- Integração de entrada com o Opensquad (squads de IA rodando fora do CRM).
-- Idempotência por evento: `chave` = squad|run|id do evento. Reenviar o mesmo lote não repete nada;
-- um evento que falhou (status 'erro') pode ser reenviado e é processado de novo. Não é entidade de negócio.
CREATE TABLE integracao_eventos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    chave         TEXT NOT NULL UNIQUE,
    squad         TEXT NOT NULL,
    run           TEXT NOT NULL,
    evento_id     TEXT NOT NULL,
    tipo          TEXT NOT NULL,
    status        TEXT NOT NULL CHECK (status IN ('ok', 'erro')),
    resposta      TEXT NOT NULL,
    recebido_em   TEXT NOT NULL,
    processado_em TEXT NOT NULL
);
CREATE INDEX idx_integracao_eventos_run ON integracao_eventos (squad, run);
