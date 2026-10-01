-- Ajuste de dados: marca como pagas todas as cobranças vencidas (histórico importado do Perfex).
-- Só o status muda; data_pagamento fica como está (a fonte não informa). Rodar de novo não altera nada.

CREATE TEMP TABLE _paga_alvo AS
SELECT id, status AS antes FROM cobrancas WHERE status = 'vencido';

INSERT INTO log_auditoria (data, origem, entidade, registro_id, acao, antes, depois)
SELECT datetime('now', '-3 hours'), 'sistema', 'cobrancas', id, 'ajuste_importacao',
       json_object('status', antes), json_object('status', 'pago')
FROM _paga_alvo;

UPDATE cobrancas
SET status = 'pago', atualizado_em = datetime('now', '-3 hours')
WHERE id IN (SELECT id FROM _paga_alvo);

DROP TABLE _paga_alvo;
