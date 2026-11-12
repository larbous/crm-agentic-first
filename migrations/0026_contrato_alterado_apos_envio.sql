-- 0026 — Contrato editado depois de enviado e antes de assinado: guarda quando o texto mudou pela última vez,
-- para avisar o cliente no link ("leia novamente antes de assinar").
ALTER TABLE contratos ADD COLUMN conteudo_alterado_em TEXT;
