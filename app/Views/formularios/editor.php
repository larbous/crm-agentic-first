<?php
/**
 * Editor de formulário de captação: configuração + construtor de campos (formularios.js) + código de incorporação.
 * @var array|null $formulario linha salva (null = novo)
 * @var array $cfg valores da configuração (banco ou POST)
 * @var list<array> $campos campos como estão no construtor
 * @var array<string,string> $erros
 * @var list<array> $destinos destinos de campo disponíveis (FormularioDefinicao::destinos)
 * @var array<int,string> $origens
 * @var array<int,string> $etapas
 * @var array<string,string> $squads slug => nome
 * @var string $tipo 'captacao' ou 'pesquisa' (pesquisa de satisfação NPS)
 */
$pesquisa = $tipo === 'pesquisa';
$id = $formulario !== null ? (int) $formulario['id'] : null;
$acao = $id !== null ? url("/formularios/{$id}") : url('/formularios');
$v = static fn (string $k, mixed $padrao = ''): mixed => $cfg[$k] ?? $padrao;
$marcado = static fn (string $k, int $padrao = 0): bool => (int) ($cfg[$k] ?? $padrao) === 1 || ($cfg[$k] ?? null) === 'on';

$alerta = '';
if ($erros !== []) {
    $itens = '';
    foreach ($erros as $mensagem) {
        $itens .= '<li>' . e($mensagem) . '</li>';
    }
    $alerta = '<div class="alert" role="alert" data-variant="destructive">' . icone('circle-alert') . '<h2>O formulário não foi salvo</h2><section><ul class="list-inside list-disc">' . $itens . '</ul></section></div>';
}

$camposJson = json_encode(array_map(static fn (array $c): array => [
    'campo_destino' => $c['campo_destino'], 'rotulo' => $c['rotulo'], 'tipo' => $c['tipo'] ?? null, 'placeholder' => $c['placeholder'] ?? '',
    'ajuda' => $c['ajuda'] ?? '', 'obrigatorio' => (int) ($c['obrigatorio'] ?? 0), 'opcoes' => array_values((array) ($c['opcoes'] ?? [])), 'largura' => (int) ($c['largura'] ?? 12),
], $campos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$destinosJson = json_encode(array_map(static fn (array $d): array => [
    'destino' => $d['destino'], 'rotulo' => $d['rotulo'], 'grupo' => $d['grupo'], 'tipos' => $d['tipos'], 'padrao' => $d['padrao'],
    'opcoes_sistema' => $d['opcoes'] !== null ? array_values($d['opcoes']) : null,
], $destinos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$err = static fn (string $k): ?string => $erros[$k] ?? null;

// ---- Configuração
$config = '<div class="grid gap-4 md:grid-cols-2">'
    . campo(['nome' => 'nome', 'rotulo' => 'Nome (uso interno)', 'valor' => $v('nome'), 'obrigatorio' => true, 'erro' => $err('nome'), 'ajuda' => 'Ex.: Contato do site.'])
    . campo(['nome' => 'titulo', 'rotulo' => 'Título exibido no formulário', 'valor' => $v('titulo'), 'erro' => $err('titulo'), 'ajuda' => 'Opcional.'])
    . campo(['nome' => 'texto_botao', 'rotulo' => 'Texto do botão', 'valor' => $v('texto_botao', 'Enviar'), 'erro' => $err('texto_botao')])
    . ($pesquisa ? '' : campo(['nome' => 'redirect_url', 'rotulo' => 'Redirecionar depois do envio', 'valor' => $v('redirect_url'), 'erro' => $err('redirect_url'), 'placeholder' => 'https://…/obrigado', 'ajuda' => 'Opcional. Em branco, mostra a mensagem abaixo.']))
    . '<div class="md:col-span-2">' . campo(['nome' => 'mensagem_sucesso', 'rotulo' => 'Mensagem de sucesso', 'tipo' => 'textarea', 'linhas' => 2, 'valor' => $v('mensagem_sucesso', $pesquisa ? 'Obrigado pela sua resposta!' : 'Recebemos suas informações. Em breve entraremos em contato.'), 'erro' => $err('mensagem_sucesso')]) . '</div>'
    . '</div>';

$processamento = '<div class="grid gap-4 md:grid-cols-2">'
    . campo(['nome' => 'origem_id_padrao', 'rotulo' => 'Origem do lead', 'erro' => $err('origem_id_padrao'), 'controle_html' => select('origem_id_padrao', $origens, $v('origem_id_padrao'), ['placeholder' => '— Nenhuma —', 'id' => 'campo-origem_id_padrao']), 'ajuda' => 'Gravada na empresa, no contato e no negócio criados.'])
    . campo(['nome' => 'status_padrao', 'rotulo' => 'Status da empresa criada', 'erro' => $err('status_padrao'), 'controle_html' => select('status_padrao', ['lead' => 'Lead', 'prospect' => 'Prospect'], $v('status_padrao', 'lead'), ['id' => 'campo-status_padrao'])])
    . campo(['nome' => 'regra_duplicado', 'rotulo' => 'Se a pessoa ou empresa já existir', 'erro' => $err('regra_duplicado'), 'controle_html' => select('regra_duplicado', [
        'tarefa' => 'Criar uma tarefa para revisar (não altera nada)', 'mesclar' => 'Mesclar (preenche só campos vazios)', 'criar' => 'Criar novos registros mesmo assim',
    ], $v('regra_duplicado', 'tarefa'), ['id' => 'campo-regra_duplicado']), 'ajuda' => 'Comparação por e-mail, WhatsApp, CNPJ e domínio do site.'])
    . campo(['nome' => 'squad_disparado', 'rotulo' => 'Squad a disparar a cada envio', 'erro' => $err('squad_disparado'), 'controle_html' => select('squad_disparado', $squads, $v('squad_disparado'), ['placeholder' => '— Nenhum —', 'id' => 'campo-squad_disparado']), 'ajuda' => 'Roda em segundo plano (worker). Squads com gatilho "formulario.submetido" também disparam.'])
    . campo(['nome' => 'criar_negocio', 'rotulo' => 'Criar um negócio a cada envio', 'tipo' => 'switch', 'valor' => $marcado('criar_negocio'), 'attrs' => ['data-fb-negocio' => true]])
    . campo(['nome' => 'etapa_id_padrao', 'rotulo' => 'Etapa inicial do negócio', 'erro' => $err('etapa_id_padrao'), 'controle_html' => select('etapa_id_padrao', $etapas, $v('etapa_id_padrao'), ['placeholder' => '— Primeira etapa do pipeline padrão —', 'id' => 'campo-etapa_id_padrao'])])
    . campo(['nome' => 'ativo', 'rotulo' => 'Formulário ativo (aceita envios)', 'tipo' => 'switch', 'valor' => $marcado('ativo', 1)])
    . '</div>';

// ---- Envio da pesquisa (só tipo pesquisa)
$envioPesquisa = '<div class="grid gap-4 md:grid-cols-2">'
    . campo(['nome' => 'gatilho_tipo', 'rotulo' => 'Quando criar as pesquisas sozinho', 'erro' => $err('gatilho_tipo'), 'controle_html' => select('gatilho_tipo', \App\Services\FormularioDefinicao::GATILHOS, $v('gatilho_tipo', 'manual'), ['id' => 'campo-gatilho_tipo']),
        'ajuda' => 'O worker cria a pesquisa e uma tarefa "Enviar pesquisa" com o link. "Só manual": você cria na tela da empresa ou em Pesquisas NPS.'])
    . campo(['nome' => 'gatilho_dias', 'rotulo' => 'Dias', 'valor' => $v('gatilho_dias'), 'erro' => $err('gatilho_dias'), 'attrs' => ['inputmode' => 'numeric'],
        'ajuda' => 'Após contrato assinado: dias depois da assinatura. Periódica: intervalo entre pesquisas do mesmo cliente. Ignorado no modo manual.'])
    . campo(['nome' => 'validade_dias', 'rotulo' => 'Validade do link (dias)', 'valor' => $v('validade_dias', 30), 'erro' => $err('validade_dias'), 'attrs' => ['inputmode' => 'numeric']])
    . campo(['nome' => 'tarefa_detrator', 'rotulo' => 'Abrir tarefa de ligação quando a nota for 0 a 6 (detrator)', 'tipo' => 'switch', 'valor' => $marcado('tarefa_detrator', 1)])
    . campo(['nome' => 'ativo', 'rotulo' => 'Pesquisa ativa (cria e aceita respostas)', 'tipo' => 'switch', 'valor' => $marcado('ativo', 1)])
    . '</div>';

// ---- Construtor
$construtor = '<div data-fb data-destinos="' . e($destinosJson) . '" data-largura-max="12">'
    . '<p class="text-muted-foreground mb-3 text-sm">Arraste pelo ícone <span aria-hidden="true">⋮⋮</span> (ou use as setas) para reordenar. A largura vai de 1 a 12 colunas (12 = linha inteira); no celular todos ocupam a linha inteira. ' . ($pesquisa
        ? 'A nota de 0 a 10 é obrigatória (é ela que calcula o NPS); o comentário e até 8 perguntas extras são opcionais. Quem responde é identificado pelo link individual, então não há campos de nome.'
        : 'Inclua o campo que identifica quem enviou (nome da empresa ou do contato) e marque-o como obrigatório.') . '</p>'
    . '<div class="flex flex-wrap items-end gap-2 pb-3">'
    . '<div class="field min-w-64 flex-1"><label for="fb-novo">Adicionar campo</label><select id="fb-novo" class="select" data-fb-novo></select></div>'
    . botao('Adicionar', ['variante' => 'outline', 'icone' => 'plus', 'attrs' => ['data-fb-adicionar' => true]])
    . '</div>'
    . '<ol class="fb-lista" data-fb-lista aria-label="Campos do formulário"></ol>'
    . '<h3 class="mt-4 mb-2 text-sm font-medium">Pré-visualização</h3><div class="fb-previa" data-fb-previa></div>'
    . '<textarea name="campos_json" class="sr-only" tabindex="-1" aria-hidden="true" data-fb-json>' . e($camposJson) . '</textarea>'
    . '</div>';

// ---- Incorporação
$incorporar = '';
if ($id !== null && !$pesquisa) {
    $publico = url_publica('/f/' . $formulario['chave']);
    $script = '<script src="' . url_publica('/assets/js/embed-formulario.js') . '" data-formulario="' . $formulario['chave'] . '" async></script>';
    $iframe = '<iframe src="' . $publico . '?embed=1" style="width:100%;border:0;min-height:520px" title="' . e($formulario['nome']) . '"></iframe>';
    $bloco = static fn (string $titulo, string $texto, string $rotulo = 'Copiar'): string => '<div class="grid gap-1"><div class="flex items-center justify-between gap-2"><span class="text-sm font-medium">' . e($titulo) . '</span>'
        . botao($rotulo, ['variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'copy', 'attrs' => ['data-copiar' => $texto, 'type' => 'button']]) . '</div>'
        . '<pre class="bg-muted overflow-x-auto rounded-md p-3 text-xs"><code>' . e($texto) . '</code></pre></div>';
    $incorporar = card(['titulo' => 'Publicar', 'descricao' => 'O link e o código só funcionam com o formulário ativo.', 'corpo_html' => '<div class="grid gap-4">'
        . $bloco('Link público', $publico)
        . $bloco('Código para incorporar (recomendado: ajusta a altura e repassa as UTMs da página)', $script)
        . $bloco('Alternativa: iframe simples (sem repasse de UTMs da página-mãe)', $iframe)
        . '</div>']);
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo"><?= e($titulo) ?></h1>
        <?php if ($id !== null): ?>
            <p class="text-muted-foreground"><?php if ($pesquisa): ?><a class="underline underline-offset-4" href="<?= e(url('/pesquisas?formulario=' . $id)) ?>">Ver respostas</a><?php else: ?><a class="underline underline-offset-4" href="<?= e(url('/formularios/submissoes?formulario=' . $id)) ?>">Ver submissões</a><?php endif; ?></p>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= e($acao) ?>" class="grid gap-4" novalidate data-fb-form>
    <?= csrf_field() ?>
    <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
    <?= $alerta ?>
    <?= card(['titulo' => $pesquisa ? 'Pesquisa' : 'Formulário', 'corpo_html' => $config]) ?>
    <?= card(['titulo' => $pesquisa ? 'Perguntas' : 'Campos', 'corpo_html' => $construtor]) ?>
    <?= $pesquisa ? card(['titulo' => 'Envio', 'corpo_html' => $envioPesquisa]) : card(['titulo' => 'O que acontece a cada envio', 'corpo_html' => $processamento]) ?>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Salvar', ['tipo' => 'submit', 'icone' => 'check']) ?>
        <?= botao('Voltar', ['href' => url('/formularios'), 'variante' => 'outline']) ?>
    </div>
</form>

<?php if ($id !== null): ?>
    <div class="mt-4 grid gap-4">
        <?= $incorporar ?>
        <form method="post" action="<?= e(url("/formularios/{$id}/arquivar")) ?>" data-confirmar="<?= $pesquisa ? 'Arquivar esta pesquisa? Os links pendentes deixam de funcionar (as respostas continuam guardadas).' : 'Arquivar este formulário? O link público deixa de funcionar (as submissões continuam guardadas).' ?>">
            <?= csrf_field() ?>
            <?= botao('Arquivar formulário', ['tipo' => 'submit', 'variante' => 'destructive', 'icone' => 'archive']) ?>
        </form>
    </div>
<?php endif; ?>
