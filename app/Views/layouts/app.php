<?php
/**
 * Layout base: sidebar (Basecoat), topo com busca e menu do usuário, conteúdo e painel de chat recolhível.
 * @var string $conteudo
 * @var string $titulo
 * @var string $caminho caminho atual (para marcar o item ativo)
 * @var array|null $usuario
 */
use App\Core\Session;
use App\Core\View;

$caminho = $caminho ?? '/';
$usuario = $usuario ?? ['nome' => 'Operador', 'email' => ''];

$navegacao = [
    ['/', 'Início', 'house'],
    ['/empresas', 'Empresas', 'building'],
    ['/contatos', 'Contatos', 'users'],
    ['/negocios', 'Negócios', 'handshake'],
    ['/tarefas', 'Tarefas', 'list-checks'],
];
$comercial = [
    ['/propostas', 'Propostas', 'file-text'],
    ['/contratos', 'Contratos', 'file-pen'],
    ['/servicos', 'Serviços', 'package'],
    ['/modelos', 'Modelos', 'layout-template'],
];
$sistema = [
    ['/auditoria', 'Auditoria', 'scroll-text'],
    ['/configuracoes', 'Configurações', 'settings'],
    ['/ui', 'Guia de estilo', 'palette'],
];
$ativo = static fn (string $rota): bool => $rota === '/' ? $caminho === '/' : ($caminho === $rota || str_starts_with($caminho, $rota . '/'));
$item = static function (array $n) use ($ativo): string {
    return '<li><a href="' . e(url($n[0])) . '"' . ($ativo($n[0]) ? ' aria-current="page"' : '') . '>'
        . icone($n[2]) . '<span>' . e($n[1]) . '</span></a></li>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
<?= View::partial('partials/head', ['titulo' => $titulo ?? '']) ?>
</head>
<body>
<a href="#conteudo" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[100] focus:rounded-md focus:bg-background focus:px-3 focus:py-2">Ir para o conteúdo</a>

<aside class="sidebar" id="sidebar" data-side="left" aria-hidden="false">
    <nav aria-label="Navegação principal">
        <header>
            <a href="<?= e(url('/')) ?>" class="app-marca">
                <span class="app-marca-logo" aria-hidden="true">L</span>
                <span class="app-marca-nome">CRM Lárbous</span>
            </a>
        </header>
        <section>
            <div role="group" aria-labelledby="nav-crm">
                <h3 id="nav-crm">CRM</h3>
                <ul><?php foreach ($navegacao as $n) { echo $item($n); } ?></ul>
            </div>
            <div role="group" aria-labelledby="nav-comercial">
                <h3 id="nav-comercial">Comercial</h3>
                <ul><?php foreach ($comercial as $n) { echo $item($n); } ?></ul>
            </div>
            <div role="group" aria-labelledby="nav-sistema">
                <h3 id="nav-sistema">Sistema</h3>
                <ul><?php foreach ($sistema as $n) { echo $item($n); } ?></ul>
            </div>
        </section>
        <footer>
            <div class="flex items-center gap-2 px-2 py-1.5">
                <?= avatar($usuario['nome']) ?>
                <div class="min-w-0 leading-tight">
                    <div class="truncate text-sm font-medium"><?= e($usuario['nome']) ?></div>
                    <div class="text-muted-foreground truncate text-xs"><?= e($usuario['email']) ?></div>
                </div>
            </div>
        </footer>
    </nav>
</aside>

<div class="app-shell">
    <header class="topbar">
        <button type="button" class="btn" data-variant="ghost" data-size="icon" aria-label="Alternar menu lateral"
                data-alternar-sidebar><?= icone('panel-left') ?></button>

        <div class="topbar-busca busca-wrapper" data-busca>
            <form role="search" onsubmit="return false">
                <label for="busca-global" class="sr-only">Busca global</label>
                <div class="input-group">
                    <div data-align="start"><?= icone('search') ?></div>
                    <input id="busca-global" type="search" placeholder="Buscar empresas, contatos, negócios…" autocomplete="off"
                           role="combobox" aria-expanded="false" aria-controls="busca-resultados" aria-autocomplete="list">
                    <div data-align="end"><?= kbd('/') ?></div>
                </div>
            </form>
            <div id="busca-resultados" class="busca-resultados" role="listbox" aria-label="Resultados da busca" hidden></div>
        </div>

        <div class="ml-auto flex items-center gap-1">
            <button type="button" class="btn" data-variant="ghost" data-size="sm" data-alternar-chat
                    aria-controls="painel-chat" aria-expanded="false">
                <?= icone('message-square') ?><span class="hidden sm:inline">Chat</span>
                <span class="hidden sm:inline-flex"><?= kbd('Ctrl', 'K') ?></span>
            </button>
            <button type="button" class="btn" data-variant="ghost" data-size="icon" data-alternar-tema aria-label="Alternar tema claro/escuro">
                <span class="dark:hidden"><?= icone('moon') ?></span>
                <span class="hidden dark:inline"><?= icone('sun') ?></span>
            </button>
            <?= menu('menu-usuario', avatar($usuario['nome'], null, 'sm'), [
                ['grupo' => $usuario['nome']],
                ['separador' => true],
                ['rotulo' => 'Desfazer última ação', 'icone' => 'undo-2', 'attrs' => ['data-enviar-form' => 'form-desfazer']],
                ['rotulo' => 'Auditoria', 'href' => url('/auditoria'), 'icone' => 'scroll-text'],
                ['rotulo' => 'Guia de estilo', 'href' => url('/ui'), 'icone' => 'palette'],
                ['rotulo' => 'Sair', 'icone' => 'log-out', 'attrs' => ['data-enviar-form' => 'form-sair']],
            ], ['classe_gatilho' => 'btn rounded-full', 'alinhar' => 'end']) ?>
            <form id="form-desfazer" method="post" action="<?= e(url('/desfazer')) ?>" hidden><?= csrf_field() ?><input type="hidden" name="voltar" value="<?= e($caminho) ?>"></form>
            <form id="form-sair" method="post" action="<?= e(url('/logout')) ?>" hidden><?= csrf_field() ?></form>
        </div>
    </header>

    <div class="app-corpo">
        <main id="conteudo" class="app-conteudo" tabindex="-1">
            <?= $conteudo ?>
        </main>

        <aside id="painel-chat" class="chat-painel" aria-label="Chat" hidden>
            <header class="chat-painel-topo">
                <div class="flex items-center gap-2 font-medium"><?= icone('sparkles') ?> Chat</div>
                <button type="button" class="btn" data-variant="ghost" data-size="icon-sm" data-alternar-chat aria-label="Fechar chat"><?= icone('x') ?></button>
            </header>
            <div class="chat-painel-corpo" aria-live="polite">
                <?= vazio('Chat em breve', 'Aqui você dará comandos e conversará com os agentes. Disponível a partir da Fase 4.', ['icone' => 'message-square']) ?>
            </div>
            <div class="chat-painel-entrada">
                <div class="input-group">
                    <input type="text" placeholder="Escreva um comando ou mensagem…" disabled aria-label="Mensagem">
                    <div data-align="end"><button type="button" class="btn" data-variant="ghost" data-size="icon-sm" disabled aria-label="Enviar"><?= icone('send') ?></button></div>
                </div>
            </div>
        </aside>
    </div>
</div>

<div id="toaster" class="toaster" aria-live="polite"></div>
<script type="application/json" id="flash-dados"><?= json_encode(Session::pegarFlash(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="<?= e(asset('vendor/basecoat/all.min.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/ui.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/table.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/chat.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/busca.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/forms.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/kanban.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/propostas.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/modelos.js')) ?>"></script>
</body>
</html>
