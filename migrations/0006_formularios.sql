-- 0006 — Formulários de captação (SPEC §4.10).
-- Formulários e campos são configuração (como agentes e squads): sem log_auditoria. Formulário se arquiva, não se apaga.
-- formulario_submissoes é o registro do que chegou pela internet (nunca apagado). empresas/negocios.formulario_id e
-- submissao_id já existem desde a 0001 (sem FK: SQLite não adiciona FK a coluna existente; a integridade é do ActionExecutor).

CREATE TABLE formularios (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    nome              TEXT NOT NULL,
    chave             TEXT NOT NULL UNIQUE,          -- token público (32+ caracteres)
    titulo            TEXT,
    texto_botao       TEXT NOT NULL DEFAULT 'Enviar',
    mensagem_sucesso  TEXT NOT NULL DEFAULT 'Recebemos suas informações. Em breve entraremos em contato.',
    redirect_url      TEXT,
    origem_id_padrao  INTEGER REFERENCES origens (id),
    status_padrao     TEXT NOT NULL DEFAULT 'lead' CHECK (status_padrao IN ('lead', 'prospect')),
    criar_negocio     INTEGER NOT NULL DEFAULT 0 CHECK (criar_negocio IN (0, 1)),
    etapa_id_padrao   INTEGER REFERENCES etapas (id),
    squad_disparado   TEXT,                          -- slug do squad a enfileirar a cada envio
    regra_duplicado   TEXT NOT NULL DEFAULT 'tarefa' CHECK (regra_duplicado IN ('tarefa', 'mesclar', 'criar')),
    ativo             INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
    criado_em         TEXT NOT NULL,
    atualizado_em     TEXT NOT NULL,
    arquivado_em      TEXT
);

CREATE TABLE formulario_campos (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    formulario_id  INTEGER NOT NULL REFERENCES formularios (id),
    campo_destino  TEXT NOT NULL,                    -- empresa.x | contato.x | negocio.x | extra.x
    rotulo         TEXT NOT NULL,
    tipo           TEXT NOT NULL CHECK (tipo IN ('texto', 'email', 'telefone', 'textarea', 'select', 'checkbox', 'numero', 'data')),
    placeholder    TEXT,
    ajuda          TEXT,
    obrigatorio    INTEGER NOT NULL DEFAULT 0 CHECK (obrigatorio IN (0, 1)),
    opcoes         TEXT,                             -- JSON (lista de textos) para select
    largura        INTEGER NOT NULL DEFAULT 12 CHECK (largura BETWEEN 1 AND 12),
    ordem          INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_formulario_campos_form ON formulario_campos (formulario_id, ordem);

CREATE TABLE formulario_submissoes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    formulario_id  INTEGER NOT NULL REFERENCES formularios (id),
    dados          TEXT NOT NULL,                    -- JSON: [{rotulo, destino, valor}]
    empresa_id     INTEGER REFERENCES empresas (id),
    contato_id     INTEGER REFERENCES contatos (id),
    negocio_id     INTEGER REFERENCES negocios (id),
    status         TEXT NOT NULL CHECK (status IN ('processada', 'duplicada', 'spam')),
    ip             TEXT,
    user_agent     TEXT,
    pagina_origem  TEXT,
    referer        TEXT,
    utm_source     TEXT,
    utm_medium     TEXT,
    utm_campaign   TEXT,
    utm_term       TEXT,
    utm_content    TEXT,
    criado_em      TEXT NOT NULL
);
CREATE INDEX idx_submissoes_form ON formulario_submissoes (formulario_id, id DESC);
CREATE INDEX idx_submissoes_ip ON formulario_submissoes (ip, criado_em);
