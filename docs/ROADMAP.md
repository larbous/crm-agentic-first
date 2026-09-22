# ROADMAP — CRM Lárbous

Implementar em ordem. Uma fase por vez. Marcar `[x]` ao concluir cada item. Referências de seção apontam para `docs/SPEC.md`.

---

## Fase 1 — Núcleo

- [x] Estrutura de pastas conforme `CLAUDE.md`
- [x] Autoloader PSR-4 próprio; `config.php` + `config.local.php.example`
- [x] `Core`: Router (GET/POST, parâmetros, grupos `/api`), DB (PDO + PRAGMAs), Session, Csrf, Auth, View (layout + partials), Validator, Response (HTML/JSON/redirect), Helpers (`e()`, moeda, data pt-BR, centavos↔reais)
- [x] Runner de migrações `scripts/migrate.php` (tabela `migracoes`)
- [x] Migração 0001: `usuarios`, `configuracoes`, `log_auditoria`, `tags`, `taggables`, `origens`, `motivos_perda`, `pipelines`, `etapas`, `empresas`, `contatos`, `negocios`, `negocio_contatos`, `atividades`, `tarefas`, `anexos`, `campos_extras_def` (SPEC §4.1–4.5, 4.12, 4.13)
- [x] Seed com etapas, origens e motivos padrão (SPEC §4.12)
- [x] `scripts/criar-usuario.php`; tela de login/logout
- [x] Interface (SPEC §12): Tailwind v4 standalone + Basecoat + tema shadcn em `theme.css`; Inter e sprite Lucide; `docs/INSTALACAO.md` com download do executável do Tailwind e comandos de build
- [x] Helpers de componente em `/app/Views/components/` (SPEC §12.5)
- [x] Componentes próprios base: `data-table`, `empty`, `kbd` (os demais entram nas fases que os usam)
- [x] Guia de estilo em `/ui` com todos os componentes e estados, claro e escuro
- [x] Layout base: sidebar do Basecoat, topo com busca (placeholder), área de conteúdo, painel de chat recolhível (placeholder), tema claro/escuro
- [x] `tests/run.php` com testes de helpers e do Router

**Pronto quando:** migração cria o banco do zero, login funciona, layout navega entre páginas vazias, `/ui` mostra todos os componentes nos temas claro e escuro, testes passam.

---

## Fase 2 — CRUDs principais

- [x] `Services/ActionExecutor`, `Audit`, `Events` (SPEC §2, §5, §10) — todas as escritas passam por eles
- [x] Empresas: lista (busca, filtros, ordenação, paginação, colunas configuráveis), formulário em abas, detalhe com timeline
- [x] ViaCEP e BrasilAPI (CNPJ) no formulário de empresa
- [x] Contatos: lista, formulário, detalhe; vínculo com empresa
- [x] Negócios: lista, formulário, detalhe; código automático; kanban com arrastar; modais de ganho (valor_fechado) e perda (motivo)
- [x] Atividades: criação rápida na timeline; atividades `sistema` automáticas; atualização de `ultimo_contato_em`
- [x] Tarefas: tela hoje/atrasadas/próximas/todas; conclusão rápida; vínculos
- [x] Anexos: upload seguro e download autenticado
- [x] Tags em empresas, contatos e negócios
- [x] Botão "Converter em cliente" (evento `empresa.convertida`)
- [x] Busca global
- [x] Tela de Auditoria com Desfazer
- [x] Configurações: pipelines/etapas, origens, motivos de perda, tags
- [x] Testes: ActionExecutor (criar/atualizar/arquivar/desfazer) e regras de ganho/perda

**Pronto quando:** é possível operar o CRM inteiro sem IA, todo registro alterado aparece na auditoria e pode ser desfeito.

---

## Fase 3 — Serviços, Propostas, Contratos e Modelos

- [x] Migração: `servicos`, `propostas`, `proposta_itens`, `contratos`, `contrato_tipos`, `modelos_documento` (SPEC §4.6–4.9)
- [x] Catálogo de serviços
- [x] Motor de variáveis `{entidade.campo}` com formatação pt-BR
- [x] Modelos: editor, lista de variáveis, pré-visualização
- [x] Propostas: editor com itens e totais em tempo real, versões, view de impressão, link público com aceite/recusa, eventos
- [x] Contratos: criação a partir de modelo, view de impressão, link público com assinatura, renovação, eventos
- [x] Seed: tipos de contrato padrão; 1 modelo de proposta e 1 de contrato de exemplo

**Pronto quando:** do negócio sai uma proposta aceita pelo link público e dela um contrato assinado pelo link público, tudo registrado na timeline.

---

## Fase 4 — Chat

- [x] Migração: `chat_mensagens`, `execucoes` (SPEC §4.13)
- [x] UI: chat na Início e painel lateral global (`Ctrl+K`), contexto automático na tela de detalhe
- [x] Comandos `/` (SPEC §3.2), sem IA
- [x] `AI/Client` (cURL, prompt caching, registro em `execucoes`, tratamento de erro/timeout)
- [x] `AI/CommandRouter`: prompt de sistema, contrato de saída (SPEC §3.4), validação por whitelist
- [x] Resolução de referências, botões de desambiguação, botão de confirmação (SPEC §3.5)
- [x] Respostas por template com link e botão Desfazer
- [x] `consultar` renderizado como tabela no chat
- [x] Arquivo `tests/frases.json` com 30 frases reais + JSON esperado; script `scripts/avaliar-roteador.php` que mede acertos

**Pronto quando:** as 30 frases de teste têm ≥ 90% de acerto e nenhuma escrita acontece sem passar pela validação.

> **Medido (2026-09-20, Haiku 4.5):** 1ª rodada 26/30 (86,7%); após ajustar o prompt e a busca de negócio por nome da empresa, 30/30. O prompt foi ajustado olhando essas mesmas 30 frases, então o 100% é otimista: renove/amplie `tests/frases.json` com frases novas do uso real antes de confiar no número. "Nenhuma escrita sem validação" está coberta por testes automatizados.

---

## Fase 5 — Agentes

- [x] Migração: `agentes`, `acoes_pendentes` (e `agentes_versoes`, `execucoes.simulacao`)
- [x] `AI/ContextBuilder` (campos de `contexto` + `contexto_relacionado`)
- [x] `AI/AgentRunner`: execução, validação de `acoes_permitidas` e `campos_gravaveis`, aprovação, saída `texto` → nota
- [x] Suporte a `web_search` na chamada da API quando habilitado no agente
- [x] Tela Agentes: lista, editor JSON com validação, Testar (simulação), importar/exportar, versões
- [x] Tela Ações pendentes com diff e aprovação em lote
- [x] Botões de agentes no detalhe dos registros; `@slug` no chat
- [x] Tela Execuções com tokens e totais do mês
- [x] Biblioteca inicial em `/library` (SPEC §6.2) e importação no seed

**Pronto quando:** cada agente da biblioteca roda em um registro real, respeita whitelist e aprovação, e registra tokens.

> **Medido (2026-09-20, `php scripts/avaliar-agentes.php`, API real):** os 9 agentes da biblioteca rodaram sobre dados de exemplo (banco em memória, aprovação "nunca" só nesse teste) e passaram: JSON válido, nenhuma ação recusada, tokens registrados em `execucoes`. Na primeira rodada `montador-proposta` estourou `max_tokens` (3500 → 6000, prompt mais enxuto), `redator-followup` gravou a nota duas vezes (agora `acoes_permitidas` vazio: o `texto` já vira nota) e `redator-contrato` recusou por já existir contrato no negócio de exemplo (dados de teste ajustados). Ressalva: o `pesquisador` (busca na web) consome ~50 mil tokens de entrada por execução e a whitelist é por campo, não por valor: o `triagem-formulario` chegou a propor `status = cliente` contra o prompt (a aprovação "escritas" é a proteção).

---

## Fase 6 — Squads, eventos e worker

- [x] Migração: `squads`, `agendamentos_execucao` (e `squads_versoes`, `worker_marcas`)
- [x] `AI/SquadRunner`: etapas sequenciais, `condicao`, `parar_se`, `usa_saida_de`, pausa por aprovação
- [x] Gatilhos por evento (assinaturas em `Events`) com proteção contra loop
- [x] `cron/worker.php` com lock (SPEC §11); agendamentos por expressão cron
- [x] Tarefas recorrentes, tarefas vencidas, propostas expiradas, contratos vencendo/vencidos
- [x] Tela Squads: editor, importar/exportar, execuções com progresso (polling)
- [x] `#slug` no chat; botões de squad no detalhe
- [x] Biblioteca inicial de squads (SPEC §7.2)
- [x] Documentar configuração do cron em `docs/INSTALACAO.md`

**Pronto quando:** criar uma empresa dispara `novo-lead` sozinho, e `revisao-semanal` roda pela agenda.

> **Verificado (2026-09-20):** testes automáticos (`tests/SquadsTest.php`, IA simulada) cobrem gatilho por evento → fila → worker → etapas, agenda vencida, pausa/retomada por aprovação, `parar_se`, loop e rotinas. Manualmente, com a API real: `revisao-semanal` executado pela tela e processado por `php cron/worker.php` (1 etapa, ~850 tokens de entrada). O `novo-lead` completo (com `pesquisador`, busca na web, dezenas de milhares de tokens) não foi rodado de ponta a ponta contra a API para não gastar; a lógica de etapas é a mesma testada com IA simulada.

---

## Fase 7 — Campos extras e Dashboard

- [x] Tela de definição de campos extras por entidade
- [x] Renderização e validação dos campos extras nos formulários, detalhes e filtros de lista
- [x] Campos extras disponíveis em `contexto` de agentes e em variáveis de modelos (`{empresa.extra.chave}`)
- [x] Dashboard da Início completo (SPEC §9) — metas entram na Fase 9 (ver DECISOES)

**Pronto quando:** um campo criado pela tela aparece em formulário, detalhe, filtro, agente e modelo sem mexer em código.

> **Verificado (2026-09-20):** testes automáticos (`tests/CamposExtrasTest.php`) cobrem definição, validação por tipo, mescla, desfazer, filtro, variáveis de modelo, contexto de agente, formulário/detalhe e as consultas do dashboard. Manualmente (`php -S` com banco descartável via `CRM_DB_CAMINHO`): campos criados pela tela de Configurações apareceram no formulário (aba Extras, com erro de validação reexibido em 422), no detalhe, nos filtros da lista de empresas e o Início renderizou o funil. Agente e modelo com campo extra foram verificados só por testes automáticos (montagem do contexto e renderização de variáveis); nenhum agente rodou contra a API real da Anthropic com `extra.<chave>`.

---

## Fase 8 — Formulários de captação

- [x] Migração: `formularios`, `formulario_campos`, `formulario_submissoes` + campos de aquisição em empresas/negócios (SPEC §4.10)
- [x] Construtor de formulário (arrastar campos, largura, obrigatório, rótulos, opções)
- [x] Páginas públicas `/f/{chave}` e embed; script de incorporação que repassa UTMs
- [x] Detecção de duplicado e regras tarefa/mesclar/criar
- [x] Honeypot e limite por IP
- [x] Evento `formulario.submetido` e disparo do squad configurado
- [x] Tela de submissões

**Pronto quando:** um formulário embutido em página externa cria empresa + contato (+ negócio) com UTMs e dispara o squad, sem duplicar contatos existentes.

---

## Fase 9 — Ações rápidas de IA e Metas

- [x] Botões de ações rápidas em campos de texto longos e na timeline (SPEC §8)
- [x] Migração: `metas`; cadastro e cálculo de progresso
- [x] Metas no dashboard e no contexto do `analista-pipeline`

**Pronto quando:** as ações rápidas funcionam em qualquer campo longo e o relatório semanal compara realizado × meta.

> **Verificado (2026-09-20):** `tests/MetasTest.php` (fim do período, validação, progresso dos 5 tipos, ritmo, filtros, contexto do analista, card do Início) e `tests/AcoesRapidasTest.php` (prompts, limites, contexto de resumir/responder montado pelo servidor, registro em `execucoes`, botões renderizados) passam. Manualmente (`php -S` com banco descartável via `CRM_DB_CAMINHO`): meta criada pelo formulário com valor em reais e com milhar, detalhe, edição, lista e card do Início; as cinco ações rápidas rodaram contra a API real da Anthropic (Haiku) e aparecem em Execuções como "Ação rápida". **Não verificado em navegador:** o comportamento do `ia-rapida.js` (painel, "Usar este texto", preenchimento do campo Detalhes) só teve a sintaxe checada (`node --check`) e o HTML/endpoint testados; nenhum clique foi exercitado. O agente `analista-pipeline` v2 não rodou contra a API com metas reais (só a montagem do contexto foi testada).

---

## Fase 10 — Infraestrutura de IA resiliente

- [x] Migração `0008`: `execucoes.provedor/tentativas/confianca`, `acoes_pendentes.motivo`, `ia_saude_provedor`
- [x] Camada de provedores: interface `Provedor`, `ProvedorAnthropic` (comportamento anterior extraído) e `ProvedorGemini`
- [x] Failover por erro/timeout com disjuntor e orçamento total de tempo; provedor, modelo usado e tentativas em `execucoes`
- [x] Limiar de confiança (`confianca` na saída do agente, `confianca_minima` por agente e global) → ações para aprovação com motivo
- [x] Teto de execuções por hora por agente e por squad, em qualquer origem (`ia.limite_hora`)
- [x] Atualizar `CLAUDE.md`, `DECISOES.md` e `INSTALACAO.md`

**Pronto quando:** com a Anthropic fora do ar, o chat e os agentes continuam respondendo pelo Gemini; ação de baixa confiança nunca é gravada sem aprovação; nenhum agente/squad passa do teto por hora.

> **Verificado (2026-09-21):** `tests/IaResilienteTest.php` (transportes simulados) cobre failover por 5xx/429/timeout, ausência de failover em 401/403/400, disjuntor (abre, meio aberto, reabre, zera, janela), tradução de modelo, requisição e parada do Gemini, confiança (corte global, por agente, ausente, simulação) e teto por hora (agente, fila, squad); suíte completa passa. **Não verificado contra a API real do Gemini**: o formato de requisição/resposta segue a documentação `generateContent`, mas nenhuma chamada real foi feita (falta a chave) e os ids de modelo padrão (`gemini-2.5-flash`/`-pro`) precisam ser confirmados na conta. Telas (Execuções, Ações pendentes, resultado do teste de agente) só tiveram sintaxe checada e o teste de banco; não foram abertas no navegador.

---

## Fase 11 — Lead scoring com temperatura

- [x] Migração `0009`: recria `negocios` com temperatura `fervendo` e colunas CHAMP; runner com `-- @fk-off`
- [x] `Services/Champ`: pontuação 0–8 e temperatura derivada (com trava sem desafio), ligada ao `ActionExecutor`
- [x] Agentes `qualificador` e `resumidor-reuniao` v2 gravando as dimensões CHAMP (sem gravar temperatura)
- [x] Temperatura visível no cartão do kanban, filtro de temperatura no kanban e na lista, card CHAMP no detalhe
- [x] Cor "fervendo" no tema e ordenação por temperatura corrigida

**Pronto quando:** rodar o Qualificador num negócio (aprovando as ações) deixa a temperatura calculada pelo CHAMP, visível no kanban e filtrável na lista.

> **Verificado (2026-09-21):** `tests/LeadScoringTest.php` cobre a tabela de pontuação e a trava, derivação em criar/atualizar/desfazer, auditoria, temperatura explícita, ordenação e filtro, kanban (com filtro) e detalhe renderizados, os dois agentes v2 (válidos, sem `temperatura` gravável, fluxo com aprovação) e a migração (dados/FKs/índices preservados, `CHECK` novo, rollback quando há violação de FK). A migração também foi aplicada com sucesso numa cópia do banco local antes de ir ao original. **Não verificado:** o Qualificador v2 contra uma IA real (sem chave da Anthropic e o Gemini sem créditos, ver Fase 10): a qualidade da avaliação CHAMP pelo modelo não foi medida; e as telas só foram renderizadas pelos testes, não abertas no navegador (nem o arrastar do kanban com o filtro ativo).

---

## Fase 12 — NPS e pesquisa de satisfação

- [x] Migração `0010`: `formularios.tipo` e configuração de disparo; tabela `pesquisas` (token, status, nota, categoria, comentário, respostas)
- [x] Construtor de formulários em modo pesquisa (nota 0–10, comentário, até 8 perguntas extras) e lista com o tipo
- [x] Envio individual com link `/nps/{token}` (uso único, validade), atalhos de WhatsApp e e-mail, marcar enviada, cancelar
- [x] Página pública de resposta (escala 0–10) e gravação atômica; timeline, tarefa para detrator e eventos `pesquisa.criada` / `pesquisa.respondida`
- [x] Gatilhos no worker: N dias após contrato assinado e periódico para clientes, com tarefa "Enviar pesquisa"; expiração de links
- [x] Tabulação automática em `/pesquisas` (NPS, distribuição, tendência mensal, taxa de resposta, respostas) e aba na empresa

**Pronto quando:** uma pesquisa criada no construtor gera links individuais (à mão ou pelo worker), o cliente responde pelo link e o NPS aparece calculado em Pesquisas NPS.

> **Verificado (2026-09-21):** `tests/PesquisasTest.php` cobre a definição (nota obrigatória, tipo imutável, gatilhos e validade), a separação do formulário de captação, a criação do envio (token, contato, timeline, eventos, tarefa), a resposta (categoria, extras, validação, uso único, expirado/cancelado/inativo, tarefa de detrator, gatilho de agente por evento), a página pública (escala, erros, cabeçalhos, reabrir), as rotinas do worker (contrato, periódica, janela, sem duplicar, limite de 50, expiração), a matemática do NPS e as telas (índice, filtros, envio com `wa.me`/`mailto`, aba da empresa, redirecionamento só para caminhos internos). Também rodado por HTTP real (`php -S` com cópia do banco): o link abriu com a escala, recusou envio sem nota (422), gravou a nota 4 como detrator com tarefa de ligação, e o segundo acesso mostrou "já foi respondida". **Não verificado:** as telas administrativas e o construtor em modo pesquisa em um navegador (arrastar, pré-visualização da escala, copiar link e os atalhos `wa.me`/`mailto`); só foram renderizados pelos testes e o JS teve a sintaxe checada. Nenhum envio real por WhatsApp ou e-mail (não existe ainda).

---

## Fase 13 — Chamados e demandas operacionais

- [x] Migração `0011`: tabelas `areas` e `chamados` (código, área, status, prazo, vínculos, checklist JSON, resolução)
- [x] Áreas configuráveis em Configurações (seed com as cinco padrão)
- [x] Chamados: abrir, editar, status (aberto, andamento, aguardando, concluído, cancelado), fechar com resolução, arquivar, anexos; tudo pelo ActionExecutor com auditoria e Desfazer
- [x] Checklist de execução vinculado ao chamado (marcar, adicionar, remover; progresso)
- [x] Chamados na mesma tela de Tarefas (grupos, ordenação conjunta, filtro por tipo e por área) e no card do Início; aba na empresa; lista própria

**Pronto quando:** um chamado aberto para uma área com prazo aparece em Tarefas (hoje/atrasadas/próximos), tem checklist que se marca item a item e pode ser concluído com resolução.

> **Verificado (2026-09-21):** `tests/ChamadosTest.php` cobre o serviço do checklist (limites, ida e volta do texto), a criação (código, padrões, área obrigatória, campos de servidor recusados), concluir/reabrir/cancelar com a data e o Desfazer, o checklist pelas ações do ActionExecutor com auditoria, as áreas, a tela unificada (mistura e ordenação, contagens, filtros inválidos, links que mantêm o filtro), o formulário (prazo, checklist em texto, resolução), a lista com filtros, a aba da empresa e o card do Início. Também exercitado por **HTTP real autenticado** (`php -S` com cópia do banco): login, `/tarefas`, criar chamado por POST com CSRF, detalhe com progresso 50%, alternar item (302; sem CSRF devolve 419), concluir com resolução, Início e Configurações → Áreas. **Não verificado em navegador:** a aparência e o uso das telas (o filtro que envia o formulário ao mudar, os botões de item do checklist, o formulário com abas); só foram renderizadas por testes e por `curl`.

---

## Fase 14 — Caixa de entrada unificada

- [x] Migração `0012`: `conversas`, `mensagens` e `atividades.tipo` com `instagram` (recriação com `-- @fk-off`)
- [x] WhatsApp Business Cloud API: webhook assinado (`/webhooks/meta`), verificação, mensagens de texto e mídia, status de entrega e envio de respostas (janela de 24 h)
- [x] Instagram Direct pelo mesmo webhook e a mesma API Graph
- [x] E-mail: cliente IMAP e SMTP próprios (sem extensão), leitura MIME, coleta no worker e resposta com `In-Reply-To`
- [x] Toda mensagem vira atividade na timeline do contato (com histórico levado ao vincular); evento `mensagem.recebida`
- [x] Tela Caixa de entrada: lista com filtros, conversa, resposta pelo canal, vincular/criar contato, resolver/reabrir, selo no menu
- [x] `docs/INSTALACAO.md` com o passo a passo de cada canal

**Pronto quando:** uma mensagem recebida em qualquer dos três canais aparece na Caixa de entrada e na timeline do contato, e a resposta sai pelo mesmo canal.

> **Verificado (2026-09-22):** `tests/CaixaTest.php` cobre a assinatura HMAC e o desafio (inclusive o padrão seguro sem segredo), a leitura dos payloads de WhatsApp (texto, áudio, imagem, localização, botão, status) e Instagram (ecos e leituras ignorados), o webhook (403/400/413, idempotência, status que só avançam), o casamento de contato com as variações do número brasileiro, a timeline e o histórico levado ao vincular, criar contato a partir da conversa, o envio (janela de 24 h, erros da API sem vazar chave, Instagram), o MIME (cabeçalhos codificados, multipart, quoted-printable ISO-8859-1, HTML, citação, respostas automáticas), o IMAP contra um servidor falso (ponto de partida, só o novo, só conhecidos, duplicados, senha errada isolada do worker), o SMTP falso (autenticação, Re:, In-Reply-To, dot-stuffing, falha), as telas e a migração. Também por **HTTP real** (`php -S` com cópia do banco): verificação GET, 403 sem assinatura e com assinatura errada, POST assinado gravando a mensagem e o reenvio não duplicando, login, `/caixa` com a conversa, resolver com CSRF (sem CSRF 419). **Não verificado:** nenhuma chamada real à Meta (WhatsApp/Instagram) nem a um servidor IMAP/SMTP de verdade (faltam as credenciais): formatos de payload e de resposta seguem a documentação, e a revisão do app para o Instagram é externa; o envio de e-mail e a coleta com TLS real só foram exercitados com conexões simuladas; e as telas não foram abertas em um navegador.

---

## Fase 15 — Processamento multimodal e follow-up automático

- [x] Migração `0013`: transcrição (`mensagens.transcricao*`, `intencao`, `sentimento`) e resumo (`conversas.resumo*`)
- [x] Transcrição de áudios do WhatsApp e do Instagram pelo Gemini (download da mídia, chamada única pelo `Client`, JSON validado por lista fixa), com intenção e sentimento; texto na bolha, na prévia e na timeline
- [x] Créditos esgotados: provedor marcado, transcrição desabilitada (áudios na fila, sem gastar tentativas), **alerta no topo de todas as telas**, nova tentativa a cada 30 min e botão "Já recarreguei"
- [x] Resumo automático da conversa ao resolver (nota na timeline e texto na conversa)
- [x] Cadência de follow-up: agente `redator-followup` acionado pelo worker para negócios sem interação há 3, 7 e 14 dias

**Pronto quando:** um áudio recebido aparece transcrito, com intenção e sentimento, na conversa e na timeline; sem créditos do Gemini, a transcrição para e o alerta aparece no topo; resolver uma conversa gera o resumo; negócio parado ganha o rascunho de follow-up.

> **Verificado (2026-09-22):** `tests/MultimodalTest.php` (16 casos, com Gemini e Meta simulados) cobre o fluxo completo da transcrição (áudio anexo em `inline_data`, tipo `audio/ogg; codecs=opus` normalizado, Instagram por URL sem token e tipo pelo conteúdo, lista fixa de intenção e sentimento, mídia expirada e formato não suportado desistem, falha transitória tenta 3 vezes), a falta de crédito (402 e 429 "prepayment credits are depleted", Anthropic 400 "credit balance is too low": alerta, fila sem gastar tentativas, sondagem depois da espera, "já recarreguei", alerta renderizado no layout), o resumo (nota com origem `ia`, sem repetir, 3 tentativas) e o follow-up (passos, cliente por último, negócio fechado ou abandonado, agente desativado, cadência configurável, nota do rascunho não conta como interação). **Não verificado:** nenhuma chamada real ao Gemini com áudio nem download real de mídia da Meta; o formato `inline_data` e os tipos aceitos seguem a documentação. Áudio do Instagram (contêiner mp4) é enviado como `audio/aac` por suposição. As telas não foram abertas em navegador.

---

## Fase 16 — Monitoramento de exceções e prevenção de churn

- [x] Rotina do worker: cliente ativo sem interação real há N dias (`churn.dias_sem_interacao`, padrão 30; 0 desliga), contando empresa, contatos e negócios dela
- [x] Rotina do worker: chamado aberto, em andamento ou aguardando sem alteração há N dias (`churn.dias_chamado_parado`, padrão 5; 0 desliga)
- [x] Notificação ao operador: tarefa de prioridade alta para hoje, com briefing montado pelo servidor (última interação, contratos vigentes, negócios, chamados, último NPS); um alerta por silêncio/parada
- [x] Teto de 20 alertas por rodada e por tipo (ativar a rotina numa base antiga não gera avalanche)

**Pronto quando:** um cliente ativo parado além do limiar aparece em Tarefas como "Risco de churn" com o briefing, e um chamado parado como "Chamado parado"; a mesma situação não alerta duas vezes e uma interação/alteração nova rearma o alerta.

> **Verificado (2026-09-21):** `tests/ChurnTest.php` (5 casos) cobre o limiar, a interação por contato, a nota que não zera o silêncio, o rearme, lead/arquivado fora, limiar configurável e desligável, o conteúdo do briefing (contrato, valor, negócios, chamados), o chamado parado (atrasado, status concluído, alteração que zera) e o teto por rodada; suíte completa passa. **Não verificado:** as tarefas geradas não foram abertas em navegador, e o briefing com NPS só foi lido no código (sem caso de teste).

---

## Fase 17 — Financeiro: cobranças, custos e DRE por cliente

- [x] Migração `0014`: `empresas.asaas_customer_id`; tabelas `cobrancas`, `custos` e `asaas_webhooks` (idempotência do webhook)
- [x] `Services/Asaas/Client`: único ponto de chamada à API do Asaas (cliente, cobrança avulsa, assinatura recorrente, cancelamento, mapeamento de status)
- [x] Cobranças: nova entidade (avulsa/recorrente), criada já tentando emitir no Asaas; cliente Asaas criado na primeira cobrança da empresa e reaproveitado depois
- [x] Webhook `/webhooks/asaas`: autenticado por token, idempotente (hash do corpo), sincroniza pendente/pago/vencido/cancelado
- [x] Fallback local no worker: cobrança pendente vencida sem confirmação do Asaas vira "vencido" sozinha
- [x] Custos: lançamento manual (descrição, valor, data, categoria opcional, recorrente sim/não), vinculado a empresa e/ou negócio
- [x] DRE por cliente (`/financeiro/dre`): relatório calculado, Receita (cobranças pagas no período) − Custo (lançamentos no período) = Margem, por empresa
- [x] Telas: Cobranças e Custos (lista/formulário/detalhe via CRUD), aba "Financeiro" na empresa, navegação "Financeiro"
- [x] `docs/INSTALACAO.md` com o passo a passo (chave da API, webhook, NF-e)

**Pronto quando:** uma cobrança criada para uma empresa aparece no Asaas (ou fica pendente sem travar, se o Asaas não estiver configurado), o webhook atualiza o status sem duplicar processamento, e o DRE de um cliente no período soma certo as cobranças pagas e os custos lançados.

> **Verificado (2026-09-22):** `tests/FinanceiroTest.php` (15 casos) cobre a criação sem Asaas configurado (fica pendente, avisa a falha), a validação de ciclo (recorrente exige, avulsa nunca leva), custos exigindo empresa ou negócio, a emissão avulsa e recorrente com o Asaas simulado (cliente criado uma única vez por empresa e reaproveitado, `billingType`/`cycle` corretos), erro do Asaas na emissão sem travar o registro local, cancelamento (chama o Asaas quando emitida, bloqueia repetir sobre paga/cancelada), o bloqueio de campos após a emissão (só notas editáveis), impedimento de arquivar cobrança pendente já emitida, o webhook (token errado, corpo sem `event`, aplica o status, idempotência por hash do corpo — reenvio não duplica auditoria), o mapeamento de eventos do Asaas, o fallback local de vencimento e a matemática do DRE (soma só o período certo, por empresa); suíte completa (319 testes) passa. Também exercitado por **HTTP real autenticado** (`php -S` com banco descartável): login, criar empresa, criar cobrança pela tela (sem chave do Asaas configurada: fica pendente com aviso, como esperado), aba Financeiro na empresa, lista e detalhe da cobrança, tela de custos, DRE (com e sem movimento), e o webhook recusando sem token (403). **Não verificado:** nenhuma chamada real à API do Asaas (sandbox ou produção) nem um webhook real recebido — não há conta/chave do Asaas disponível nesta sessão; o formato de requisição/resposta segue a documentação pública da API v3. A emissão de NF-e não foi testada (depende da configuração fiscal no painel do Asaas, fora do CRM) e o app não confirma nem verifica a migração da conta Asaas ao Emissor Nacional de NFS-e — isso é responsabilidade externa do operador. As telas não foram abertas num navegador de verdade (só por `curl`).

---

## Pendência conhecida — IVA Dual (CBS/IBS) e gestão de fornecedor

Fora do roteiro das 17 fases (nunca foi especificado em `docs/SPEC.md` nem no roteiro original de fases 10–17). Registrado em 2026-09-22 a partir de uma pergunta direta do usuário; sem escopo definido ainda, então não vira checklist de fase até isso acontecer.

- **Fornecedor**: não existe como entidade. `empresas` só representa o lado cliente/lead (`status_empresa`: lead, prospect, cliente, ex_cliente, inativo); não há cadastro de quem vende para a Lárbous, nem contas a pagar, nem vínculo entre um custo e um fornecedor específico.
- **Custos** (Fase 17) são só descrição + valor + data + categoria em texto livre — pensados para o DRE por cliente, não para gestão de fornecedores ou fluxo de aprovação de compra.
- **CBS/IBS (Reforma Tributária)**: nenhum campo, cálculo ou regra tributária no CRM. A emissão de NF-e das cobranças é inteiramente delegada ao Asaas (o CRM só guarda `nfe_status`/`nfe_url`, o que o webhook devolve); toda alíquota, CST/classificação tributária e o próprio cálculo de CBS/IBS ficam do lado do Asaas (ou da contabilidade), não deste sistema.
- **Antes de planejar uma fase para isso**, decidir com o usuário: se fornecedor é uma entidade nova (`fornecedores`) ou uma extensão de `empresas` (ex.: `tipo: cliente|fornecedor|ambos`); se custos por fornecedor viram contas a pagar (com vencimento/status) ou continuam lançamento simples; e se CBS/IBS precisa de algum dado guardado localmente (para relatório, por exemplo) ou se basta continuar 100% delegado ao Asaas/contabilidade, dado que o CRM não emite nota fiscal por conta própria (regra do CLAUDE.md: nenhuma dependência nova sem instrução explícita).

---

## Pendência conhecida — Contato vinculado a mais de uma empresa

Fora do roteiro das 17 fases. Registrado em 2026-09-22 a partir de uma pergunta direta do usuário (caso real:
uma pessoa é responsável por 3 empresas e usa um único contato/e-mail). Sem escopo definido ainda, então não
vira checklist de fase até isso acontecer.

- **Hoje `contatos.empresa_id` é uma chave estrangeira única** (`migrations/0001_nucleo.sql:226`): um contato
  pertence a exatamente uma empresa. Não existe tabela pivô contato↔empresa (só `negocio_contatos`, que liga
  contato a negócio, não a empresa extra).
- **Decisão do usuário para agora**: contornar cadastrando a pessoa como um contato duplicado em cada
  empresa (mesmo nome/e-mail, um registro por empresa). Aceitável porque **contatos ainda não têm login** no
  sistema (só usuários internos autenticam) — não há identidade única de contato a preservar entre empresas
  por enquanto, então a duplicação não quebra nada além de exigir atualizar o e-mail/telefone em mais de um
  lugar se mudar.
- **Se/quando virar fase**, a solução correta é uma relação muitos-para-muitos de verdade (nova tabela pivô,
  ex. `contato_empresas`, contato sem `empresa_id` fixo), o que exige revisar: whitelist do
  `Schema`/`ActionExecutor`, telas de contato e empresa (formulário, listagem, detalhe), resolução de
  referência no chat (`BuscaRepository::resolver`), destinos de campo em formulários públicos, e toda consulta
  que hoje assume 1 empresa por contato (ex. LTV, filtros, exportações). Repensar também o que acontece se
  isso um dia precisar coexistir com login de contato (portal do cliente) — a essa altura "qual empresa esse
  contato está vendo" deixa de ser implícito.
