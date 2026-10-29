-- 0026 — Layout da proposta: modelo padrão do CRM (seções + itens) ou HTML/CSS personalizado colado pelo operador.
-- No layout personalizado o documento é o HTML informado (com variáveis {empresa.nome}, {proposta.itens}…),
-- exibido num iframe isolado (sem scripts) no link público e na impressão.

ALTER TABLE propostas ADD COLUMN layout TEXT NOT NULL DEFAULT 'padrao' CHECK (layout IN ('padrao', 'personalizado'));
ALTER TABLE propostas ADD COLUMN html_personalizado TEXT;
