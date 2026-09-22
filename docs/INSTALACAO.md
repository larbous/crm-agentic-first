# Instalação e build

## Requisitos

- PHP 8.2+ com extensões `pdo_sqlite`, `curl`, `mbstring`, `json`, `fileinfo`. A extensão `dom` (padrão na maioria das hospedagens) é usada para sanitizar o HTML dos contratos; sem ela o conteúdo é tratado como texto.
- Sem Composer e sem Node. O servidor de hospedagem nunca executa build: recebe o CSS pronto.

## Primeira execução

### Sem CLI (hospedagem compartilhada, cPanel/Plesk/hPanel)

Copie os arquivos para a hospedagem, aponte o document root para `public/` e garanta que `storage/` é
gravável pelo PHP. A primeira requisição HTTP (`Services/Instalador`, chamado em `public/index.php`) aplica
sozinha as migrações pendentes e o seed padrão (`Services/Semeador`); a rota pública `/instalar` então pede
para criar o usuário administrador. Depois de criado o primeiro usuário, a rota `/instalar` deixa de
funcionar (redireciona para `/login`) — não é possível usá-la de novo. Uma marca em
`storage/db/.instalado` faz esse mecanismo rodar só uma vez por instalação; **atualizações** (migrações
novas de uma versão futura do CRM) continuam pelo `php scripts/migrate.php` de sempre, abaixo.

### Com CLI (desenvolvimento local ou hospedagem com SSH)

```bash
cp config.local.php.example config.local.php   # ajuste se necessário (não versionado)
php scripts/migrate.php                        # cria/atualiza o banco em storage/db/crm.sqlite
php scripts/seed.php                           # etapas, origens e motivos de perda padrão
php scripts/criar-usuario.php "Seu Nome" voce@dominio.com "senha-com-8+-caracteres"
php tests/run.php                              # testes
php -S localhost:8000 -t public                # servidor de desenvolvimento
```

> Windows: se `pdo_sqlite` não estiver ativa, habilite `extension=pdo_sqlite` (e `sqlite3`) no `php.ini`
> ou rode com `php -d extension=pdo_sqlite -d extension=sqlite3 ...`.

## CSS (Tailwind v4 standalone)

O CSS compilado (`public/assets/css/app.css`) é versionado. Só é preciso recompilar ao mexer em
`assets-src/`, nas views (`app/Views/**/*.php`) ou nos módulos JS (`public/assets/js/**/*.js`).

1. Baixe o executável do Tailwind CSS v4 em <https://github.com/tailwindlabs/tailwindcss/releases/latest>
   (ex.: `tailwindcss-windows-x64.exe`, `tailwindcss-linux-x64`, `tailwindcss-macos-arm64`) e salve na raiz
   do projeto como `tailwindcss` (ou `tailwindcss.exe`). Ele está no `.gitignore`.
   - Linux/macOS: `chmod +x tailwindcss`
2. Desenvolvimento (recompila ao salvar):
   ```bash
   ./tailwindcss -i assets-src/app.css -o public/assets/css/app.css --watch
   ```
3. Produção (antes do commit):
   ```bash
   ./tailwindcss -i assets-src/app.css -o public/assets/css/app.css --minify
   ```

## Componentes e assets de terceiros (versionados)

| Item | Origem | Local |
|---|---|---|
| Basecoat 1.0.2 (MIT) — CSS | npm `basecoat-css` | `assets-src/vendor/basecoat/` |
| Basecoat — JS dos componentes | npm `basecoat-css` (`dist/js/all.min.js`) | `public/assets/vendor/basecoat/` |
| Lucide (ISC) — sprite SVG | npm `lucide-static` (`sprite.svg`) | `public/assets/vendor/lucide/` |
| Inter (OFL) — woff2 variável | npm `@fontsource-variable/inter` | `public/assets/vendor/inter/` |

Para atualizar o Basecoat: baixe o pacote `basecoat-css` (`npm pack basecoat-css` ou o tarball do registro),
copie `dist/components/*.css`, `dist/basecoat-components.css` e `dist/styles/vega.css` para
`assets-src/vendor/basecoat/` e `dist/js/all.min.js` para `public/assets/vendor/basecoat/`; recompile o CSS.

## Tema

`assets-src/theme.css` traz as variáveis do shadcn/ui (claro e `.dark`) mais `--success`, `--warning`, `--info`
e `--temp-*`. Para trocar o tema, gere um no editor de temas do shadcn/ui e cole as variáveis nesse arquivo.
`--primary` é um **placeholder** até a definição da cor oficial da Lárbous.

## IA (chat)

Comandos com `/` funcionam sem IA. Para linguagem natural, defina em `config.local.php`:

```php
'anthropic' => [
    'api_key' => 'sk-ant-...',
    'timeout' => 30,                        // opcional (segundos)
    'cacert'  => 'C:/php/extras/cacert.pem', // opcional: bundle de CA (Windows/hospedagens sem certificados)
],
```

No Windows, se aparecer `unable to get local issuer certificate`, baixe <https://curl.se/ca/cacert.pem> para `storage/cacert.pem` e use `'cacert' => __DIR__ . '/storage/cacert.pem'` (não desative a verificação de TLS).

O modelo do roteador é `claude-haiku-4-5-20251001`; para trocar, grave `ia.modelo_roteador` em `configuracoes`.

### Failover (Gemini) e guardrails

Provedor secundário opcional: com a chave abaixo, se a Anthropic estiver instável (rede, timeout, 429/5xx) a chamada passa para o Gemini sozinha. Sem a chave, tudo funciona só com a Anthropic.

```php
'gemini' => ['api_key' => 'AIza...'],   // Google AI Studio; nunca no banco nem no repositório
```

Ajustes opcionais em `configuracoes` (chave → valor; padrão entre parênteses):

| Chave | Efeito |
|---|---|
| `ia.provedores` (`anthropic,gemini`) | Ordem de tentativa; só entram os que têm chave. |
| `ia.modelo_gemini_rapido` (`gemini-3.5-flash`) | Equivalente do Haiku (roteador, ações rápidas). |
| `ia.modelo_gemini_redacao` (`gemini-3.1-pro-preview`) | Equivalente do Sonnet (agentes de redação e análise). |
| `ia.deadline_total` (`100`) | Orçamento total, em segundos, somando todas as tentativas de uma chamada. |
| `ia.confianca_minima` (`0.7`) | Abaixo disso, ações de agente vão para aprovação, mesmo com `aprovacao: nunca`. |
| `ia.limite_hora` (`30`) | Execuções por hora de cada agente e de cada squad. |

Cada agente pode ter o seu corte em `confianca_minima` (0 a 1) no JSON. A tela Execuções mostra o provedor quando não é a Anthropic e quantas tentativas foram necessárias.
Limitações no Gemini: sem busca na web (o `pesquisador` só roda com a Anthropic) e sem prompt caching (custo de entrada maior).

### Áudios, resumo de conversas e follow-up (Fase 15)

- **Transcrição de áudios** (WhatsApp e Instagram): usa **só o Gemini** (a Anthropic não aceita áudio); exige `gemini.api_key` e o token do canal. O worker baixa a mídia, transcreve e grava texto, intenção e sentimento; o áudio em si não é guardado. Áudios acima de 15 MB não são transcritos. Sem chave do Gemini ou **sem créditos**, a transcrição fica desativada e os áudios esperam na fila: aparece um alerta vermelho no topo de todas as telas e, quando os créditos voltarem (o sistema testa de novo a cada 30 min, ou clique em "Já recarreguei"), eles são transcritos sozinhos.
- **Resumo ao resolver**: ao resolver uma conversa com troca de mensagens, o worker gera o resumo (fica na conversa e vira uma nota na timeline do contato). Usa o modelo rápido (`ia.modelo_roteador`) de qualquer provedor configurado.
- **Follow-up automático**: o worker aciona o agente `redator-followup` (da biblioteca; precisa estar importado e ativo, **desativá-lo desliga a cadência**) para negócios abertos sem interação real há 3, 7 e 14 dias; o rascunho fica como nota no negócio para você revisar e enviar pela Caixa de entrada. A cadência é `followup.cadencia_dias` em `configuracoes` (ex.: `5,10`). Vale o teto `ia.limite_hora`.
Para medir o acerto do roteador (meta ≥ 90%): `php scripts/avaliar-roteador.php` (`--offline` só valida o fixture; `--verbose` mostra as saídas). A avaliação usa um banco em memória, mas **chama a API real e consome tokens**.

## Agentes de IA

`php scripts/seed.php` importa os agentes (`/library/*.agent.json`) e os squads (`/library/*.squad.json`) da biblioteca que ainda não existem (nunca sobrescreve os já importados ou editados). Use a chave da API da seção anterior; modelo e `max_tokens` vêm da definição de cada agente (padrão `ia.modelo_agente` em `configuracoes`, senão `claude-sonnet-5`).
`php scripts/avaliar-agentes.php` roda cada agente sobre dados de exemplo (`--offline` só valida, `agente-slug` roda um; `--verbose` mostra o texto). Usa banco em memória, mas **chama a API real e consome tokens** (o `pesquisador`, com busca na web, usa dezenas de milhares).
A execução de um agente leva de alguns segundos a ~2 minutos (busca na web): em hospedagem compartilhada, confira o `max_execution_time` do PHP (o código pede 180 s).

## Worker (cron)

Agentes e squads disparados por evento ou agenda, squads executados pelo operador, tarefas recorrentes, tarefas vencidas, propostas expiradas e contratos vencendo/vencidos dependem do worker. Cadastre **uma** tarefa no cron da hospedagem, a cada minuto:

```
* * * * * php /caminho/do/projeto/cron/worker.php
```

- Use o mesmo `php` (8.2+) do site. No painel da hospedagem, costuma ser "Tarefas Cron" com o comando acima.
- Só um worker roda por vez (lock em `storage/worker.lock`); uma chamada que chegar com outra em andamento sai em silêncio. Sem nada a fazer, não imprime nada (não gera e-mail do cron). `php cron/worker.php --verbose` mostra o resumo da rodada.
- Cada rodada: recupera execuções interrompidas; roda as rotinas (recorrentes, vencidas, expiradas); dispara agendamentos vencidos; e processa a fila (até 5 execuções por rodada, sem começar nada novo depois de 4 minutos). Um squad com várias etapas de IA pode levar minutos: `set_time_limit(0)` no CLI; se a hospedagem limitar o tempo do cron, o restante continua na rodada seguinte.
- **Sem o worker nada roda sozinho**: os eventos apenas põem a execução na fila (a tela Execuções mostra "Na fila"). Em desenvolvimento, rode `php cron/worker.php --verbose` à mão depois de disparar um squad.
- Fuso horário das agendas: `app.fuso` (padrão `America/Sao_Paulo`). Expressões cron de 5 campos (`0 8 * * 1` = segunda 08:00); sem nomes de mês/dia nem atalhos como `@daily`.
- Avisos do worker (`tarefa.vencida`, `contrato.vencendo`, próxima tarefa recorrente) são emitidos uma vez por registro/data (tabela `worker_marcas`). Contratos avisam `aviso_renovacao_dias` antes do fim. O worker também abre tarefas de alerta para cliente ativo sem interação há 30 dias e chamado parado há 5 dias (`churn.dias_sem_interacao` e `churn.dias_chamado_parado` em `configuracoes`; 0 desliga), no máximo 20 por rodada.

## Hospedagem compartilhada

Aponte o document root para `/public`. `storage/`, `app/`, `migrations/`, `cron/` e `config*.php` ficam fora dele. `storage/` precisa ser gravável pelo usuário do PHP e do cron.

## Links públicos e banco de testes

- `app.url_publica` (em `config.local.php`) define a URL absoluta usada nos links de proposta/contrato copiados na tela (ex.: `https://crm.larbous.com.br`). Vazio = host da requisição.
- Para testar sem tocar no banco de uso: `CRM_DB_CAMINHO=/caminho/teste.sqlite php scripts/migrate.php` (idem `seed.php`, `criar-usuario.php` e `php -S`).

### Atualizar agentes da biblioteca depois de uma fase

O seed nunca sobrescreve agentes já importados. Depois da Fase 11, em bancos existentes importe `library/qualificador.agent.json` e `library/resumidor-reuniao.agent.json` em Agentes → Importar (geram a versão 2, com CHAMP; a versão 1 continua no histórico). Sem isso, os agentes antigos seguem gravando `temperatura` direto e não preenchem o CHAMP.

### Áreas dos chamados (Fase 13)

`php scripts/seed.php` cria as áreas padrão (Tráfego Pago, Design, Social Media, Web, Redação) que ainda não existem; rode-o depois de `php scripts/migrate.php` em bancos já em uso. Depois é só editar a lista em Configurações → Áreas (chamados).

## Caixa de entrada: WhatsApp, Instagram e e-mail (Fase 14)

Cada canal é opcional e só liga quando as chaves abaixo existem em `config.local.php` (nunca no banco, nunca em `config.php`). Sem elas, a tela **Caixa de entrada** funciona vazia e avisa quais canais faltam. Para receber mensagens o site precisa estar em **HTTPS** com endereço público (a Meta não chama `localhost`).

```php
'canais' => [
    'meta' => [
        'app_secret'   => '...',            // Meta for Developers → seu app → Configurações → Básico → Chave secreta do app
        'verify_token' => 'invente-um-texto-longo-e-aleatorio',   // você escolhe; o mesmo texto vai no painel da Meta
    ],
    'whatsapp' => [
        'token'           => '...',         // token permanente (usuário do sistema), com permissão whatsapp_business_messaging
        'phone_number_id' => '...',         // WhatsApp → Configuração da API → "ID do número de telefone"
    ],
    'instagram' => [
        'token'    => '...',                // token da conta profissional do Instagram
        'conta_id' => '...',                // id da conta profissional (Instagram) que recebe as mensagens
    ],
    'email' => [
        'imap_host' => 'imap.seudominio.com', 'imap_porta' => 993, 'imap_seguranca' => 'ssl',   // ssl | tls | ''
        'usuario'   => 'crm@seudominio.com',  'senha' => '...',
        'smtp_host' => 'smtp.seudominio.com', 'smtp_porta' => 465, 'smtp_seguranca' => 'ssl',   // ssl (465) ou tls (587)
        'de_email'  => 'crm@seudominio.com',  'de_nome' => 'Lárbous',
        // 'smtp_usuario' => '', 'smtp_senha' => '',   // só se forem diferentes do login do IMAP
        // 'importar_desconhecidos' => false,          // true: cria conversa até para quem ainda não é contato
        // 'intervalo_seg' => 120,                     // segundos entre duas conexões ao IMAP
    ],
],
```

**WhatsApp (Cloud API oficial) e Instagram Direct — mesmo webhook**

1. Em <https://developers.facebook.com>, crie um app do tipo *Business* e adicione o produto **WhatsApp** (e o de **Instagram**, se for usar).
2. WhatsApp: em *Configuração da API*, copie o **ID do número de telefone** e gere um **token permanente** (Business Manager → Usuários do sistema → gerar token com `whatsapp_business_messaging` e `whatsapp_business_management`). O token temporário de 24 h serve só para testar.
3. Webhook: em *Configuração* do produto, informe a **URL de callback** `https://SEU-DOMINIO/webhooks/meta` e o **Verify token** (o mesmo `verify_token` do `config.local.php`); a Meta chama a URL para verificar. Depois assine o campo **messages** (WhatsApp) e **messages** do objeto Instagram.
4. Instagram: a conta precisa ser **profissional** e estar ligada a uma Página do Facebook; o app precisa da permissão `instagram_manage_messages`, que exige **revisão do app pela Meta** (pode levar dias: comece cedo). Em modo de desenvolvimento só funcionam contas com função no app.
5. Regra da Meta que o CRM respeita: só se responde com texto livre **até 24 h depois da última mensagem do cliente**. Fora da janela é preciso um *modelo de mensagem aprovado*, que o CRM ainda não envia; a tela avisa e bloqueia o envio.

**E-mail**: use os dados de IMAP/SMTP da caixa (no hPanel: E-mails → Configuração de cliente de e-mail). A primeira coleta só marca o ponto de partida (o histórico da caixa não é importado); depois, a cada 2 minutos, entram os e-mails novos de quem já é contato ou empresa. O cron da hospedagem precisa estar rodando `cron/worker.php` a cada minuto.

Para conferir sem esperar o worker: `php cron/worker.php --verbose`. Mensagens recebidas pelo webhook aparecem na hora.

## Financeiro: cobranças pelo Asaas (Fase 17)

Cobranças (avulsas e recorrentes) são criadas no Asaas a partir da tela **Financeiro → Cobranças**; o status (pendente, pago, vencido, cancelado) chega pelo webhook. Sem as chaves abaixo em `config.local.php`, a cobrança fica salva localmente como pendente, sem tentar falar com o Asaas.

```php
'asaas' => [
    'api_key'       => '...',      // painel do Asaas → Integrações → Chave de API (use a de sandbox para testar)
    'ambiente'      => 'sandbox',  // sandbox | producao
    'webhook_token' => 'invente-um-texto-longo-e-aleatorio', // o mesmo valor vai no painel do Asaas
],
```

1. Crie a conta em <https://www.asaas.com> (ou use uma conta sandbox em <https://sandbox.asaas.com> para testar sem dinheiro real) e gere a chave de API em *Integrações → Chave de API*.
2. Em *Integrações → Webhooks*, cadastre a URL `https://SEU-DOMINIO/webhooks/asaas`, marque ao menos os eventos `PAYMENT_CREATED`, `PAYMENT_CONFIRMED`, `PAYMENT_RECEIVED`, `PAYMENT_OVERDUE`, `PAYMENT_DELETED` e `PAYMENT_REFUNDED`, e defina um **token de acesso** — o mesmo texto de `asaas.webhook_token`. O site precisa estar em **HTTPS** com endereço público (o Asaas não chama `localhost`).
3. **NF-e/NFS-e**: emitida pelo Asaas, acoplada à cobrança; a configuração fiscal (regime tributário, código de serviço, município) é feita no painel do Asaas, não no CRM. A partir de 1º/set/2026 o Emissor Nacional de NFS-e passou a valer também para ME/EPP no Simples Nacional — confirme que a conta Asaas já está migrada antes de emitir notas em produção.
4. **Empresa → Cliente no Asaas**: o cliente é criado na primeira cobrança de cada empresa (campo interno `empresas.asaas_customer_id`); cobranças seguintes da mesma empresa reaproveitam o mesmo cliente.
5. Sem o webhook chegar (ex.: ambiente ainda sem HTTPS), o worker tem um fallback: cobrança pendente que já passou do vencimento vira "vencido" sozinha, sem esperar o Asaas.

Para conferir sem esperar o Asaas: use o botão "Emitir no Asaas" na página da cobrança para tentar de novo se a emissão falhou, e o Postman/`curl` para simular um webhook (`asaas-access-token: SEU-TOKEN`) contra `/webhooks/asaas` em ambiente de teste.
