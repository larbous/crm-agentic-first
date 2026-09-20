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

> **Pendente:** a meta de ≥ 90% só pode ser medida com a API real — rode `php scripts/avaliar-roteador.php` com `anthropic.api_key` em `config.local.php` (o modo `--offline` já confere que o fixture é válido no contrato). A parte "nenhuma escrita sem validação" está coberta por testes automatizados.

---

## Fase 5 — Agentes

- [ ] Migração: `agentes`, `acoes_pendentes`
- [ ] `AI/ContextBuilder` (campos de `contexto` + `contexto_relacionado`)
- [ ] `AI/AgentRunner`: execução, validação de `acoes_permitidas` e `campos_gravaveis`, aprovação, saída `texto` → nota
- [ ] Suporte a `web_search` na chamada da API quando habilitado no agente
- [ ] Tela Agentes: lista, editor JSON com validação, Testar (simulação), importar/exportar, versões
- [ ] Tela Ações pendentes com diff e aprovação em lote
- [ ] Botões de agentes no detalhe dos registros; `@slug` no chat
- [ ] Tela Execuções com tokens e totais do mês
- [ ] Biblioteca inicial em `/library` (SPEC §6.2) e importação no seed

**Pronto quando:** cada agente da biblioteca roda em um registro real, respeita whitelist e aprovação, e registra tokens.

---

## Fase 6 — Squads, eventos e worker

- [ ] Migração: `squads`, `agendamentos_execucao`
- [ ] `AI/SquadRunner`: etapas sequenciais, `condicao`, `parar_se`, `usa_saida_de`, pausa por aprovação
- [ ] Gatilhos por evento (assinaturas em `Events`) com proteção contra loop
- [ ] `cron/worker.php` com lock (SPEC §11); agendamentos por expressão cron
- [ ] Tarefas recorrentes, tarefas vencidas, propostas expiradas, contratos vencendo/vencidos
- [ ] Tela Squads: editor, importar/exportar, execuções com progresso (polling)
- [ ] `#slug` no chat; botões de squad no detalhe
- [ ] Biblioteca inicial de squads (SPEC §7.2)
- [ ] Documentar configuração do cron em `docs/INSTALACAO.md`

**Pronto quando:** criar uma empresa dispara `novo-lead` sozinho, e `revisao-semanal` roda pela agenda.

---

## Fase 7 — Campos extras e Dashboard

- [ ] Tela de definição de campos extras por entidade
- [ ] Renderização e validação dos campos extras nos formulários, detalhes e filtros de lista
- [ ] Campos extras disponíveis em `contexto` de agentes e em variáveis de modelos (`{empresa.extra.chave}`)
- [ ] Dashboard da Início completo (SPEC §9)

**Pronto quando:** um campo criado pela tela aparece em formulário, detalhe, filtro, agente e modelo sem mexer em código.

---

## Fase 8 — Formulários de captação

- [ ] Migração: `formularios`, `formulario_campos`, `formulario_submissoes` + campos de aquisição em empresas/negócios (SPEC §4.10)
- [ ] Construtor de formulário (arrastar campos, largura, obrigatório, rótulos, opções)
- [ ] Páginas públicas `/f/{chave}` e embed; script de incorporação que repassa UTMs
- [ ] Detecção de duplicado e regras tarefa/mesclar/criar
- [ ] Honeypot e limite por IP
- [ ] Evento `formulario.submetido` e disparo do squad configurado
- [ ] Tela de submissões

**Pronto quando:** um formulário embutido em página externa cria empresa + contato (+ negócio) com UTMs e dispara o squad, sem duplicar contatos existentes.

---

## Fase 9 — Ações rápidas de IA e Metas

- [ ] Botões de ações rápidas em campos de texto longos e na timeline (SPEC §8)
- [ ] Migração: `metas`; cadastro e cálculo de progresso
- [ ] Metas no dashboard e no contexto do `analista-pipeline`

**Pronto quando:** as ações rápidas funcionam em qualquer campo longo e o relatório semanal compara realizado × meta.
