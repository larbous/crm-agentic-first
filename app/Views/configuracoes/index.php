<?php
/**
 * Configurações: pipelines/etapas, origens, motivos de perda e tags.
 * @var string $aba
 * @var list<array> $pipelines
 * @var array<int,list<array>> $etapas
 * @var list<array> $origens
 * @var list<array> $motivos
 * @var list<array> $tags
 * @var list<array> $tiposContrato
 * @var array<string,list<array>> $camposExtras definições por entidade (inclui inativas)
 * @var array $agencia
 */
use App\Services\Schema;

/** Formulários das linhas ficam fora das tabelas; os controles se ligam a eles pelo atributo form="". */
$formsOcultos = '';
$formLinha = static function (string $tipo, int $id, string $formId) use (&$formsOcultos): void {
    $formsOcultos .= '<form id="' . e($formId) . '" method="post" action="' . e(url("/configuracoes/{$tipo}/{$id}")) . '">' . csrf_field() . '</form>';
};
$formArquivar = static function (string $tipo, int $id, string $formId, string $msg) use (&$formsOcultos): void {
    $formsOcultos .= '<form id="' . e($formId) . '" method="post" action="' . e(url("/configuracoes/{$tipo}/{$id}/arquivar")) . '" data-confirmar="' . e($msg) . '">' . csrf_field() . '</form>';
};
$input = static fn (string $nome, string $valor, string $formId, array $extra = []): string
    => '<input class="input" name="' . e($nome) . '" value="' . e($valor) . '" form="' . e($formId) . '"' . attrs_html($extra) . '>';

// ---- Listas simples (origens, motivos de perda)
$listaSimples = static function (string $tipo, array $itens, string $novoRotulo) use ($formLinha, $formArquivar, $input): string {
    $linhas = '';
    foreach ($itens as $i) {
        $id = (int) $i['id'];
        $f = "f-{$tipo}-{$id}";
        $formLinha($tipo, $id, $f);
        $formArquivar($tipo, $id, "a-{$tipo}-{$id}", 'Arquivar "' . $i['nome'] . '"?');
        $linhas .= '<tr><td>' . $input('nome', $i['nome'], $f, ['required' => true, 'maxlength' => 80, 'aria-label' => 'Nome']) . '</td>'
            . '<td class="w-px whitespace-nowrap">' . botao('Salvar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'outline', 'attrs' => ['form' => $f]])
            . ' ' . botao('Arquivar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'ghost', 'classe' => 'text-destructive', 'attrs' => ['form' => "a-{$tipo}-{$id}"]]) . '</td></tr>';
    }
    $novo = '<form method="post" action="' . e(url("/configuracoes/{$tipo}")) . '" class="mt-4 flex flex-wrap items-end gap-2">' . csrf_field()
        . '<div class="min-w-56 flex-1">' . campo(['nome' => 'nome', 'id' => "novo-{$tipo}", 'rotulo' => $novoRotulo, 'obrigatorio' => true, 'attrs' => ['maxlength' => 80]]) . '</div>'
        . botao('Adicionar', ['tipo' => 'submit', 'icone' => 'plus']) . '</form>';
    return ($linhas !== ''
        ? '<div class="table-container"><table class="table"><thead><tr><th>Nome</th><th></th></tr></thead><tbody>' . $linhas . '</tbody></table></div>'
        : vazio('Nada cadastrado', 'Adicione o primeiro item abaixo.', ['icone' => 'inbox'])) . $novo;
};

// ---- Tags
$htmlTags = '';
foreach ($tags as $t) {
    $id = (int) $t['id'];
    $f = "f-tags-{$id}";
    $formLinha('tags', $id, $f);
    $formArquivar('tags', $id, "a-tags-{$id}", 'Arquivar a tag "' . $t['nome'] . '"?');
    $htmlTags .= '<tr><td>' . $input('nome', $t['nome'], $f, ['required' => true, 'maxlength' => 60, 'aria-label' => 'Nome']) . '</td>'
        . '<td class="w-24"><input class="input h-8 p-1" type="color" name="cor" value="' . e($t['cor'] ?: '#64748b') . '" form="' . e($f) . '" aria-label="Cor"></td>'
        . '<td class="w-px whitespace-nowrap">' . botao('Salvar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'outline', 'attrs' => ['form' => $f]])
        . ' ' . botao('Arquivar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'ghost', 'classe' => 'text-destructive', 'attrs' => ['form' => "a-tags-{$id}"]]) . '</td></tr>';
}
$painelTags = ($htmlTags !== ''
    ? '<div class="table-container"><table class="table"><thead><tr><th>Nome</th><th>Cor</th><th></th></tr></thead><tbody>' . $htmlTags . '</tbody></table></div>'
    : vazio('Nenhuma tag', 'Tags organizam empresas, contatos e negócios.', ['icone' => 'tag']))
    . '<form method="post" action="' . e(url('/configuracoes/tags')) . '" class="mt-4 flex flex-wrap items-end gap-2">' . csrf_field()
    . '<div class="min-w-56 flex-1">' . campo(['nome' => 'nome', 'id' => 'nova-tag', 'rotulo' => 'Nova tag', 'obrigatorio' => true, 'attrs' => ['maxlength' => 60]]) . '</div>'
    . '<div class="w-24">' . campo(['nome' => 'cor', 'id' => 'nova-tag-cor', 'rotulo' => 'Cor', 'tipo' => 'color', 'valor' => '#64748b', 'attrs' => ['class' => 'input h-9 p-1']]) . '</div>'
    . botao('Adicionar', ['tipo' => 'submit', 'icone' => 'plus']) . '</form>';

// ---- Pipelines e etapas
$painelPipelines = '';
foreach ($pipelines as $p) {
    $pid = (int) $p['id'];
    $f = "f-pipelines-{$pid}";
    $formLinha('pipelines', $pid, $f);
    $formArquivar('pipelines', $pid, "a-pipelines-{$pid}", 'Arquivar o pipeline "' . $p['nome'] . '"?');

    $linhas = '';
    foreach ($etapas[$pid] ?? [] as $e) {
        $eid = (int) $e['id'];
        $fe = "f-etapas-{$eid}";
        $formLinha('etapas', $eid, $fe);
        $formArquivar('etapas', $eid, "a-etapas-{$eid}", 'Arquivar a etapa "' . $e['nome'] . '"?');
        $linhas .= '<tr>'
            . '<td class="w-20">' . $input('ordem', (string) $e['ordem'], $fe, ['type' => 'number', 'min' => 0, 'max' => 1000, 'aria-label' => 'Ordem']) . '</td>'
            . '<td>' . $input('nome', $e['nome'], $fe, ['required' => true, 'maxlength' => 80, 'aria-label' => 'Nome']) . '</td>'
            . '<td class="w-28">' . $input('probabilidade_padrao', (string) $e['probabilidade_padrao'], $fe, ['type' => 'number', 'min' => 0, 'max' => 100, 'aria-label' => 'Probabilidade (%)']) . '</td>'
            . '<td class="w-36">' . select('tipo', Schema::opcoes('tipo_etapa'), $e['tipo'], ['id' => "tipo-{$eid}", 'attrs' => ['form' => $fe, 'aria-label' => 'Tipo']]) . '</td>'
            . '<td class="w-20"><input class="input h-8 p-1" type="color" name="cor" value="' . e($e['cor'] ?: '#64748b') . '" form="' . e($fe) . '" aria-label="Cor"></td>'
            . '<td class="w-px whitespace-nowrap">' . botao('Salvar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'outline', 'attrs' => ['form' => $fe]])
            . ' ' . botao('Arquivar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'ghost', 'classe' => 'text-destructive', 'attrs' => ['form' => "a-etapas-{$eid}"]]) . '</td></tr>';
    }
    $novaEtapa = '<form method="post" action="' . e(url('/configuracoes/etapas')) . '" class="mt-3 grid items-end gap-2 md:grid-cols-[1fr_8rem_9rem_auto]">' . csrf_field()
        . '<input type="hidden" name="pipeline_id" value="' . $pid . '">'
        . campo(['nome' => 'nome', 'id' => "nova-etapa-{$pid}", 'rotulo' => 'Nova etapa', 'obrigatorio' => true, 'attrs' => ['maxlength' => 80]])
        . campo(['nome' => 'probabilidade_padrao', 'id' => "nova-etapa-prob-{$pid}", 'rotulo' => 'Prob. (%)', 'tipo' => 'number', 'valor' => '0', 'attrs' => ['min' => 0, 'max' => 100]])
        . campo(['nome' => 'tipo', 'id' => "nova-etapa-tipo-{$pid}", 'rotulo' => 'Tipo', 'controle_html' => select('tipo', Schema::opcoes('tipo_etapa'), 'aberta', ['id' => "nova-etapa-tipo-{$pid}"])])
        . botao('Adicionar etapa', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'plus']) . '</form>';

    $cabecalho = '<div class="mb-3 flex flex-wrap items-center gap-2">'
        . '<div class="min-w-48 flex-1">' . $input('nome', $p['nome'], $f, ['required' => true, 'maxlength' => 80, 'aria-label' => 'Nome do pipeline']) . '</div>'
        . ((int) $p['padrao'] === 1 ? badge('Padrão', 'primary') : '')
        . botao('Renomear', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'outline', 'attrs' => ['form' => $f]])
        . ((int) $p['padrao'] === 1 ? '' : botao('Arquivar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'ghost', 'classe' => 'text-destructive', 'attrs' => ['form' => "a-pipelines-{$pid}"]]))
        . '</div>';

    $painelPipelines .= '<div class="mb-4 rounded-lg border p-4">' . $cabecalho
        . ($linhas !== ''
            ? '<div class="table-container"><table class="table"><thead><tr><th>Ordem</th><th>Etapa</th><th>Prob. (%)</th><th>Tipo</th><th>Cor</th><th></th></tr></thead><tbody>' . $linhas . '</tbody></table></div>'
            : '<p class="text-muted-foreground text-sm">Sem etapas.</p>')
        . $novaEtapa . '</div>';
}
$painelPipelines .= '<form method="post" action="' . e(url('/configuracoes/pipelines')) . '" class="flex flex-wrap items-end gap-2">' . csrf_field()
    . '<div class="min-w-56 flex-1">' . campo(['nome' => 'nome', 'id' => 'novo-pipeline', 'rotulo' => 'Novo pipeline', 'obrigatorio' => true, 'attrs' => ['maxlength' => 80]]) . '</div>'
    . botao('Criar pipeline', ['tipo' => 'submit', 'icone' => 'plus']) . '</form>'
    . '<p class="text-muted-foreground mt-3 text-xs">Uma etapa do tipo "ganho" exige o valor fechado e uma do tipo "perdido" exige o motivo ao mover o negócio. Etapas com negócios ativos não podem ser arquivadas.</p>';

// ---- Campos extras (empresas, contatos e negócios)
$marca = static fn (string $nome, bool $ligado, string $formId, string $rotulo): string
    => '<label class="flex items-center gap-1 text-xs whitespace-nowrap"><input type="hidden" name="' . e($nome) . '" value="0" form="' . e($formId) . '">'
        . '<input type="checkbox" name="' . e($nome) . '" value="1" form="' . e($formId) . '"' . ($ligado ? ' checked' : '') . '> ' . e($rotulo) . '</label>';
$painelExtras = '<p class="text-muted-foreground mb-4 text-sm">Campos próprios do seu negócio. Aparecem no formulário (aba Extras), no detalhe, como filtro/coluna da lista '
    . '(lista de opções e sim/não), no contexto dos agentes (<code>extra.chave</code>) e nos modelos (<code>{empresa.extra.chave}</code>). '
    . 'A chave não muda depois de criada. Campos obrigatórios só são exigidos nas telas; chat, agentes e formulários públicos não travam por eles.</p>';
foreach (Schema::opcoes('entidade_extra') as $entExtra => $rotuloEnt) {
    $linhasExtras = '';
    foreach ($camposExtras[$entExtra] ?? [] as $c) {
        $id = (int) $c['id'];
        $f = "f-campos-extras-{$id}";
        $formLinha('campos-extras', $id, $f);
        $formArquivar('campos-extras', $id, "a-campos-extras-{$id}", 'Arquivar o campo "' . $c['rotulo'] . '"? Os valores já gravados continuam nos registros, mas deixam de aparecer.');
        $linhasExtras .= '<tr class="align-top">'
            . '<td class="w-20">' . $input('ordem', (string) $c['ordem'], $f, ['type' => 'number', 'min' => 0, 'max' => 1000, 'aria-label' => 'Ordem']) . '</td>'
            . '<td>' . $input('rotulo', $c['rotulo'], $f, ['required' => true, 'maxlength' => 80, 'aria-label' => 'Rótulo']) . '<code class="text-muted-foreground text-xs">' . e($c['chave']) . '</code></td>'
            . '<td class="w-40">' . select('tipo', Schema::opcoes('tipo_extra'), $c['tipo'], ['id' => "tipo-extra-{$id}", 'attrs' => ['form' => $f, 'aria-label' => 'Tipo']]) . '</td>'
            . '<td><textarea class="textarea" rows="2" name="opcoes" form="' . e($f) . '" aria-label="Opções (uma por linha)" placeholder="Uma opção por linha (só para lista de opções)">' . e(implode("\n", $c['opcoes'])) . '</textarea></td>'
            . '<td class="w-px">' . $marca('obrigatorio', (int) $c['obrigatorio'] === 1, $f, 'Obrigatório') . $marca('ativo', (int) $c['ativo'] === 1, $f, 'Ativo') . '</td>'
            . '<td class="w-px whitespace-nowrap">' . botao('Salvar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'outline', 'attrs' => ['form' => $f]])
            . ' ' . botao('Arquivar', ['tipo' => 'submit', 'tamanho' => 'sm', 'variante' => 'ghost', 'classe' => 'text-destructive', 'attrs' => ['form' => "a-campos-extras-{$id}"]]) . '</td></tr>';
    }
    $novoExtra = '<form method="post" action="' . e(url('/configuracoes/campos-extras')) . '" class="mt-3 grid items-start gap-2 md:grid-cols-[10rem_1fr_9rem_1fr_auto]">' . csrf_field()
        . '<input type="hidden" name="entidade" value="' . e($entExtra) . '"><input type="hidden" name="ativo" value="1">'
        . campo(['nome' => 'chave', 'id' => "extra-chave-{$entExtra}", 'rotulo' => 'Chave', 'obrigatorio' => true, 'ajuda' => 'ex.: nicho', 'attrs' => ['maxlength' => 40, 'pattern' => '[a-z][a-z0-9_]*']])
        . campo(['nome' => 'rotulo', 'id' => "extra-rotulo-{$entExtra}", 'rotulo' => 'Rótulo', 'obrigatorio' => true, 'attrs' => ['maxlength' => 80]])
        . campo(['nome' => 'tipo', 'id' => "extra-tipo-{$entExtra}", 'rotulo' => 'Tipo', 'controle_html' => select('tipo', Schema::opcoes('tipo_extra'), 'texto', ['id' => "extra-tipo-{$entExtra}"])])
        . campo(['nome' => 'opcoes', 'id' => "extra-opcoes-{$entExtra}", 'rotulo' => 'Opções (lista)', 'tipo' => 'textarea', 'linhas' => 2, 'ajuda' => 'Uma por linha'])
        . '<div class="pt-6">' . botao('Adicionar', ['tipo' => 'submit', 'icone' => 'plus']) . '</div></form>';
    $painelExtras .= '<div class="mb-4 rounded-lg border p-4"><h3 class="mb-3 font-medium">' . e($rotuloEnt) . '</h3>'
        . ($linhasExtras !== ''
            ? '<div class="table-container"><table class="table"><thead><tr><th>Ordem</th><th>Rótulo / chave</th><th>Tipo</th><th>Opções</th><th></th><th></th></tr></thead><tbody>' . $linhasExtras . '</tbody></table></div>'
            : '<p class="text-muted-foreground text-sm">Nenhum campo extra.</p>')
        . $novoExtra . '</div>';
}

// ---- Dados da agência (variáveis {larbous.*}) e validade padrão das propostas
$campoAg = "";
foreach (\App\Services\Variaveis::CAMPOS_AGENCIA as $chave => $rotuloAg) {
    $campoAg .= '<div' . (in_array($chave, ['endereco'], true) ? ' class="md:col-span-2"' : '') . '>'
        . campo(['nome' => $chave, 'id' => 'ag-' . $chave, 'rotulo' => $rotuloAg, 'valor' => $agencia[$chave] ?? '', 'attrs' => ['maxlength' => 300]]) . '</div>';
}
$painelAgencia = '<form method="post" action="' . e(url('/configuracoes/agencia')) . '" class="grid gap-4">' . csrf_field()
    . '<p class="text-muted-foreground text-sm">Usados no cabeçalho de propostas e contratos e nas variáveis <code>{larbous.nome}</code>, <code>{larbous.cnpj}</code> etc.</p>'
    . '<div class="grid gap-4 md:grid-cols-2">' . $campoAg
    . campo(['nome' => 'validade_dias', 'id' => 'ag-validade', 'rotulo' => 'Validade padrão das propostas (dias)', 'tipo' => 'number', 'valor' => $agencia['validade_dias'] ?? '15', 'attrs' => ['min' => 1, 'max' => 365]])
    . '</div><div>' . botao('Salvar dados da agência', ['tipo' => 'submit', 'icone' => 'check']) . '</div></form>';

$paineis = [
    'pipelines' => ['Pipelines e etapas', $painelPipelines],
    'origens'   => ['Origens', $listaSimples('origens', $origens, 'Nova origem')],
    'motivos'   => ['Motivos de perda', $listaSimples('motivos-perda', $motivos, 'Novo motivo de perda')],
    'tags'      => ['Tags', $painelTags],
    'contratos' => ['Tipos de contrato', $listaSimples('contrato-tipos', $tiposContrato, 'Novo tipo de contrato')],
    'extras'    => ['Campos extras', $painelExtras],
    'agencia'   => ['Dados da agência', $painelAgencia],
];
$abasHtml = [];
$ativa = 0;
foreach ($paineis as $chave => [$rotulo, $html]) {
    if ($chave === $aba) {
        $ativa = count($abasHtml);
    }
    $abasHtml[] = ['rotulo' => $rotulo, 'html' => $html];
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Configurações</h1>
        <p class="text-muted-foreground">Cadastros de apoio usados nos formulários e no funil.</p>
    </div>
</div>

<?= card(['corpo_html' => abas('abas-config', $abasHtml, $ativa, ['linha' => true])]) ?>
<div hidden><?= $formsOcultos ?></div>
