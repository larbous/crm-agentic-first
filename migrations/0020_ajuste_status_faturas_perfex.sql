-- Ajuste de dados: corrige o status das faturas antigas importadas do Perfex.
-- A importação marcou como "vencido" faturas que, no Perfex, estavam pagas ou canceladas.
-- Casa pelo número da fatura na descrição ("Fatura Perfex #N" ou "Fatura Perfex #N (NFS-...)"), não por id,
-- porque os ids variam entre bancos. Só mexe em cobranças ainda "vencido"/"pendente", sem emissão no Asaas
-- e não arquivadas; rodar de novo não altera nada. data_pagamento fica como está (a fonte não informa).

CREATE TEMP TABLE _ajuste_status (numero INTEGER PRIMARY KEY, novo TEXT NOT NULL);
INSERT INTO _ajuste_status (numero, novo) VALUES
    (109, 'cancelado'), (106, 'cancelado'), (99, 'cancelado'), (97, 'cancelado'), (96, 'cancelado'),
    (95, 'pago'),       (94, 'cancelado'),  (91, 'pago'),      (90, 'cancelado'), (89, 'pago'),
    (88, 'cancelado'),  (85, 'cancelado'),  (84, 'cancelado'), (83, 'pago'),      (71, 'cancelado'),
    (69, 'pago'),       (66, 'pago'),       (68, 'pago'),      (65, 'pago'),      (67, 'pago'),
    (64, 'pago'),       (61, 'pago'),       (57, 'pago'),      (56, 'pago'),      (55, 'pago');

CREATE TEMP TABLE _ajuste_alvo AS
SELECT c.id AS id, c.status AS antes, a.novo AS depois
FROM cobrancas c
JOIN _ajuste_status a
  ON c.descricao = 'Fatura Perfex #' || a.numero
  OR c.descricao LIKE 'Fatura Perfex #' || a.numero || ' (%'
WHERE c.status IN ('vencido', 'pendente')
  AND c.asaas_id IS NULL
  AND c.arquivado_em IS NULL;

INSERT INTO log_auditoria (data, origem, entidade, registro_id, acao, antes, depois)
SELECT datetime('now', '-3 hours'), 'sistema', 'cobrancas', id, 'ajuste_importacao',
       json_object('status', antes), json_object('status', depois)
FROM _ajuste_alvo;

UPDATE cobrancas
SET status = (SELECT depois FROM _ajuste_alvo WHERE _ajuste_alvo.id = cobrancas.id),
    atualizado_em = datetime('now', '-3 hours')
WHERE id IN (SELECT id FROM _ajuste_alvo);

DROP TABLE _ajuste_alvo;
DROP TABLE _ajuste_status;
