-- Assinatura do Asaas: uma cobrança por pagamento (ciclo), não mais uma linha por assinatura.
-- O Asaas gera cada ciclo 40 dias antes do vencimento, então dois ciclos ficam abertos ao mesmo tempo; com uma
-- linha só, o ciclo seguinte sobrescrevia o anterior (vencimento, link do boleto) e o pagamento de um ciclo
-- marcava o outro como pago. Agora várias linhas compartilham o asaas_id da assinatura, e o que é único é o
-- pagamento (asaas_payment_id). Numa cobrança avulsa, asaas_id continua único.

DROP INDEX IF EXISTS idx_cobrancas_asaas_id;
CREATE UNIQUE INDEX idx_cobrancas_asaas_id ON cobrancas (asaas_id) WHERE asaas_id IS NOT NULL AND asaas_tipo = 'payment';
CREATE INDEX idx_cobrancas_asaas_assinatura ON cobrancas (asaas_id) WHERE asaas_tipo = 'subscription';

DROP INDEX IF EXISTS idx_cobrancas_asaas_payment;
CREATE UNIQUE INDEX idx_cobrancas_asaas_payment ON cobrancas (asaas_payment_id) WHERE asaas_payment_id IS NOT NULL;
