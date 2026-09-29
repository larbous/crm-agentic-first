# Changelog

## v1.0.0

Primeira versão pública do CRM Lárbous — CRM interno *agentic-first* em PHP puro + SQLite, sem Composer e sem dependências externas.

### Destaques

- **CRM completo:** empresas, contatos, negócios (kanban), propostas e contratos com link público e view de impressão, chamados por área, tarefas, metas, pesquisas NPS e formulários públicos.
- **Chat de comandos** (`/nota`, `/tarefa`, `/buscar`, linguagem natural) com IA que nunca escreve SQL: devolve JSON validado por whitelist e executado pelo `ActionExecutor`.
- **Agentes e squads** com aprovação, corte de confiança, limites por hora e trilha de auditoria.
- **IA com failover:** Anthropic (primário) e Google Gemini (secundário), disjuntor e orçamento de tempo.
- **Financeiro:** cobranças e recorrência via Asaas (webhook), NF-e pelo Asaas, taxas lançadas como despesas automáticas, módulo de despesas com importação por planilha CSV e rodapé de totais por página.
- **Tema** com a identidade visual da Lárbous (Basecoat + Tailwind v4), modo claro e escuro.
- **Segurança:** CSRF, sessão endurecida, limite de tentativas de login, uploads validados, rotas públicas por token.
- **Roda em hospedagem compartilhada** (PHP 8.2+ e cron), 369 testes em PHP puro e CI no GitHub Actions.

### Instalação

Veja [docs/INSTALACAO.md](docs/INSTALACAO.md).
