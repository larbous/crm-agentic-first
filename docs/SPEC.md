# SPEC — CRM Lárbous

Versão 1.0 · CRM interno, agentic-first, 1 operador.

## 1. Conceito

- **Chat** é a interface principal: comandos diretos, linguagem natural e acionamento de agentes/squads.
- **Telas de CRUD** completas para edição humana (evitam chamadas à IA em alterações simples).
- **Agentes** (definidos em JSON, importáveis/exportáveis) executam tarefas específicas.
- **Squads** encadeiam agentes em processos, disparados manualmente, por evento ou por agenda.
- Toda escrita — humana, chat, agente, formulário — passa pelo **ActionExecutor**: validação, gravação, auditoria, eventos.

## 2. Arquitetura

```
Tela (CRUD humano) ─────────────► Controllers ─► ActionExecutor ─► Repositories ─► SQLite
                                                      │  ├─► Audit (log_auditoria)
Chat ─► Comando "/" (sem IA) ──────────────────────────┤  └─► Events ─► Squads/Agentes (fila)
    └─► CommandRouter (1 chamada IA → JSON) ───────────┤
                  └─► AgentRunner / SquadRunner ───────┘ (ou acoes_pendentes p/ aprovação)

Formulário público ─► FormController ─► ActionExecutor
cron (1/min) ─► worker.php ─► fila de execuções, agentes agendados, tarefas recorrentes, avisos
```

## 3. Chat

### 3.1 Modos de entrada

| Entrada | Exemplo | IA |
|---|---|---|
| Comando | `/nota Ana pediu prazo maior` | não |
| Agente direto | `@pesquisador Padaria Central` | só o agente |
| Squad direto | `#novo-lead Padaria Central` | só o squad |
| Linguagem natural | `cria negócio de 8 mil pro site da Padaria Central, contato Ana` | 1 chamada (Haiku) |

### 3.2 Comandos

| Comando | Efeito |
|---|---|
| `/nota <texto>` | Atividade tipo nota no registro em contexto |
| `/tarefa <texto> [dd/mm] [hh:mm]` | Tarefa vinculada ao registro em contexto |
| `/buscar <termo>` | Busca global (empresas, contatos, negócios) |
| `/concluir <id>` | Conclui tarefa |
| `/desfazer` | Reverte a última ação registrada no log |
| `/agentes` · `/squads` | Lista disponíveis |
| `/ajuda` | Lista comandos |

### 3.3 Contexto enviado ao roteador

```json
{
  "tela": {"entidade": "empresas", "id": 12, "nome": "Padaria Central"},
  "ultima_ref": {"entidade": "contatos", "id": 40, "nome": "Ana Souza"},
  "hoje": "2026-09-19",
  "mensagem": "..."
}
```
Nada de histórico de conversa.

### 3.4 Contrato de saída do roteador

A IA devolve **somente** um destes objetos:

```json
{"tipo":"acao","acao":"criar","entidade":"negocios",
 "dados":{"titulo":"Site Padaria Central","valor_estimado":8000},
 "ref":{"empresa":"Padaria Central","contato":"Ana"}}

{"tipo":"acao","acao":"consultar","entidade":"negocios",
 "filtro":[["etapa","=","Proposta"],["valor_estimado",">",5000]],
 "ordem":"previsao_fechamento asc","limite":20}

{"tipo":"agente","slug":"pesquisador","alvo":{"empresa":"Padaria Central"},"entrada":"texto livre opcional"}

{"tipo":"squad","slug":"novo-lead","alvo":{"empresa":"Padaria Central"}}

{"tipo":"indefinido","pergunta":"Qual negócio da Padaria Central?"}
```

- **Ações:** `criar`, `atualizar`, `arquivar`, `nota`, `tarefa`, `concluir`, `mover_etapa`, `consultar`, `desfazer`, `converter_cliente`.
- **Entidades graváveis pelo chat:** empresas, contatos, negocios, atividades, tarefas. **Consultáveis:** todas as de negócio.
- Valores monetários vêm em **reais** (decimal); o servidor converte para centavos.
- Datas relativas ("sexta", "semana que vem") são resolvidas pela IA para ISO usando `hoje`.
- `filtro` usa só campos da whitelist da entidade e operadores `= != > >= < <= contem entre vazio nao_vazio`.

### 3.5 Processamento no servidor

1. Validar JSON e whitelist. Inválido → "Não entendi. Tente reformular ou use /ajuda."
2. Resolver `ref` por busca (nome, nome fantasia, razão social, email, telefone). 0 resultados → oferecer criar; 2+ → botões de escolha.
3. `arquivar` e alterações que afetem mais de 1 registro → botão de confirmação.
4. Executar via ActionExecutor.
5. Responder com template: `✓ Negócio "Site Padaria Central" criado (R$ 8.000,00) → [abrir]`.
6. `consultar` → renderiza tabela no chat com links.
7. Gravar mensagem e resposta em `chat_mensagens` (só exibição).

### 3.6 UI do chat
- Página **Início** com chat central e painel lateral de chat em todas as telas (recolhível, atalho `Ctrl+K`).
- Na tela de detalhe, o registro aberto é o contexto automático.
- Mensagens de ação exibem link para o registro e botão **Desfazer**.

## 4. Modelo de dados

Colunas padrão omitidas nas tabelas abaixo (presentes em todas as tabelas de negócio): `id`, `criado_em`, `atualizado_em`, `arquivado_em`, `criado_por`. Valores `*_valor`/`valor_*`/`preco_*`/`total*` em **centavos**.

### 4.1 empresas

| Grupo | Campos |
|---|---|
| Identificação | razao_social, nome_fantasia (obrigatório), cnpj (único quando preenchido), inscricao_estadual, inscricao_municipal, porte (mei/me/epp/medio/grande), segmento, cnae_principal, data_fundacao, logo (anexo_id) |
| Contato | email_geral, telefone, whatsapp, site, instagram, facebook, linkedin, tiktok, youtube, google_meu_negocio |
| Endereço | cep, logradouro, numero, complemento, bairro, cidade, uf, pais (padrão "Brasil") |
| Comercial | status (lead/prospect/cliente/ex_cliente/inativo), origem_id, indicado_por, classificacao (A/B/C), faixa_faturamento, faixa_funcionarios, ticket_potencial, cliente_desde |
| Presença digital | dominio, registrador_dominio, vencimento_dominio, hospedagem_atual, plataforma_site, tem_ecommerce, plataforma_ecommerce, erp, ferramenta_email_marketing, usa_trafego_pago, observacoes_digitais |
| Aquisição | formulario_id, submissao_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content |
| Controle | notas, campos_extras (JSON) |

Calculados na exibição: `ltv` (soma de negócios ganhos), `mrr` (soma de contratos ativos recorrentes).

### 4.2 contatos

| Grupo | Campos |
|---|---|
| Pessoal | nome (obrigatório), sobrenome, apelido, cpf, data_nascimento, foto (anexo_id) |
| Profissional | empresa_id, cargo, departamento, papel_decisao (decisor/influenciador/financeiro/tecnico/usuario), nivel_hierarquico |
| Canais | email, email_secundario, telefone, whatsapp, linkedin, instagram, canal_preferido (whatsapp/email/telefone), melhor_horario |
| LGPD | opt_in_marketing, base_legal (consentimento/contrato/legitimo_interesse), data_consentimento, origem_consentimento |
| Relacionamento | status (ativo/inativo), origem_id, ultimo_contato_em, proximo_contato_em, nivel_relacionamento (1–5), interesses |
| Controle | notas, campos_extras |

`ultimo_contato_em` é atualizado automaticamente ao registrar atividade de contato (ligação, whatsapp, email, reunião, visita).

### 4.3 negocios

| Grupo | Campos |
|---|---|
| Básico | titulo (obrigatório), codigo (NEG-AAAA-NNNN, automático), empresa_id, contato_principal_id, decisor_id, pipeline_id, etapa_id, status (aberto/ganho/perdido/pausado) |
| Valores | valor_estimado, valor_fechado, tipo_receita (unico/mensal/anual), valor_recorrente, probabilidade (0–100, padrão da etapa) |
| Datas | previsao_fechamento, data_fechamento, entrou_etapa_em |
| Qualificação | temperatura (frio/morno/quente/fervendo), prioridade (baixa/media/alta), dor_principal, objetivo_cliente, orcamento_cliente, prazo_desejado, criterio_decisao, concorrentes; CHAMP: champ_desafios, champ_autoridade, champ_dinheiro, champ_prioridade (confirmado/parcial/nao_identificado), champ_resumo, champ_pontos (0–8, calculado), champ_avaliado_em (Fase 11) |
| Andamento | proximo_passo, proximo_passo_em, motivo_perda_id, detalhe_perda |
| Aquisição | origem_id, formulario_id, submissao_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content |
| Controle | notas, campos_extras |

Calculados: `valor_ponderado = valor_estimado × probabilidade`, `dias_na_etapa`.
Regras: mover para etapa tipo `ganho` → status ganho, data_fechamento = hoje, pede valor_fechado; tipo `perdido` → pede motivo_perda.

**negocio_contatos:** negocio_id, contato_id, papel.

### 4.4 atividades

tipo (nota/ligacao/whatsapp/email/reuniao/visita/proposta/contrato/sistema), assunto, descricao, data_hora, duracao_min, direcao (entrada/saida), resultado, empresa_id, contato_id, negocio_id, anexos (via tabela anexos).

Atividades `sistema` são geradas automaticamente (mudança de etapa, proposta enviada, contrato assinado etc.) e formam a timeline.

### 4.5 tarefas

titulo, descricao, tipo (ligar/enviar/reuniao/followup/interno/outro), prioridade (baixa/media/alta/urgente), status (pendente/andamento/concluida/cancelada), vencimento (data+hora opcional), lembrete_em, concluida_em, recorrencia (nenhuma/diaria/semanal/mensal/anual), empresa_id, contato_id, negocio_id, contrato_id.

Recorrência: ao concluir, o worker cria a próxima ocorrência.

### 4.5b chamados e areas (Fase 13)

- **areas:** nome (lista configurável em Configurações → Áreas; padrão: Tráfego Pago, Design, Social Media, Web, Redação).
- **chamados:** codigo (`CH-AAAA-NNNN`), titulo, descricao, area_id (obrigatória), prioridade (baixa/media/alta/urgente), status (aberto/andamento/aguardando/concluido/cancelado), vencimento (prazo de entrega, data + hora opcional), resolucao, concluido_em, empresa_id, contato_id, negocio_id, contrato_id, checklist (JSON `[{texto, feito}]`, até 50 itens).
- Chamado é a **demanda de execução** da agência ("criar a campanha", "arte do post"); tarefa é o que o operador precisa fazer. Não há responsável individual: a atribuição é por área (um operador). Sem recorrência.

### 4.5c conversas e mensagens (Fase 14)

- **conversas:** canal (`whatsapp` | `instagram` | `email`), identificador (número com DDI, id do usuário do Instagram ou e-mail; único por canal), nome, contato_id, empresa_id, assunto (e-mail), status (aberta/resolvida), nao_lidas, ultima_mensagem_em, ultima_entrada_em, ultima_direcao, ultima_previa.
- **mensagens:** conversa_id, direcao (entrada/saida), tipo (texto/audio/imagem/video/documento/sticker/localizacao/outro), texto, midia (JSON com o id do provedor), id_externo (único, prefixo `wa:`/`ig:`/`em:`), status (recebida/enviando/enviada/entregue/lida/falhou), erro, data_hora, atividade_id.
- Toda mensagem de uma conversa ligada a um contato ou empresa vira uma **atividade** (`whatsapp`, `email` ou `instagram`) na timeline. As credenciais dos canais ficam em `config.local.php` (`canais.*`), nunca no banco.

### 4.6 servicos

nome, categoria (site/ecommerce/consultoria/manutencao/hospedagem/trafego/outro), descricao, entregaveis, unidade (projeto/hora/mes/ano), preco_base, preco_minimo, recorrente, prazo_padrao_dias, ativo.

### 4.7 propostas

| Grupo | Campos |
|---|---|
| Básico | negocio_id, empresa_id, contato_id, numero (PROP-AAAA-NNNN), versao, titulo, modelo_id, status (rascunho/enviada/visualizada/aceita/recusada/expirada) |
| Datas | data_emissao, validade, enviada_em, visualizada_em, respondida_em |
| Valores | subtotal, desconto_tipo (percentual/valor), desconto_valor, total, total_recorrente |
| Condições | forma_pagamento, condicoes_pagamento, parcelas, entrada_percentual, prazo_entrega_dias |
| Conteúdo | apresentacao, escopo, fora_escopo, cronograma, garantia, observacoes |
| Acesso | token_publico, aceite_nome, aceite_documento, aceite_ip, motivo_recusa |

**proposta_itens:** proposta_id, servico_id, descricao, quantidade, unidade, valor_unitario, desconto, total, recorrente, ordem.

Nova versão = cópia com `versao+1`; a anterior fica `recusada` ou mantida como histórico. Aceite público → proposta `aceita`, evento `proposta.aceita`.

### 4.8 contratos

| Grupo | Campos |
|---|---|
| Básico | numero (CT-AAAA-NNNN), titulo, tipo_id, empresa_id, contato_id, negocio_id, proposta_id, modelo_id, status (rascunho/enviado/assinado/ativo/vencido/cancelado/renovado) |
| Valores | valor_total, valor_mensal, recorrencia (unica/mensal/anual), dia_vencimento_pagamento, forma_pagamento |
| Vigência | data_inicio, data_fim, renovacao_automatica, aviso_renovacao_dias (padrão 30), contrato_origem_id |
| Reajuste/rescisão | indice_reajuste (ipca/igpm/fixo/nenhum), percentual_reajuste, data_proximo_reajuste, multa_rescisoria, aviso_previo_dias |
| Conteúdo | conteudo (HTML), clausulas_especiais |
| Aceite | token_publico, enviado_em, visualizado_em, assinado_em, assinatura_nome, assinatura_documento, assinatura_ip |
| Controle | notas, campos_extras |

**contrato_tipos:** nome (Desenvolvimento, Manutenção, Hospedagem, Consultoria, Tráfego).
Renovar = novo contrato com `contrato_origem_id`, original → `renovado`. Worker marca `vencido` após `data_fim` e dispara `contrato.vencendo` quando faltarem `aviso_renovacao_dias`.

### 4.9 modelos_documento

tipo (contrato/proposta/email/whatsapp), nome, assunto, conteudo, ativo.
Variáveis `{entidade.campo}` (ex.: `{empresa.nome_fantasia}`, `{contato.nome}`, `{negocio.valor_fechado}`, `{contrato.data_fim}`, `{hoje}`, `{proposta.itens}`). Valores monetários e datas formatados em pt-BR na substituição.

### 4.10 formularios

| Tabela | Campos |
|---|---|
| **formularios** | nome, chave (token), titulo, texto_botao, mensagem_sucesso, redirect_url, origem_id_padrao, status_padrao, criar_negocio, etapa_id_padrao, squad_disparado, regra_duplicado (tarefa/mesclar/criar), ativo |
| **formulario_campos** | formulario_id, campo_destino (`empresa.x`, `contato.x`, `negocio.x`, `extra.x`), rotulo, tipo (texto/email/telefone/textarea/select/checkbox/numero/data), placeholder, ajuda, obrigatorio, opcoes (JSON), largura (1–12), ordem |
| **formulario_submissoes** | formulario_id, dados (JSON), empresa_id, contato_id, negocio_id, status (processada/duplicada/spam), ip, user_agent, pagina_origem, referer, utm_source, utm_medium, utm_campaign, utm_term, utm_content |

- Endpoints públicos: `/f/{chave}` (página estilizada) e `/f/{chave}?embed=1` (iframe). Script JS de incorporação opcional que repassa UTMs da página-mãe.
- Duplicado: compara email → whatsapp → cnpj → domínio do site. Regra `tarefa` cria tarefa com os dados; `mesclar` preenche só campos vazios; `criar` cria sempre.
- Anti-spam: honeypot + limite de 5 envios/IP/hora.

### 4.11 metas

tipo (faturamento/mrr/novos_clientes/propostas_enviadas/negocios_ganhos), periodo (mensal/trimestral/anual), valor_alvo, data_inicio, data_fim. Progresso calculado em tempo real.

### 4.12 Tabelas de apoio

- **pipelines:** nome, padrao.
- **etapas:** pipeline_id, nome, ordem, probabilidade_padrao, cor, tipo (aberta/ganho/perdido).
  Padrão: Novo lead (10) → Qualificado (25) → Reunião (40) → Proposta (60) → Negociação (80) → Ganho (100) · Perdido (0).
- **origens:** nome. Padrão: Indicação, Instagram, Google, Site, Formulário, WhatsApp, Evento, Prospecção ativa, Outro.
- **motivos_perda:** nome. Padrão: Preço, Prazo, Escolheu concorrente, Sem orçamento, Sem resposta, Projeto adiado, Outro.
- **tags:** nome, cor · **taggables:** tag_id, entidade, registro_id.
- **anexos:** entidade, registro_id, nome_original, caminho, mime, tamanho.
- **campos_extras_def:** entidade, chave, rotulo, tipo (texto/numero/data/select/checkbox/url/textarea), opcoes, obrigatorio, ordem, ativo.

### 4.13 Tabelas de sistema

- **usuarios:** nome, email, senha_hash, ultimo_login_em.
- **configuracoes:** chave, valor (modelos padrão, limites de tokens, dados da Lárbous para documentos, fuso).
- **log_auditoria:** data, origem (humano/ia/agente:slug/formulario:id/sistema), entidade, registro_id, acao, antes (JSON), depois (JSON), execucao_id, desfeito_em.
- **chat_mensagens:** papel (operador/sistema), conteudo, payload (JSON da ação/resultado), contexto (JSON).
- **agentes:** slug, nome, descricao, definicao (JSON completo), versao, ativo.
- **squads:** slug, nome, descricao, definicao (JSON), versao, ativo.
- **execucoes:** agente_id, squad_id, squad_execucao_id, etapa_ordem, entidade, registro_id, entrada, saida, status (fila/rodando/concluida/erro/aguardando_aprovacao/cancelada), modelo, tokens_entrada, tokens_saida, duracao_ms, erro, iniciado_em, concluido_em.
- **acoes_pendentes:** execucao_id, acao (JSON no mesmo formato do roteador), resumo, status (pendente/aprovada/rejeitada), decidido_em.
- **agendamentos_execucao:** agente_id/squad_id, cron, ultimo_run_em, proximo_run_em.

### 4.10b Pesquisas NPS (Fase 12)

- **formularios.tipo** (`captacao` | `pesquisa`; imutável) e, só para pesquisa: `gatilho_tipo` (`manual` | `contrato_assinado` | `periodica`), `gatilho_dias`, `validade_dias` (padrão 30), `tarefa_detrator`. Destinos de campo da pesquisa: `pesquisa.nota` (escala 0–10, obrigatória), `pesquisa.comentario` e `pergunta.1` a `pergunta.8` (perguntas extras).
- **pesquisas:** formulario_id, token (40 hex; link individual `/nps/{token}`), empresa_id, contato_id, contrato_id, gatilho, status (pendente/respondida/expirada/cancelada), expira_em, enviada_em, respondida_em, nota (0–10), categoria (promotor 9–10 / neutro 7–8 / detrator 0–6), comentario, respostas (JSON), ip + campos padrão. Índice único (formulario_id, contrato_id) para o gatilho de contrato.
- **NPS** = % promotores − % detratores (−100 a 100). Tela `/pesquisas`: índice, distribuição das notas, tendência mensal, taxa de resposta, fila de envio e respostas.


## 5. Eventos

`Events::disparar(nome, payload)`; gatilhos de agentes/squads assinam eventos.

`empresa.criada` · `empresa.atualizada` · `empresa.convertida` · `contato.criado` · `negocio.criado` · `negocio.etapa_mudou` · `negocio.ganho` · `negocio.perdido` · `atividade.criada` · `tarefa.vencida` · `proposta.enviada` · `proposta.visualizada` · `proposta.aceita` · `proposta.recusada` · `pesquisa.criada` · `pesquisa.respondida` · `mensagem.recebida` · `contrato.assinado` · `contrato.vencendo` · `contrato.vencido` · `formulario.submetido`

Eventos disparados por agentes não re-disparam o mesmo agente (proteção contra loop via `origem`).

## 6. Agentes

### 6.1 Formato `.agent.json`

```json
{
  "slug": "pesquisador",
  "nome": "Pesquisador de Empresa",
  "descricao": "Levanta presença digital e dados públicos de uma empresa",
  "versao": 1,
  "modelo": "claude-haiku-4-5-20251001",
  "max_tokens": 800,
  "web_search": true,
  "entrada": "empresas",
  "contexto": ["nome_fantasia", "razao_social", "site", "instagram", "cidade", "uf", "segmento"],
  "contexto_relacionado": {"atividades": 5},
  "prompt": "Você pesquisa empresas brasileiras... Responda só com JSON no formato {\"acoes\": [...], \"resumo\": \"...\"}.",
  "acoes_permitidas": ["atualizar", "nota", "tarefa"],
  "campos_gravaveis": ["site", "instagram", "plataforma_site", "tem_ecommerce", "segmento", "observacoes_digitais"],
  "aprovacao": "escritas",
  "gatilho": {"tipo": "manual"}
}
```

- `entrada`: entidade-alvo (`empresas`, `contatos`, `negocios`, `propostas`, `contratos`, `nenhuma`).
- `contexto`: únicos campos enviados à IA. `contexto_relacionado`: últimas N atividades/tarefas.
- `campos_gravaveis`: whitelist de campos que o agente pode alterar (validada pelo servidor).
- Saída do agente: `{"acoes": [<ações no formato do roteador>], "resumo": "texto curto", "texto": "saída longa opcional"}`. `texto` vira atividade tipo nota (rascunhos, relatórios).
- `aprovacao`: `sempre` | `escritas` | `nunca`.
- `gatilho`: `{"tipo":"manual"}` | `{"tipo":"evento","evento":"negocio.ganho"}` | `{"tipo":"agendado","cron":"0 8 * * 1"}`.
- Import valida o JSON e o slug; slug existente → nova versão (a anterior é mantida em histórico).

### 6.2 Biblioteca inicial (`/library`)

| Slug | Modelo | Função |
|---|---|---|
| pesquisador | Haiku + web | Presença digital e dados públicos → atualiza empresa + nota |
| qualificador | Haiku | Classificação A/B/C e qualificação CHAMP do negócio (a temperatura é calculada pelo servidor), dor provável → atualiza empresa/negócio |
| triagem-formulario | Haiku | Classifica submissão (lead/spam/suporte), extrai dor e orçamento de campos livres |
| redator-followup | Sonnet | Rascunho de WhatsApp/email a partir do modelo e do histórico → nota |
| resumidor-reuniao | Haiku | Notas/transcrição → atividade reunião + tarefas + qualificação do negócio |
| montador-proposta | Sonnet | Itens (do catálogo) e escopo da proposta a partir do negócio |
| redator-contrato | Sonnet | Preenche modelo de contrato a partir de negócio + proposta aceita |
| renovacao | Haiku | Contrato vencendo → tarefa + rascunho de proposta de renovação/reajuste |
| analista-pipeline | Sonnet | Negócios parados, previsões vencidas, tarefas atrasadas, metas → relatório + tarefas |

## 7. Squads

### 7.1 Formato `.squad.json`

```json
{
  "slug": "novo-lead",
  "nome": "Entrada de Novo Lead",
  "versao": 1,
  "entrada": "empresas",
  "etapas": [
    {"agente": "triagem-formulario", "condicao": "origem == formulario"},
    {"agente": "pesquisador"},
    {"agente": "qualificador", "usa_saida_de": ["pesquisador"]},
    {"agente": "redator-followup", "condicao": "empresa.classificacao in [A,B]"}
  ],
  "parar_se": "triagem-formulario.status == spam",
  "gatilho": {"tipo": "evento", "evento": "empresa.criada"}
}
```

- Execução sequencial pelo worker; cada etapa é uma linha em `execucoes`.
- `condicao` e `parar_se`: expressões simples avaliadas pelo servidor (`==`, `!=`, `in`, `>`, `<`), nunca pela IA.
- `usa_saida_de`: inclui o `resumo` das etapas indicadas no contexto.
- Etapa com ação pendente de aprovação pausa o squad até decisão.

### 7.2 Biblioteca inicial

| Slug | Gatilho | Etapas |
|---|---|---|
| novo-lead | empresa.criada / formulario.submetido | triagem-formulario → pesquisador → qualificador → redator-followup |
| pos-reuniao | manual | resumidor-reuniao → montador-proposta → tarefa de follow-up |
| negocio-ganho | negocio.ganho | redator-contrato → tarefa "enviar contrato" |
| contrato-assinado | contrato.assinado | converte empresa em cliente → tarefa de onboarding |
| revisao-semanal | agendado seg 08:00 | analista-pipeline |
| renovacoes | contrato.vencendo | renovacao |

## 8. Ações rápidas de IA

Botões em campos de texto longos e na timeline, cada um = 1 chamada Haiku, resultado mostrado para aceitar/descartar:
**Melhorar texto** · **Tom formal** · **Tom amigável** · **Resumir histórico** (últimas 20 atividades do registro) · **Sugerir resposta** (última atividade de entrada).

## 9. Telas

| Tela | Conteúdo |
|---|---|
| Início | Chat; tarefas de hoje/atrasadas; pipeline por etapa (valor e ponderado); negócios parados (>14 dias na etapa); contratos vencendo; ações pendentes; metas |
| Empresas | Lista (busca, filtros por status/classificação/origem/tag, colunas configuráveis, ordenação, paginação); formulário em abas (Dados, Contato, Endereço, Digital, Aquisição, Extras); detalhe com timeline, contatos, negócios, propostas, contratos, tarefas, anexos e botões de agentes/squads |
| Contatos | Idem |
| Negócios | Lista + kanban por etapa com arrastar (mudança passa pelo ActionExecutor, com modais de ganho/perda); detalhe com propostas, contatos, timeline |
| Propostas | Lista; editor com itens do catálogo e totais em tempo real; visualização de impressão; link público com aceite/recusa |
| Contratos | Lista (filtros por status/tipo/vencimento); editor a partir de modelo; impressão; link público com assinatura; botão Renovar |
| Caixa de entrada | Conversas de WhatsApp, Instagram e e-mail numa tela (filtros por canal, situação, não lidas e busca); conversa com resposta pelo próprio canal, vínculo a contato, resolver/reabrir. Webhook da Meta em `/webhooks/meta`; e-mail por IMAP/SMTP |
| Tarefas e chamados | Hoje / atrasadas / próximos 7 dias / todas, com tarefas e chamados juntos (filtro por tipo e por área); conclusão rápida. Chamados também têm lista (filtros por área/status/prioridade), formulário com checklist e detalhe (andamento, checklist, anexos, histórico) |
| Serviços | Catálogo |
| Modelos | Editor com lista de variáveis e pré-visualização com registro de exemplo |
| Formulários | Lista; construtor (arrastar campos, largura, obrigatório); código de incorporação; submissões |
| Metas | Cadastro e progresso |
| Agentes | Lista; editor JSON com validação; **Testar** (escolhe registro e roda em modo simulação); importar/exportar |
| Squads | Editor de etapas; importar/exportar; execuções |
| Execuções | Histórico, status, tokens, erros; totais de tokens do mês por modelo |
| Ações pendentes | Aprovar/rejeitar individual ou em lote, com diff |
| Auditoria | Log filtrável com botão Desfazer |
| Configurações | Pipelines/etapas, origens, motivos de perda, tags, tipos de contrato, áreas, campos extras, dados da empresa, modelos de IA e limites, usuário |

**Busca global** no topo (atalho `/`): empresas, contatos, negócios, propostas, contratos.

**Auto-preenchimento:** CEP via ViaCEP e CNPJ via BrasilAPI no formulário de empresa (chamada no navegador).

**Visual:** interface limpa, densa o suficiente para listas, modo claro/escuro por `prefers-color-scheme`, responsiva (uso ocasional no celular).

## 10. Desfazer

`/desfazer` ou botão: aplica o `antes` do último registro de `log_auditoria` não desfeito (criar → arquivar; atualizar → restaurar valores; arquivar → limpar `arquivado_em`) e marca `desfeito_em`. O próprio desfazer é auditado.

## 11. Worker (`cron/worker.php`, 1×/min)

1. Processa `execucoes` em `fila` (limite por rodada para caber no timeout da hospedagem).
2. Dispara agendamentos vencidos.
3. Cria próximas ocorrências de tarefas recorrentes.
4. Marca tarefas vencidas (`tarefa.vencida`), propostas expiradas, contratos vencendo/vencidos.
5. Pesquisas NPS: cria as pesquisas dos gatilhos (`contrato_assinado`, `periodica`), com uma tarefa "Enviar pesquisa", e expira os links vencidos.
6. Caixa de entrada: coleta os e-mails novos da caixa IMAP (a cada 2 minutos, até 20 por rodada).
Usa lock de arquivo para não rodar em paralelo.

## 12. Interface (shadcn/ui via Basecoat)

O visual segue o design system do **shadcn/ui**. Como o shadcn/ui original é composto de componentes React, a implementação usa o **Basecoat**: porte do shadcn/ui para HTML + Tailwind CSS + JavaScript vanilla, compatível com os temas do shadcn/ui. Sem React, sem runtime de framework.

### 12.1 Ferramentas

| Item | Definição |
|---|---|
| Componentes | Basecoat (MIT), arquivos-fonte versionados em `/assets-src/vendor/basecoat/` (CSS) e `/public/assets/vendor/basecoat/` (JS dos componentes interativos). Seguir a instalação da versão atual em basecoatui.com |
| CSS | Tailwind CSS v4 compilado com o **executável standalone** do Tailwind (não requer Node) |
| Ícones | Lucide (mesmo conjunto do shadcn/ui), sprite SVG em `/public/assets/vendor/lucide/sprite.svg` |
| Fonte | Inter (woff2) em `/public/assets/vendor/inter/` |
| Tema | Variáveis de tema do shadcn/ui |

### 12.2 Build do CSS

```
/assets-src/app.css           # entrada: @import "tailwindcss"; Basecoat; tema; componentes próprios
/assets-src/theme.css         # variáveis do tema shadcn (claro e .dark)
/assets-src/components/*.css  # componentes que não existem no Basecoat
/public/assets/css/app.css    # SAÍDA compilada e minificada — versionada no Git
```

- Dev: `./tailwindcss -i assets-src/app.css -o public/assets/css/app.css --watch`
- Produção: mesmo comando com `--minify`, antes do commit.
- O executável do Tailwind **não** é versionado (`.gitignore`); o download fica documentado em `docs/INSTALACAO.md`.
- O servidor de hospedagem nunca executa build: recebe o CSS pronto.
- Tailwind detecta classes em `app/Views/**/*.php` e `public/assets/js/**/*.js` (`@source`).

### 12.3 Tema

Variáveis padrão do shadcn/ui em `theme.css`: `--background`, `--foreground`, `--card`, `--card-foreground`, `--popover`, `--popover-foreground`, `--primary`, `--primary-foreground`, `--secondary`, `--secondary-foreground`, `--muted`, `--muted-foreground`, `--accent`, `--accent-foreground`, `--destructive`, `--border`, `--input`, `--ring`, `--radius`, `--chart-1`…`--chart-5`, `--sidebar-*`.

- Tema gerado no editor de temas do shadcn/ui ou compatível e colado em `theme.css`. `--primary` recebe a cor oficial da Lárbous (placeholder até definição).
- Variáveis adicionais do domínio, no mesmo padrão: `--success`, `--warning`, `--info` (+ `-foreground`), `--temp-frio`, `--temp-morno`, `--temp-quente`. Cor da etapa vem do banco via `style="--etapa: #hex"`.
- Modo escuro pela classe `.dark` no `<html>` (convenção shadcn); padrão segue `prefers-color-scheme`, com alternância salva nas configurações do usuário.
- Densidade: texto base 14px (`text-sm`), linhas de tabela compactas.

### 12.4 Componentes

**Do Basecoat** (usar as classes e a estrutura HTML documentadas por ele): button, badge, card, alert, input, textarea, select, checkbox, radio, switch, label, form, tabs, table, dialog, dropdown menu, popover, tooltip, toast, accordion, avatar, skeleton, pagination, breadcrumb, sidebar, combobox e command, conforme disponíveis na versão instalada.

**Próprios**, em `/assets-src/components/`, com utilitários Tailwind e as variáveis do tema, visual consistente com shadcn:

| Componente | Uso |
|---|---|
| `kanban` | colunas por etapa, cards arrastáveis (HTML Drag and Drop API) |
| `timeline` | histórico de atividades com ícone por tipo |
| `chat` | mensagens do operador, respostas do sistema, cards de ação com link/Desfazer, botões de desambiguação/confirmação |
| `metric` | número + rótulo + variação (dashboard) |
| `diff` | antes/depois em ações pendentes e auditoria |
| `filter-bar` | busca + selects + chips de filtros ativos |
| `data-table` | extensão da table do Basecoat: cabeçalho fixo, ordenação, seleção, colunas configuráveis |
| `empty` | estado vazio |
| `kbd` | atalhos de teclado |

Se algum item "do Basecoat" não existir na versão instalada, criá-lo como componente próprio seguindo o mesmo padrão.

### 12.5 Partials PHP

Para não repetir marcação, cada componente usado com frequência tem um partial/helper em `/app/Views/components/`, espelhando o modelo de componentes do shadcn:
`botao()`, `badge()`, `campo()` (label + controle + ajuda + erro), `select()`, `tabela()`, `card()`, `modal()`, `menu()`, `icone()`, `avatar()`, `pill_etapa()`, `vazio()`.
As views chamam os helpers; a marcação Basecoat fica centralizada neles.

### 12.6 JavaScript

- JS do Basecoat para componentes interativos (dropdown, popover, select, tabs, toast, command), carregado como módulo.
- JS próprio em `/public/assets/js/` para chat, kanban, data-table, filtros e paleta de comandos.
- Nada de Alpine, jQuery ou React.

### 12.7 Layout e responsividade

- Shell com o **sidebar** do Basecoat (recolhível para ícones), topo com busca global e menu do usuário, conteúdo com largura máxima de 1440px, painel de chat à direita (380px) recolhível.
- < 1024px: sidebar vira gaveta; chat vira tela cheia.
- < 640px: formulários em uma coluna; tabelas e kanban com rolagem horizontal.

### 12.8 Acessibilidade
Manter os atributos ARIA dos componentes Basecoat; foco visível; `aria-live` no chat e nos toasts; navegação completa por teclado.

### 12.9 Guia de estilo
Rota autenticada `/ui` com todos os componentes (Basecoat e próprios), variações e estados, nos temas claro e escuro.

### 12.10 Impressão
Propostas e contratos usam views próprias com `@media print` (A4, margens, quebra de página), independentes do shell.

## 13. Integração com o Opensquad

Squads de IA que rodam fora do CRM (projeto Opensquad da agência) registram o que produziram por `POST /api/integracao/opensquad`: rota pública, sem sessão e sem CSRF, autenticada pelo cabeçalho `X-Opensquad-Token` (valor em `integracao.opensquad_token`; vazio = integração desligada). Não usa `Authorization` porque hospedagem compartilhada com PHP em CGI costuma descartar esse cabeçalho.

**Corpo:** `{"squad": "prospeccao-leads", "run": "2026-09-24-101500-x", "simular": false, "eventos": [...]}` (até 100 eventos, 1 MB). **Resposta** (200): `{"ok", "simulacao", "resultados": [{"id", "status": "ok|ja_processado|erro", "mensagem"?, "empresa_id", "contato_id", "negocio_id", "acoes": [...], "avisos": [...], "link"}]}`.

| Tipo | Efeito |
|---|---|
| `lead` | Localiza a empresa (CNPJ → e-mail → WhatsApp/telefone → domínio → nome + cidade) ou cria; idem contato. Em registro existente só completa campos vazios. Garante um negócio aberto e o avança para `negocio.etapa` (nome de etapa aberta); nunca retrocede. |
| `ganho` | Como `lead`, e leva o negócio aberto à primeira etapa de ganho com `valor_fechado` (obrigatório); converte a empresa em cliente. Negócio já ganho: não cria outro. Sem negócio: cria já ganho. |
| `perdido` | Empresa existente; negócio aberto → etapa de perda com `motivo` (nome; criado se faltar) e `detalhe`. |
| `atividade` | Empresa existente; só `nota` e `tarefas`. |

Todos aceitam `nota` (atividade) e `tarefas` (até 20; `vencimento` ou `em_dias`). `origem` (nome) é criada se não existir. Campos fora da whitelist viram aviso, não erro; `status`/`cliente_desde`/`campos_extras` da empresa não são definidos pela integração.

- **Tudo ou nada por evento:** cada evento é uma transação do `ActionExecutor`; um evento recusado não grava nada e não impede os outros do lote.
- **Idempotência:** tabela `integracao_eventos`, chave `squad|run|id`. Evento já `ok` volta como `ja_processado` (com a resposta original); evento com `erro` pode ser reenviado.
- **Simulação** (`simular: true`): o lote inteiro roda numa transação desfeita no fim (savepoint por evento), sem disparar eventos nem registrar idempotência — mostra ao operador o que o envio real faria.
- **Origem** `opensquad:<squad>`, auditável e desfazível como qualquer ação. Eventos com essa origem **não disparam agentes nem squads** do CRM (Gatilhos): pesquisa, qualificação e documentos já foram feitos no Opensquad.
