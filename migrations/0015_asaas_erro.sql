-- Fase 17 (correção): motivo da última falha de emissão no Asaas.
-- Antes, a falha só aparecia num toast que some em 5 s: quem clicava em "Emitir no Asaas"
-- via a tela recarregar igual e concluía que o botão não fazia nada. Agora o motivo fica
-- guardado na cobrança e visível na tela até a emissão dar certo.
ALTER TABLE cobrancas ADD COLUMN asaas_erro TEXT;
