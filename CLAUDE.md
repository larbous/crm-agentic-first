# CLAUDE.md — CRM Lárbous

CRM interno da Lárbous (agência web), com conceito **agentic-first**: o operador trabalha por chat e por telas de CRUD; agentes de IA e squads executam processos. Praticamente 1 operador.

**Fontes da verdade:**
- `docs/SPEC.md` — especificação completa (dados, telas, IA, agentes, squads).
- `docs/ROADMAP.md` — fases, checklists e critérios de pronto.

Antes de implementar qualquer coisa, leia a seção correspondente do SPEC. Ao concluir itens, marque o checklist no ROADMAP. Se algo não estiver especificado, escolha a opção mais simples coerente com o SPEC e registre a decisão (data + decisão + motivo) em `docs/DECISOES.md` — arquivo interno, fora do repositório público; se não existir na sua cópia, explique a decisão na descrição do commit/PR.

---

## Stack (não alterar sem instrução explícita)

- **PHP 8.2+**, sem framework, `declare(strict_types=1);` em todo arquivo.
- **Sem Composer e sem dependências externas.** Autoloader PSR-4 próprio (`App\` → `/app`).
- **SQLite** via PDO. Arquivo em `/storage/db/crm.sqlite` (fora da pasta pública).
- **Frontend:** HTML renderizado em PHP (views), **JavaScript vanilla** (ES modules, `fetch`). Visual **shadcn/ui** implementado com **Basecoat** (porte do shadcn/ui para HTML + Tailwind, sem React) e **Tailwind CSS v4** compilado pelo executável standalone (sem Node). O CSS compilado é versionado; o servidor nunca roda build (SPEC §12).
- **Assets de terceiros permitidos (versionados no repositório, nada de CDN):** Basecoat, fonte Inter (woff2), ícones Lucide (sprite SVG). Nada além disso sem instrução explícita.
- **IA:** `App\Services\AI\Client` é o único ponto de chamada, via cURL, com provedores em `App\Services\AI\Provedor*`: **Anthropic** (primário) e **Google Gemini** (secundário, failover). Nenhuma outra forma de chamar IA; novo provedor = nova classe `Provedor`, nunca cURL solto.
- **Background:** `cron/worker.php` executado por cron a cada minuto.
- **PDF:** não usar biblioteca. Propostas e contratos têm uma view otimizada para impressão (`@media print`); o usuário gera o PDF pelo navegador.
- Deve rodar em hospedagem compartilhada comum (PHP + cron). Nada de processos persistentes, WebSockets ou extensões incomuns. Extensões exigidas: `pdo_sqlite`, `curl`, `mbstring`, `json`, `fileinfo`.

## Estrutura de pastas

```
/assets-src        # fontes CSS (Tailwind + Basecoat + tema + componentes próprios)
/public            # document root — única pasta exposta
  index.php        # front controller
  /assets/js       # módulos ES
  /assets/css
/app
  /Core            # Router, DB, Auth, View, Validator, Csrf, Session, Response, Helpers
  /Controllers
  /Repositories    # único lugar com SQL
  /Services
    ActionExecutor.php   # TODA escrita de dados passa aqui (humano, chat, agente)
    Audit.php
    Events.php
    /AI            # Client, CommandRouter, ContextBuilder, AgentRunner, SquadRunner
  /Views           # templates PHP
/library           # *.agent.json e *.squad.json da biblioteca inicial
/migrations        # NNNN_descricao.sql, aplicadas em ordem
/storage           # db/, uploads/, logs/ — nunca acessível pela web
/cron              # worker.php
/scripts           # CLI: migrate.php, criar-usuario.php, seed.php
/tests             # run.php + testes em PHP puro
/docs
config.php         # fora de /public; lê config.local.php (não versionado)
```

## Convenções de código

- PSR-12. Classes `PascalCase`, métodos `camelCase`, tabelas e colunas `snake_case` **em português** (conforme SPEC).
- Controllers finos: validam entrada, chamam Service/Repository, devolvem view ou JSON.
- **SQL só em Repositories**, sempre com prepared statements. Nunca interpolar variáveis em SQL.
- **Toda criação/alteração/arquivamento passa pelo `ActionExecutor`**, que valida, grava, registra em `log_auditoria` e dispara eventos. Controllers de CRUD também usam o ActionExecutor.
- Soft delete: `arquivado_em`. Nunca `DELETE` físico em entidades de negócio (exceto via ferramenta explícita de limpeza, que não existe no MVP).
- Rotas JSON sob `/api/...`; rotas de tela sem prefixo.
- JS: um módulo por responsabilidade (`chat.js`, `kanban.js`, `forms.js`, `table.js`, `ui.js`). Sem jQuery, Alpine ou React.
- Views usam os helpers de componente de `/app/Views/components/` (SPEC §12.5); não repetir marcação Basecoat nas páginas.
- Após alterar views ou CSS, recompilar `public/assets/css/app.css` antes do commit.
- Comentários e textos de interface em **português do Brasil**.

## Convenções de dados

- `id INTEGER PRIMARY KEY AUTOINCREMENT` em todas as tabelas.
- Datas em texto ISO 8601 (`YYYY-MM-DD` ou `YYYY-MM-DD HH:MM:SS`), fuso **America/Sao_Paulo**.
- **Valores monetários em centavos (INTEGER).** Conversão para reais só na exibição e na entrada.
- Booleanos como `INTEGER` 0/1. JSON como `TEXT`.
- Toda tabela de negócio tem `criado_em`, `atualizado_em`, `arquivado_em`, `criado_por` (`humano`, `ia`, `agente:<slug>`, `formulario:<id>`, `sistema`).
- PRAGMAs em toda conexão: `foreign_keys = ON`, `journal_mode = WAL`, `busy_timeout = 5000`.
- Migrações nunca são editadas depois de aplicadas; mudança = nova migração.

## Segurança

- Senhas com `password_hash` / `password_verify`.
- CSRF token em todo POST/PUT/DELETE (formulários e `fetch` via header `X-CSRF-Token`).
- Escapar toda saída HTML com o helper `e()`.
- Sessão: `httponly`, `samesite=Lax`, `secure` quando HTTPS; regenerar ID no login.
- Uploads: validar MIME real (`finfo`), limite de tamanho, nome aleatório, salvar em `/storage/uploads`, servir por controller autenticado.
- Rotas públicas (links de proposta/contrato, formulários) só por token aleatório de 32+ caracteres; formulários com honeypot e limite por IP.
- Chave da API Anthropic em `config.local.php`, nunca no banco nem no repositório.

## Regras de IA (críticas)

1. **A IA nunca escreve SQL e nunca acessa o banco.** Ela devolve JSON; o servidor valida contra uma whitelist de ações, entidades, campos e operadores e executa via `ActionExecutor`.
2. **Uma chamada por mensagem do chat.** Sem loop de agente no chat, sem histórico de conversa enviado. Contexto = registro aberto na tela + última entidade referenciada (id + nome).
3. **Respostas ao operador são templates do servidor**, não texto gerado pela IA (exceto saídas de agentes de redação).
4. **Resolução de nomes é do servidor** (busca no banco). Ambiguidade → botões de escolha, sem nova chamada à IA.
5. Prompts de sistema curtos, fixos e com prompt caching.
6. Toda chamada registra em `execucoes`: modelo, provedor, tentativas, tokens de entrada/saída, duração, status.
7. Agentes só executam as `acoes_permitidas` do seu JSON; com `aprovacao` ativa, as ações vão para `acoes_pendentes`. Além disso, todo agente devolve `confianca` (0–1): abaixo do corte (`confianca_minima` do agente, senão `ia.confianca_minima`, padrão 0,7) as ações vão para aprovação **mesmo com `aprovacao: nunca`**; saída sem `confianca` conta como 0. Teto de execuções por hora por agente e por squad (`ia.limite_hora`, padrão 30), em qualquer origem.
8. Arquivamento e alterações em lote pedidas pelo chat **sempre** exigem confirmação.
9. Modelos padrão: roteador e tarefas simples `claude-haiku-4-5-20251001`; agentes de redação e análise `claude-sonnet-5`. Sempre lidos de `configuracoes` / JSON do agente, nunca hardcoded fora de defaults. No failover, o modelo Claude pedido é traduzido por classe (Haiku → `ia.modelo_gemini_rapido`; demais → `ia.modelo_gemini_redacao`).
10. Failover: erro de rede, timeout, 429/529/5xx (depois de 1 nova tentativa) troca de provedor dentro de um orçamento total de tempo (`ia.deadline_total`, padrão 100 s); 3 falhas seguidas em 5 min abrem o disjuntor do provedor por 5 min. 401/403 e outros 4xx **não** trocam (erro de configuração aparece ao operador). Busca na web é só da Anthropic.

## Como trabalhar

- Implemente **uma fase por vez**, na ordem do ROADMAP.
- Ao terminar cada fase: rode `php scripts/migrate.php`, `php tests/run.php`, teste manualmente com `php -S localhost:8000 -t public`, marque o checklist e faça commit (`fase N: <resumo>`).
- Não implemente itens de fases futuras "por antecipação".
- Não adicione dependências, frameworks ou serviços externos, **exceto os previstos em `docs/roadmap-fases-10-17-crm-larbous.md`**: Gemini (Fase 10), WhatsApp Cloud API, e-mail e Instagram (Fase 14) e Asaas (Fase 17), cada um só na sua fase.
- Se uma instrução do SPEC parecer impossível ou conflitante, pare e pergunte.
