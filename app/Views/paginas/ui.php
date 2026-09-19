<?php
/**
 * Guia de estilo (/ui): todos os componentes, variações e estados. Alternar o tema pelo botão do topo
 * mostra tudo em claro e escuro (a página usa as mesmas variáveis do tema).
 */

$secoes = [
    'cores' => 'Cores e tema', 'tipografia' => 'Tipografia', 'botoes' => 'Botões', 'badges' => 'Badges e etapas',
    'alertas' => 'Alertas e toasts', 'formularios' => 'Formulários', 'cards' => 'Cards', 'tabs' => 'Tabs e acordeão',
    'tabelas' => 'Tabelas', 'vazio' => 'Estado vazio e atalhos', 'menus' => 'Menus, modal e tooltip',
    'feedback' => 'Avatar, progresso e skeleton', 'navegacao' => 'Navegação',
];

/** Seção do guia: título + conteúdo em um card. */
$secao = static function (string $id, string $titulo, string $html, string $descricao = '') use ($secoes): string {
    return '<section id="' . e($id) . '" class="ui-secao">'
        . card(['titulo' => $titulo, 'descricao' => $descricao !== '' ? $descricao : null, 'corpo_html' => '<div class="grid gap-6">' . $html . '</div>'])
        . '</section>';
};
$grupo = static fn (string $rotulo, string $html): string =>
    '<div class="grid gap-2"><div class="text-muted-foreground text-xs font-medium tracking-wide uppercase">' . e($rotulo) . '</div>'
    . '<div class="ui-amostra">' . $html . '</div></div>';

$cores = ['background', 'foreground', 'card', 'primary', 'secondary', 'muted', 'accent', 'destructive', 'border', 'ring',
    'success', 'warning', 'info', 'temp-frio', 'temp-morno', 'temp-quente', 'chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5'];
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Guia de estilo</h1>
        <p class="text-muted-foreground">Componentes, variações e estados. Use o botão de tema no topo para alternar entre claro e escuro.</p>
    </div>
    <div class="flex flex-wrap gap-1.5">
        <?php foreach ($secoes as $id => $rotulo) {
            echo botao($rotulo, ['variante' => 'outline', 'tamanho' => 'xs', 'href' => '#' . $id]);
        } ?>
    </div>
</div>

<?php
// ---- Cores
$amostras = '';
foreach ($cores as $cor) {
    $amostras .= '<div class="flex items-center gap-2"><span class="ui-cor" style="background: var(--' . e($cor) . ')"></span>'
        . '<code class="text-xs">--' . e($cor) . '</code></div>';
}
echo $secao('cores', 'Cores e tema', '<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">' . $amostras . '</div>',
    'Variáveis do tema shadcn definidas em assets-src/theme.css. A cor primária é um placeholder até a definição da cor oficial da Lárbous.');

// ---- Tipografia
echo $secao('tipografia', 'Tipografia', '
    <div class="grid gap-2">
        <h1 class="text-2xl font-semibold tracking-tight">Título da página — text-2xl</h1>
        <h2 class="text-lg font-semibold">Título de seção — text-lg</h2>
        <p>Texto base de 14px (text-sm) em Inter. Listas e tabelas usam este tamanho.</p>
        <p class="text-muted-foreground">Texto secundário (muted-foreground).</p>
        <p class="text-xs">Texto auxiliar de 12px. <code class="bg-muted rounded px-1">código inline</code></p>
        <p><a class="underline underline-offset-4" href="#tipografia">Link no texto</a></p>
    </div>');

// ---- Botões
$variantes = ['primary', 'secondary', 'outline', 'ghost', 'link', 'destructive'];
$b = '';
foreach ($variantes as $v) { $b .= botao(ucfirst($v), ['variante' => $v]); }
$t = '';
foreach (['xs', 'sm', 'default', 'lg'] as $tam) { $t .= botao('Tamanho ' . $tam, ['tamanho' => $tam]); }
$i = botao('Adicionar', ['icone' => 'plus']) . botao('Editar', ['icone' => 'pencil', 'variante' => 'outline'])
    . botao('Configurações', ['tamanho' => 'icon', 'icone' => 'settings', 'variante' => 'outline'])
    . botao('Fechar', ['tamanho' => 'icon-sm', 'icone' => 'x', 'variante' => 'ghost']);
$d = '';
foreach ($variantes as $v) { $d .= botao('Desabilitado', ['variante' => $v, 'attrs' => ['disabled' => true]]); }
$grupoBotoes = '<div class="button-group">' . botao('Dia', ['variante' => 'outline']) . botao('Semana', ['variante' => 'outline']) . botao('Mês', ['variante' => 'outline']) . '</div>';
echo $secao('botoes', 'Botões', $grupo('Variantes', $b) . $grupo('Tamanhos', $t) . $grupo('Com ícone', $i)
    . $grupo('Desabilitados', $d) . $grupo('Grupo de botões', $grupoBotoes));

// ---- Badges e etapas
$bd = '';
foreach (['primary', 'secondary', 'outline', 'destructive', 'ghost', 'success', 'warning', 'info'] as $v) { $bd .= badge($v, $v); }
$etapas = [['Novo lead', '#64748b'], ['Qualificado', '#0ea5e9'], ['Reunião', '#8b5cf6'], ['Proposta', '#f59e0b'],
    ['Negociação', '#f97316'], ['Ganho', '#22c55e'], ['Perdido', '#ef4444']];
$pe = '';
foreach ($etapas as [$nome, $cor]) { $pe .= pill_etapa($nome, $cor); }
$temp = badge('Frio', 'outline', ['classe' => 'text-temp-frio']) . badge('Morno', 'outline', ['classe' => 'text-temp-morno'])
    . badge('Quente', 'outline', ['classe' => 'text-temp-quente']);
echo $secao('badges', 'Badges e etapas', $grupo('Badges', $bd) . $grupo('Pílulas de etapa (cor vem do banco)', $pe)
    . $grupo('Temperatura', $temp) . $grupo('Com ícone', badge('Verificado', 'success', ['icone' => 'check'])));

// ---- Alertas e toasts
$toasts = '';
foreach (['success' => 'Sucesso', 'error' => 'Erro', 'info' => 'Info', 'warning' => 'Aviso'] as $cat => $rot) {
    $toasts .= botao($rot, ['variante' => 'outline', 'attrs' => ['data-toast-demo' => $cat]]);
}
echo $secao('alertas', 'Alertas e toasts',
    alerta('Informação', 'Uma mensagem neutra para o operador.')
    . alerta('Algo deu errado', 'Não foi possível salvar. Verifique os campos e tente de novo.', 'destructive')
    . $grupo('Toasts (aria-live)', $toasts));

// ---- Formulários
$form = '<div class="grid gap-4 md:grid-cols-2">'
    . campo(['nome' => 'ui_nome', 'rotulo' => 'Nome fantasia', 'placeholder' => 'Padaria Central', 'obrigatorio' => true, 'ajuda' => 'Como a empresa é conhecida.'])
    . campo(['nome' => 'ui_email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'valor' => 'email-invalido', 'erro' => 'Informe um e-mail válido.'])
    . campo(['nome' => 'ui_desab', 'rotulo' => 'Campo desabilitado', 'valor' => 'Somente leitura', 'desabilitado' => true])
    . campo(['nome' => 'ui_status', 'rotulo' => 'Status', 'controle_html' => select('ui_status', ['lead' => 'Lead', 'prospect' => 'Prospect', 'cliente' => 'Cliente'], 'prospect')])
    . campo(['nome' => 'ui_valor', 'rotulo' => 'Valor estimado (R$)', 'valor' => centavos_para_texto(800000), 'attrs' => ['inputmode' => 'decimal']])
    . campo(['nome' => 'ui_data', 'rotulo' => 'Previsão de fechamento', 'tipo' => 'date', 'valor' => hoje()])
    . '<div class="md:col-span-2">' . campo(['nome' => 'ui_notas', 'rotulo' => 'Notas', 'tipo' => 'textarea', 'placeholder' => 'Observações internas…']) . '</div>'
    . campo(['nome' => 'ui_check', 'rotulo' => 'Aceita receber comunicações', 'tipo' => 'checkbox', 'valor' => true])
    . campo(['nome' => 'ui_switch', 'rotulo' => 'Usa tráfego pago', 'tipo' => 'switch'])
    . '</div>';
echo $secao('formularios', 'Formulários', $form . '<div class="ui-amostra">' . botao('Salvar', ['tipo' => 'submit']) . botao('Cancelar', ['variante' => 'outline']) . '</div>');

// ---- Cards
$c1 = card(['titulo' => 'Card com ação', 'descricao' => 'Descrição curta do conteúdo.', 'corpo_html' => '<p>Corpo do card.</p>',
    'rodape_html' => botao('Ação', ['tamanho' => 'sm'])]);
$c2 = card(['titulo' => 'Card compacto', 'tamanho' => 'sm', 'corpo_html' => '<p class="text-muted-foreground">Versão sm.</p>']);
$metrica = card(['corpo_html' => '<div class="text-muted-foreground text-xs">Pipeline ponderado</div><div class="text-2xl font-semibold">' . e(moeda(4250000)) . '</div>'
    . '<div class="text-success text-xs">+12% no mês</div>']);
echo $secao('cards', 'Cards', '<div class="grid gap-4 md:grid-cols-3">' . $c1 . $c2 . $metrica . '</div>');

// ---- Tabs e acordeão
$tabs = '<div class="tabs" id="ui-tabs"><nav role="tablist" aria-orientation="horizontal">'
    . '<button type="button" role="tab" id="ui-tabs-tab-1" aria-controls="ui-tabs-panel-1" aria-selected="true" tabindex="0">Dados</button>'
    . '<button type="button" role="tab" id="ui-tabs-tab-2" aria-controls="ui-tabs-panel-2" aria-selected="false" tabindex="-1">Contato</button>'
    . '<button type="button" role="tab" id="ui-tabs-tab-3" aria-controls="ui-tabs-panel-3" aria-selected="false" tabindex="-1">Endereço</button></nav>'
    . '<div role="tabpanel" id="ui-tabs-panel-1" aria-labelledby="ui-tabs-tab-1" tabindex="-1" class="pt-4">Conteúdo da aba Dados.</div>'
    . '<div role="tabpanel" id="ui-tabs-panel-2" aria-labelledby="ui-tabs-tab-2" tabindex="-1" class="pt-4" hidden>Conteúdo da aba Contato.</div>'
    . '<div role="tabpanel" id="ui-tabs-panel-3" aria-labelledby="ui-tabs-tab-3" tabindex="-1" class="pt-4" hidden>Conteúdo da aba Endereço.</div></div>';
$acordeao = '<section class="accordion">'
    . '<details class="group border-b" open><summary>Como funciona o funil?</summary><p>Os negócios avançam por etapas até ganho ou perdido.</p></details>'
    . '<details class="group border-b"><summary>Onde ficam as etapas?</summary><p>Em Configurações → Pipelines (Fase 2).</p></details></section>';
echo $secao('tabs', 'Tabs e acordeão', $tabs . $acordeao);

// ---- Tabelas
$colunas = ['nome' => 'Empresa', 'etapa' => 'Etapa', 'valor' => 'Valor', 'ultimo' => 'Último contato'];
$linhas = [
    ['nome' => 'Padaria Central', 'etapa' => ['html' => pill_etapa('Proposta', '#f59e0b'), 'valor' => 'Proposta'], 'valor' => ['html' => e(moeda(800000)), 'valor' => 800000], 'ultimo' => '19/09/2026'],
    ['nome' => 'Studio Aurora', 'etapa' => ['html' => pill_etapa('Qualificado', '#0ea5e9'), 'valor' => 'Qualificado'], 'valor' => ['html' => e(moeda(320000)), 'valor' => 320000], 'ultimo' => '15/09/2026'],
    ['nome' => 'Mercado Bom Preço', 'etapa' => ['html' => pill_etapa('Ganho', '#22c55e'), 'valor' => 'Ganho'], 'valor' => ['html' => e(moeda(1500000)), 'valor' => 1500000], 'ultimo' => '02/09/2026'],
];
$dtCols = [
    'nome'   => ['rotulo' => 'Empresa', 'ordenavel' => true],
    'etapa'  => ['rotulo' => 'Etapa', 'ordenavel' => true],
    'valor'  => ['rotulo' => 'Valor', 'ordenavel' => true, 'tipo' => 'numero', 'alinhar' => 'direita'],
    'ultimo' => ['rotulo' => 'Último contato'],
];
echo $secao('tabelas', 'Tabelas',
    $grupo('Tabela simples (tabela())', '') . tabela($colunas, $linhas)
    . $grupo('data-table: ordenação, seleção e cabeçalho fixo', '')
    . data_table($dtCols, $linhas, ['selecionavel' => true, 'id' => 'ui-dt'])
    . $grupo('data-table vazia', '')
    . data_table($dtCols, [], ['id' => 'ui-dt-vazia', 'vazio_html' => vazio('Nenhuma empresa encontrada', 'Ajuste os filtros ou cadastre uma nova empresa.', ['icone' => 'building', 'acao_html' => botao('Nova empresa', ['icone' => 'plus'])])]));

// ---- Vazio e atalhos
echo $secao('vazio', 'Estado vazio e atalhos',
    vazio('Nenhuma tarefa para hoje', 'Quando houver tarefas, elas aparecem aqui.', ['icone' => 'list-checks', 'acao_html' => botao('Nova tarefa', ['icone' => 'plus'])])
    . $grupo('Atalhos de teclado', kbd('/') . kbd('Ctrl', 'K') . kbd('Esc')));

// ---- Menus, modal e tooltip
$mn = menu('ui-menu', 'Ações ' . icone('chevron-down'), [
    ['grupo' => 'Empresa'],
    ['rotulo' => 'Editar', 'icone' => 'pencil', 'atalho' => 'E', 'href' => '#menus'],
    ['rotulo' => 'Exportar', 'icone' => 'download', 'href' => '#menus'],
    ['separador' => true],
    ['rotulo' => 'Arquivar', 'icone' => 'x', 'perigo' => true],
]);
$md = botao('Abrir modal', ['variante' => 'outline', 'attrs' => ['data-abrir-modal' => 'ui-modal']])
    . modal('ui-modal', ['titulo' => 'Arquivar empresa?', 'descricao' => 'A empresa some das listas, mas pode ser restaurada pelo Desfazer.',
        'rodape_html' => botao('Cancelar', ['variante' => 'outline', 'attrs' => ['onclick' => "this.closest('dialog').close()"]]) . botao('Arquivar', ['variante' => 'destructive'])]);
$tp = '<button type="button" class="btn" data-variant="outline" data-tooltip="Dica ao passar o mouse">Tooltip</button>'
    . '<button type="button" class="btn" data-variant="outline" data-tooltip="Abaixo" data-side="bottom">Tooltip abaixo</button>';
echo $secao('menus', 'Menus, modal e tooltip', $grupo('Menu suspenso', $mn) . $grupo('Modal (dialog nativo)', $md) . $grupo('Tooltip', $tp));

// ---- Avatar, progresso e skeleton
$av = avatar('Ana Souza', null, 'sm') . avatar('Bruno Lima') . avatar('Carla Dias', null, 'lg');
$pg = '<div class="grid w-full max-w-sm gap-3"><div class="progress" role="progressbar" aria-valuenow="60" aria-valuemin="0" aria-valuemax="100"><span style="width:60%"></span></div>'
    . '<div class="progress" role="progressbar" aria-valuenow="25" aria-valuemin="0" aria-valuemax="100"><span style="width:25%"></span></div></div>';
$sk = '<div class="flex w-full max-w-sm items-center gap-3"><div class="skeleton size-10 shrink-0 rounded-full"></div>'
    . '<div class="grid flex-1 gap-2"><div class="skeleton h-3 w-3/4"></div><div class="skeleton h-3 w-1/2"></div></div></div>';
echo $secao('feedback', 'Avatar, progresso e skeleton', $grupo('Avatares (sm, padrão, lg)', $av) . $grupo('Progresso', $pg) . $grupo('Skeleton', $sk));

// ---- Navegação
$bc = '<nav class="breadcrumb" aria-label="Breadcrumb"><ol class="text-muted-foreground gap-1.5 text-sm">'
    . '<li><a href="' . e(url('/')) . '" class="hover:text-foreground">Início</a></li><li aria-hidden="true">' . icone('chevron-right', 'size-3.5') . '</li>'
    . '<li><a href="' . e(url('/empresas')) . '" class="hover:text-foreground">Empresas</a></li><li aria-hidden="true">' . icone('chevron-right', 'size-3.5') . '</li>'
    . '<li><span aria-current="page" class="text-foreground">Padaria Central</span></li></ol></nav>';
$pag = '<div class="ui-amostra">' . botao('Anterior', ['variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'chevron-left'])
    . botao('1', ['variante' => 'outline', 'tamanho' => 'icon-sm']) . botao('2', ['variante' => 'primary', 'tamanho' => 'icon-sm'])
    . botao('3', ['variante' => 'outline', 'tamanho' => 'icon-sm']) . botao('Próxima', ['variante' => 'outline', 'tamanho' => 'sm', 'rotulo_html' => 'Próxima' . icone('chevron-right')]) . '</div>';
echo $secao('navegacao', 'Navegação', $grupo('Breadcrumb', $bc) . $grupo('Paginação', $pag),
    'A sidebar, o topo com busca, o painel de chat (Ctrl+K) e o alternador de tema são o próprio layout desta página.');
?>
