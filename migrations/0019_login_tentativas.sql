-- Proteção contra força bruta no login: cada tentativa que falha grava uma linha (IP + e-mail tentado).
-- O bloqueio é calculado por contagem numa janela de tempo (ver Services/LoginLimite); linhas antigas são apagadas
-- de tempos em tempos. Não é entidade de negócio (sem auditoria/soft delete).
CREATE TABLE login_tentativas (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    ip        TEXT NOT NULL,
    email     TEXT NOT NULL,
    criado_em TEXT NOT NULL
);
CREATE INDEX idx_login_tentativas_ip ON login_tentativas (ip, criado_em);
CREATE INDEX idx_login_tentativas_email ON login_tentativas (email, criado_em);
