-- Ajuste de dados: cancela todas as cobranças pendentes, inclusive as emitidas no Asaas.
-- As emitidas foram geradas no ambiente sandbox e não existem de verdade; por isso o cancelamento é só local
-- (não chama a API do Asaas). Rodar de novo não altera nada.

CREATE TEMP TABLE _cancela_alvo AS
SELECT id, status AS antes FROM cobrancas WHERE status = 'pendente';

INSERT INTO log_auditoria (data, origem, entidade, registro_id, acao, antes, depois)
SELECT datetime('now', '-3 hours'), 'sistema', 'cobrancas', id, 'ajuste_importacao',
       json_object('status', antes), json_object('status', 'cancelado')
FROM _cancela_alvo;

UPDATE cobrancas
SET status = 'cancelado', atualizado_em = datetime('now', '-3 hours')
WHERE id IN (SELECT id FROM _cancela_alvo);

DROP TABLE _cancela_alvo;
