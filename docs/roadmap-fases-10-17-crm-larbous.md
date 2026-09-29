# CRM Lárbous — Roteiro de Fases 10 a 17

Continuação do roadmap original (fases 1-9, já concluídas e commitadas). As 9 melhorias identificadas numa análise comparativa com outras ferramentas do mercado foram agrupadas em 8 fases, sequenciadas por dependência técnica e custo/benefício — não pela ordem em que foram levantadas.

---

## Fase 10 — Infraestrutura de IA resiliente

**Por que primeiro:** toda fase seguinte adiciona mais chamadas de IA ao sistema (copiloto de WhatsApp, scoring, detecção de churn). Faz sentido endurecer a camada de IA antes de construir mais coisas em cima dela.

- **Arquitetura multi-provedor com failover**: operar com mais de um provedor de LLM (ex.: Anthropic + outro), trocando automaticamente se um estiver instável ou lento. Decisão pendente: qual(is) provedor(es) além do atual, e o critério exato de failover (timeout, taxa de erro).
- **Guardrails explícitos**: rate limits por agente/squad, whitelist de ações já existe (permissões), mas falta limiar de confiança (confidence threshold) documentado — abaixo de que confiança uma ação vai obrigatoriamente para a fila de aprovação, mesmo que o agente estivesse configurado para rodar sem aprovação.

**Não é uma fase visível ao operador** — é fundação técnica para as fases 11-17.

---

## Fase 11 — Lead scoring com temperatura

**Depende de:** nada novo (reaproveita o agente Qualificador de Lead já existente).

- Adicionar classificação estruturada de temperatura (fervendo / quente / morno / frio) ao processar um lead, usando metodologia BANT ou CHAMP.
- Campo de temperatura visível no card do negócio (kanban) e no filtro de listagem.
- Decisão pendente: BANT ou CHAMP, ou os dois configuráveis por pipeline.

---

## Fase 12 — NPS e pesquisa de satisfação

**Depende de:** construtor de formulários (já existe, Fase 5 do roadmap original).

- Disparo de pesquisa periódica de satisfação/NPS, reaproveitando o construtor de formulários existente.
- Tabulação automática das respostas.
- Gatilho de disparo: por evento (ex.: X dias após contrato assinado) ou por agenda — reaproveita a mesma infraestrutura de gatilhos de agentes/squads já existente.

---

## Fase 13 — Chamados e demandas operacionais

**Depende de:** nada novo, mas é um módulo com peso próprio (entidade nova).

- Nova entidade "Chamado", separada de Tarefa: abertura, atribuição por área/departamento (ex.: Tráfego Pago, Design, Social Media, Web, Redação), acompanhamento e fechamento.
- Checklists de execução vinculados ao chamado.
- Decisão pendente: chamados aparecem na mesma tela de Tarefas (hoje/atrasadas/próximas) ou em tela própria por área.

---

## Fase 14 — Caixa de entrada unificada

**Por que aqui:** é o maior esforço de integração externa do roteiro (três canais), e é pré-requisito das Fases 15 e 16.

- Conexão com WhatsApp Business Cloud API (oficial), e-mail e Instagram Direct, centralizados numa única tela de conversas.
- Toda mensagem recebida vira Atividade automática no registro correspondente (reaproveita a timeline já existente).
- Decisão pendente: ordem de implementação dos três canais (sugestão: WhatsApp primeiro, por ser o canal comercial mais usado no Brasil).

---

## Fase 15 — Processamento multimodal e follow-up automático

**Depende de:** Fase 14 (canais de comunicação já unificados).

- Transcrição automática de áudios recebidos, com extração de intenção e análise de sentimento.
- Cadências de follow-up: agente que acompanha periodicamente negócios frios ou sem resposta, dentro dos canais já conectados.
- Resumo automático de conversa ao final do atendimento, atualizando o histórico do registro.

---

## Fase 16 — Monitoramento de exceções e prevenção de churn

**Depende de:** Fase 14, para que "cliente sem interação há N dias" seja calculado com dados reais de todos os canais (hoje só teria dados de atividade manual).

- Estende a rotina de execução em segundo plano já existente (que hoje avisa sobre tarefas vencidas, propostas expiradas e contratos vencendo) para também detectar: cliente sem interação há N dias, chamado parado sem resposta.
- Notificação proativa ao operador com briefing do risco.
- Decisão pendente: limiar de dias sem interação que dispara o alerta (configurável por cliente/tipo de contrato, ou fixo).

---

## Fase 17 — Financeiro: cobranças, custos e DRE por cliente

**Maior escopo do roteiro** — decisões já fechadas na conversa:

- **Cobranças**: nova entidade, ligada a Asaas. Suporta avulsa (por proposta/entrega) e recorrente (assinatura/MRR ligada ao contrato). Status sincronizado via webhook do Asaas (pendente, pago, vencido, cancelado).
- **Mapeamento**: Empresa do Lárbous → Cliente no Asaas, criado na primeira cobrança.
- **Webhook Asaas**: primeira integração de entrada externa do sistema — endpoint autenticado, com tratamento de idempotência para não processar o mesmo evento duas vezes.
- **NF-e/NFS-e**: emitida via Asaas, acoplada à cobrança. Depende da configuração fiscal da Lárbous já estar correta no painel do Asaas (regime tributário, código de serviço, município) — isso é pré-requisito externo, não algo que o CRM configura. Atenção: a partir de 1º/set/2026 o Emissor Nacional de NFS-e passou a valer também para ME/EPP no Simples Nacional — confirmar que a conta Asaas já está migrada antes de programar em cima disso.
- **Custos por cliente**: lançamento manual (descrição, valor, data, categoria opcional, recorrente sim/não), vinculado a Negócio/Empresa. Não há apontamento automático de horas — decisão explícita para não inflar o escopo; se quiser custo automático por hora trabalhada, é uma frente futura separada.
- **DRE por cliente**: relatório calculado (não é tela de cadastro) — Receita (soma de cobranças pagas no período) − Custo (soma de lançamentos no período) = Margem, por cliente e por período.

---

## Resumo de dependências

```
Fase 10 (infra IA) ──> base para todas as fases seguintes
Fase 11 (scoring)  ──> independente
Fase 12 (NPS)      ──> independente
Fase 13 (chamados) ──> independente
Fase 14 (canais)   ──> pré-requisito de 15 e 16
Fase 15 (multimodal)   ──> depende de 14
Fase 16 (churn)    ──> depende de 14 (qualidade dos dados)
Fase 17 (financeiro) ──> independente das demais, mas maior escopo — pode rodar em paralelo se houver capacidade
```

