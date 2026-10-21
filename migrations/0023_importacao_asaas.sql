-- Asaas como fonte da verdade (2026-10-21): o CRM acompanha o que nasce e muda no painel do Asaas.
--
-- asaas_assinatura_status: situação da assinatura no Asaas (ativa, inativa, expirada, excluida). A linha de uma
-- cobrança recorrente guarda o status do ciclo mais recente; sem esta coluna, uma assinatura cancelada cujo último
-- ciclo foi pago continuaria parecendo "Pago" para sempre, sem nada que mostrasse que a recorrência acabou.
ALTER TABLE cobrancas ADD COLUMN asaas_assinatura_status TEXT;

-- Pagamentos/assinaturas que chegaram do Asaas e ainda não puderam virar cobrança: a empresa não foi encontrada
-- (cliente cadastrado no painel sem CNPJ/CPF casando com nenhuma empresa daqui) ou o Asaas não respondeu na hora.
-- Não é entidade de negócio (como asaas_webhooks): guarda o último payload e é retentada pelo worker, que a marca
-- como resolvida quando a importação dá certo. Uma linha por assinatura (sub:ID) ou por pagamento avulso (pay:ID).
CREATE TABLE asaas_pendencias (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    chave               TEXT NOT NULL UNIQUE,
    evento              TEXT NOT NULL,
    customer_id         TEXT NOT NULL,
    cliente_nome        TEXT,
    documento           TEXT,
    descricao           TEXT,
    valor               INTEGER,
    vencimento          TEXT,
    motivo              TEXT NOT NULL,
    payload             TEXT NOT NULL,
    tentativas          INTEGER NOT NULL DEFAULT 1,
    ultima_tentativa_em TEXT NOT NULL,
    criado_em           TEXT NOT NULL,
    resolvido_em        TEXT
);
CREATE INDEX idx_asaas_pendencias_abertas ON asaas_pendencias (resolvido_em, ultima_tentativa_em);
