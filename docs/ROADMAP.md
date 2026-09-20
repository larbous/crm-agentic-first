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
