# Deploy

Este documento cobre duas coisas: como manter uma instância real (com dados e customizações próprias)
separada deste repositório público, e como publicar essa instância na Hostinger sem build no servidor.
Para a primeira instalação (banco, usuário, variáveis de `config.local.php`), veja
[`docs/INSTALACAO.md`](INSTALACAO.md) — este documento assume que a instalação já funciona localmente.

## Repositório público vs. instância real

Este repositório (`crm-agentic-first`) é o projeto open source: código genérico, sem dados de nenhum
cliente, sem segredos (já fora do controle de versão via `.gitignore`: `config.local.php`, `storage/db`,
`storage/uploads`, `storage/logs`, `docs/DECISOES.md`).

Quem for operar uma instância real (com marca, clientes e integrações próprias) deve manter um **repositório
privado separado**, não um "fork" do GitHub — um fork de repositório público permanece público. O caminho é
duplicar o histórico uma vez para um repositório privado novo e trabalhar a partir dele:

```bash
# 1. crie um repositório PRIVADO vazio no GitHub (gh repo create --private, ou pela UI)

# 2. a partir de um clone deste repositório público:
git remote add producao https://github.com/SEU-USUARIO/SEU-REPO-PRIVADO.git
git push producao main
```

A partir daí:

- `origin` continua apontando para o repositório público — só recebe melhorias genéricas (sem nada
  específico de uma operação real), via commit direto ou PR.
- `producao` é o repositório privado, fonte do deploy na hospedagem. É lá que ficam customizações de marca,
  regras de negócio específicas e qualquer ajuste que não faça sentido no projeto genérico.
- Para trazer melhorias feitas no público para a instância real: `git fetch origin && git merge origin/main`.
- Para levar uma melhoria genérica feita na instância real de volta ao público: `git cherry-pick <hash>` do
  commit específico e `git push origin` (ou abra um PR).

Segredos (chaves de API, senha do banco, tokens de webhook) nunca entram em nenhum dos dois repositórios —
sempre em `config.local.php`, que é local ao servidor e está no `.gitignore` dos dois.

## Deploy na Hostinger (planos Business/Cloud com Git no hPanel)

A Hostinger tem uma ferramenta de Git nativa (hPanel → **Avançado → Git**) que faz `git pull` no servidor a
cada push — sem precisar de build, exatamente como este projeto já é pensado para rodar
([`docs/INSTALACAO.md`](INSTALACAO.md#hospedagem-compartilhada)).

1. **hPanel → Avançado → Git → Criar novo repositório.**
   - Endereço do repositório: URL HTTPS do repositório **privado** de produção (`producao` acima). Para repo
     privado, a Hostinger pede autenticação — gere um
     [token de acesso pessoal](https://github.com/settings/tokens) com escopo `repo` e use como senha (ou
     configure uma deploy key, se a ferramenta oferecer essa opção).
   - Branch: `main` (ou uma branch dedicada, ex. `producao`, se quiser separar do que está em teste).
   - Diretório de destino: uma pasta **fora** do document root do site (ex. `crm-producao/`, ao lado de
     `public_html/`, não dentro dela).
2. **Confira o Document Root do domínio** (hPanel → **Sites → Gerenciar → Document Root**): tem que apontar
   para `<diretório de destino do Git>/public`, nunca para a raiz do projeto. Esse foi um bug real do
   primeiro deploy: com o document root na raiz, o Apache nunca alcança o front controller (`/instalar` e
   qualquer outra rota devolvem 404 antes de chegar no PHP) e, pior, `storage/` (banco, uploads) e
   `config.local.php` (chaves de API) ficam potencialmente servíveis por HTTP direto. O `.htaccess` da raiz
   do repositório (`Require all denied`) é uma rede de segurança para esse caso, mas **não substitui**
   apontar o document root certo — é defesa em profundidade, não a correção.
3. **Ative o auto-deploy** (webhook): a cada `git push` na branch escolhida, a Hostinger puxa
   automaticamente as mudanças. Depois de conectar, confira em **Settings → Webhooks** do repositório no
   GitHub se um webhook da Hostinger foi realmente criado (`gh api repos/OWNER/REPO/hooks` deve devolver uma
   lista não vazia); em alguns planos essa integração não cria webhook algum, e o deploy automático nunca
   dispara — nesse caso use o botão de deploy manual do painel a cada push, ou avalie a alternativa com
   GitHub Actions abaixo.
4. **Comando pós-deploy** (se o plano oferecer essa opção no painel de Git): configure
   `php scripts/migrate.php` para aplicar migrações novas a cada deploy sem depender de SSH manual. Se a
   opção não existir, rode manualmente por SSH depois de cada push:
   ```bash
   ssh usuario@servidor "cd /caminho/do/projeto && php scripts/migrate.php"
   ```
5. **Primeira execução**: como descrito em [`docs/INSTALACAO.md`](INSTALACAO.md), a primeira requisição
   HTTP aplica as migrações e a rota `/instalar` cria o usuário administrador. Depois disso a rota se
   desliga sozinha.
6. **Persistência entre deploys**: `config.local.php` e `storage/db`, `storage/uploads`, `storage/logs`
   ficam fora do controle de versão — um `git pull` não os toca (`git pull` nunca apaga arquivo não
   rastreado). Garanta apenas que `storage/` seja gravável pelo usuário do PHP no servidor e que
   `config.local.php` exista lá (copie manualmente uma vez, via SFTP/SSH, a partir de
   `config.local.php.example`).
7. **Cron**: cadastre `cron/worker.php` a cada minuto em hPanel → Avançado → Tarefas Cron, como em
   [`docs/INSTALACAO.md`](INSTALACAO.md#worker-cron).

## Alternativa: GitHub Actions

Se no futuro fizer sentido um gate de CI antes do deploy (ex.: só publicar se `php tests/run.php` passar),
isso pode ser adicionado como uma camada extra sobre o mesmo fluxo — uma Action que roda os testes e, se
passar, conecta por SSH (`rsync`/`git pull` remoto) ou dispara o mesmo endpoint de auto-deploy da Hostinger.
Não é necessário para o funcionamento básico: a ferramenta de Git nativa já cobre o caso de uso de um
operador só, sem pipeline de CI.
