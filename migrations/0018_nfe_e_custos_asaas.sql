-- Complemento do financeiro (fora do roteiro de fases, 2026-09-28): status de NF-e sincronizado pelo webhook
-- do Asaas e id do pagamento (payment) mais recente conhecido — necessário porque numa cobrança recorrente
-- `asaas_id` guarda o id da assinatura (subscription), e o webhook de nota fiscal referencia o pagamento do
-- ciclo (invoice.payment), não a assinatura. Ver docs/DECISOES.md.
ALTER TABLE cobrancas ADD COLUMN asaas_payment_id TEXT;
CREATE INDEX idx_cobrancas_asaas_payment ON cobrancas (asaas_payment_id) WHERE asaas_payment_id IS NOT NULL;
