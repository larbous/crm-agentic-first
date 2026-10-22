-- 0024 — Campos que os contratos usavam no Perfex como "campos personalizados" e agora viram colunas:
-- representante legal da contratante, desconto promocional e responsável pelo contrato na empresa.
-- Alimentam as variáveis {contrato.representante_nome}, {contrato.valor_promocional}, {empresa.responsavel_nome} etc.

ALTER TABLE contratos ADD COLUMN representante_nome TEXT;
ALTER TABLE contratos ADD COLUMN representante_cpf TEXT;
ALTER TABLE contratos ADD COLUMN representante_rg TEXT;
ALTER TABLE contratos ADD COLUMN valor_promocional INTEGER;       -- centavos
ALTER TABLE contratos ADD COLUMN percentual_desconto INTEGER;     -- centésimos de %
ALTER TABLE contratos ADD COLUMN periodo_desconto TEXT;

ALTER TABLE empresas ADD COLUMN responsavel_nome TEXT;
ALTER TABLE empresas ADD COLUMN responsavel_cpf TEXT;
ALTER TABLE empresas ADD COLUMN responsavel_rg TEXT;
