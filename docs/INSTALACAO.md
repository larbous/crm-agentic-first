# Instalação e build

## Requisitos

- PHP 8.2+ com extensões `pdo_sqlite`, `curl`, `mbstring`, `json`, `fileinfo`. A extensão `dom` (padrão na maioria das hospedagens) é usada para sanitizar o HTML dos contratos; sem ela o conteúdo é tratado como texto.
- Sem Composer e sem Node. O servidor de hospedagem nunca executa build: recebe o CSS pronto.

## Primeira execução

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

O modelo do roteador é `claude-haiku-4-5-20251001`; para trocar, grave `ia.modelo_roteador` em `configuracoes`.
Para medir o acerto do roteador (meta ≥ 90%): `php scripts/avaliar-roteador.php` (`--offline` só valida o fixture; `--verbose` mostra as saídas). A avaliação usa um banco em memória, mas **chama a API real e consome tokens**.

## Hospedagem compartilhada

Aponte o document root para `/public`. `storage/`, `app/`, `migrations/` e `config*.php` ficam fora dele.
O cron do worker será documentado na Fase 6.

## Links públicos e banco de testes

- `app.url_publica` (em `config.local.php`) define a URL absoluta usada nos links de proposta/contrato copiados na tela (ex.: `https://crm.larbous.com.br`). Vazio = host da requisição.
- Para testar sem tocar no banco de uso: `CRM_DB_CAMINHO=/caminho/teste.sqlite php scripts/migrate.php` (idem `seed.php`, `criar-usuario.php` e `php -S`).
