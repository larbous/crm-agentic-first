# Contribuindo / Contributing

Obrigado por querer ajudar! Contribuições são bem-vindas: código, testes, documentação, traduções, ideias e relatos de bug.

*(English: PRs and issues in English are welcome. The short version is at the bottom.)*

## Antes de começar

- **Bug ou ideia?** Abra uma [issue](https://github.com/larbous/crm-agentic-first/issues/new/choose) e descreva o problema ou a necessidade. Para mudanças grandes, converse antes de escrever código — evita retrabalho.
- **Falha de segurança?** Não abra issue pública: veja [`SECURITY.md`](SECURITY.md).
- **Dúvida de uso?** Use as *Discussions* (se estiverem ativas) ou abra uma issue com o rótulo `pergunta`.

## Ambiente de desenvolvimento

Requisitos: PHP 8.2+ com `pdo_sqlite`, `curl`, `mbstring`, `json`, `fileinfo`. **Não há Composer nem npm.**

```bash
git clone https://github.com/larbous/crm-agentic-first.git
cd crm-agentic-first
php scripts/migrate.php
php scripts/seed.php
php scripts/criar-usuario.php "Seu Nome" voce@exemplo.com "uma-senha-forte"
php -S localhost:8000 -t public
php tests/run.php            # suíte de testes (PHP puro, sem PHPUnit)
```

## Regras do projeto (as mesmas do [`CLAUDE.md`](CLAUDE.md))

- PHP 8.2+, **sem framework e sem dependências externas**. Nada de Composer, CDN ou pacotes novos. Assets de terceiros só os já versionados (Basecoat, Inter, Lucide).
- PSR-12 e `declare(strict_types=1);` em todo arquivo PHP. JavaScript vanilla (módulos ES), sem jQuery/React/Alpine.
- **SQL só em `app/Repositories`**, sempre com prepared statements.
- **Toda criação, alteração ou arquivamento passa pelo `ActionExecutor`** (humano, chat, agente e formulário público escrevem pelo mesmo portão, com as mesmas regras e auditoria).
- **A IA nunca escreve SQL nem toca no banco:** ela devolve JSON, e o servidor valida contra a whitelist do `Schema` e executa.
- Tabelas e colunas em `snake_case` **em português**; valores monetários em centavos (`INTEGER`); datas ISO 8601.
- **Migrações nunca são editadas depois de aplicadas:** mudança é uma migração nova (`migrations/NNNN_descricao.sql`).
- Escape toda saída HTML com `e()`; POSTs precisam de CSRF; segredos só em `config.local.php`.
- Comentários e textos de interface em **português do Brasil** (PRs com descrição em inglês são bem-vindos).
- Mexeu em views ou CSS? Recompile `public/assets/css/app.css` antes do commit (passo a passo em [`docs/INSTALACAO.md`](docs/INSTALACAO.md)).

## Como enviar um pull request

1. Faça um fork e crie uma branch a partir da `main`.
2. Faça a mudança **com testes** (`tests/*Test.php`, função `teste('descrição', fn () => ...)`). Rode `php tests/run.php` — tudo deve passar.
3. Se a spec ([`docs/SPEC.md`](docs/SPEC.md)) for omissa, escolha a opção mais simples coerente com ela e **explique a decisão na descrição do PR**.
4. Commits curtos e descritivos, no estilo `feat: ...`, `fix: ...`, `docs: ...`.
5. Abra o PR preenchendo o modelo. Não inclua chaves de API, dados de clientes nem arquivos de banco (`.sqlite`) — o `.gitignore` já cobre os comuns, mas confira o `git status`.

## English summary

PHP 8.2+, no framework, no dependencies (no Composer/npm). SQL only in `app/Repositories`; every write goes through `ActionExecutor`; AI never touches the database (it returns JSON validated against a whitelist). Migrations are append-only. Run `php tests/run.php` before opening a PR and add tests for what you change. UI text and comments are in Brazilian Portuguese, but issues and PRs in English are welcome. Security issues: see [`SECURITY.md`](SECURITY.md).
