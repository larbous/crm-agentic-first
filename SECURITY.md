# Política de segurança / Security policy

*(English summary at the bottom.)*

## Como reportar uma vulnerabilidade

**Não abra uma issue pública** para falhas de segurança — isso expõe todo mundo que já usa o CRM antes de existir correção.

Use um destes canais privados:

1. **Recomendado:** [relatar uma vulnerabilidade de forma privada no GitHub](https://github.com/larbous/crm-agentic-first/security/advisories/new) (aba *Security* → *Report a vulnerability*).
2. Por e-mail: **agencia.larbous@gmail.com**, com o assunto `[SEGURANÇA] CRM Lárbous`.

Inclua, se puder: a versão/commit, o passo a passo para reproduzir, o impacto que você enxerga e (opcionalmente) uma sugestão de correção. **Não inclua** dados reais de clientes nem chaves de API reais no relato.

## O que esperar

- Confirmamos o recebimento em até **7 dias** (é um projeto mantido por uma equipe pequena — prazos em regime de melhor esforço).
- Avaliamos, corrigimos e publicamos a correção antes de divulgar detalhes. Se você quiser, citamos o seu nome (ou preservamos o anonimato) nos créditos da correção.
- Pedimos que você aguarde a correção antes de divulgar publicamente (divulgação coordenada).

## Versões com correção de segurança

Só a branch `main` (última versão) recebe correções. Se você usa um fork ou uma versão antiga, atualize.

## O que está no escopo

Qualquer falha no código deste repositório que permita, por exemplo: acesso sem autenticação, contorno de CSRF, injeção (SQL, HTML/XSS, comandos), leitura ou escrita de arquivos fora do previsto, vazamento de dados ou de credenciais, contorno da whitelist do `ActionExecutor` (a IA nunca deve conseguir escrever fora do que foi autorizado) ou do limite de tentativas de login.

Fora do escopo: problemas na sua hospedagem, na configuração do seu servidor ou em serviços de terceiros (Anthropic, Google, Meta, Asaas).

## Checklist de endurecimento para quem instala

- **Use HTTPS.** O cookie de sessão só recebe a flag `Secure` quando a requisição é HTTPS.
- **Crie o primeiro usuário logo depois de subir o sistema.** Enquanto não existe usuário, a rota `/instalar` cria o administrador para quem chegar primeiro. Não deixe a URL exposta sem terminar a instalação.
- **Aponte o document root para `public/`** sempre que o painel da hospedagem permitir. Se não permitir, o `.htaccess` da raiz já bloqueia `app/`, `storage/` e `config*.php`; confirme abrindo `https://seu-dominio/storage/db/crm.sqlite` — deve dar 404.
- **Chaves e senhas só em `config.local.php`** (ignorado pelo Git). Nunca em `config.php`, no banco ou no repositório.
- **Tokens dos webhooks** (`asaas.webhook_token`, `integracao.opensquad_token`, credenciais da Meta): use textos longos e aleatórios (`php -r "echo bin2hex(random_bytes(32));"`). Com o token vazio, o webhook correspondente fica desativado.
- **Faça backup do `storage/db/crm.sqlite`** e proteja o arquivo (ele contém todos os dados do CRM).
- Mantenha o PHP atualizado.

---

## English summary

**Please do not report security issues in public issues.** Use GitHub's private vulnerability reporting ([Security → Report a vulnerability](https://github.com/larbous/crm-agentic-first/security/advisories/new)) or email **agencia.larbous@gmail.com** with the subject `[SECURITY] CRM Lárbous`. We aim to acknowledge within 7 days (best effort, small team) and ask for coordinated disclosure. Only the `main` branch receives fixes.

Hardening tips: serve over HTTPS; finish the installer right after deploying (the `/instalar` route creates the admin account for whoever reaches it first); point the document root at `public/`; keep secrets only in `config.local.php`; use long random webhook tokens; back up `storage/db/crm.sqlite`.
