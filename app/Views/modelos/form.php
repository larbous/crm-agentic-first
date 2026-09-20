<?php
/**
 * Editor de modelos: campos, lista de variáveis e pré-visualização (modelos.js).
 * @var array|null $registro
 * @var string $acao
 * @var string $cancelar
 * @var array $valores
 * @var array $erros
 * @var array $catalogo grupo => [token => descrição]
 * @var array $negocios id => rótulo
 */
use App\Services\Schema;

$tipo = (string) ($valores['tipo'] ?? 'proposta');
$html = $tipo === 'contrato';
$erroGeral = $erros['_'] ?? null;
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo"><?= e($titulo) ?></h1>
        <p class="text-muted-foreground">Use variáveis como <code>{empresa.nome}</code> no texto; elas são preenchidas com os dados reais ao criar a proposta ou o contrato.</p>
    </div>
</div>

<form method="post" action="<?= e($acao) ?>" class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_26rem]" novalidate data-modelo-form>
    <?= csrf_field() ?>
    <?php if ($erroGeral || $erros !== []): ?>
        <div class="xl:col-span-2"><?= alerta('Corrija os campos indicados', $erroGeral ?? implode(' ', array_slice(array_values($erros), 0, 3)), 'destructive') ?></div>
    <?php endif; ?>

    <div class="grid content-start gap-4">
        <?= card(['corpo_html' =>
            '<div class="grid gap-4 md:grid-cols-2">'
            . campo(['nome' => 'tipo', 'rotulo' => 'Tipo', 'obrigatorio' => true, 'erro' => $erros['tipo'] ?? null,
                'controle_html' => select('tipo', Schema::opcoes('tipo_modelo'), $tipo, ['obrigatorio' => true, 'attrs' => ['class' => 'select w-full', 'data-modelo-tipo' => true]])])
            . campo(['nome' => 'nome', 'rotulo' => 'Nome do modelo', 'valor' => $valores['nome'] ?? '', 'obrigatorio' => true, 'erro' => $erros['nome'] ?? null, 'attrs' => ['maxlength' => 160]])
            . '<div class="md:col-span-2" data-so-email' . ($tipo === 'email' ? '' : ' hidden') . '>'
            . campo(['nome' => 'assunto', 'rotulo' => 'Assunto do e-mail', 'valor' => $valores['assunto'] ?? '', 'erro' => $erros['assunto'] ?? null, 'attrs' => ['maxlength' => 200]]) . '</div>'
            . campo(['nome' => 'ativo', 'rotulo' => 'Modelo ativo (aparece nas listas de escolha)', 'tipo' => 'switch', 'valor' => (int) ($valores['ativo'] ?? 1) === 1])
            . '</div>']) ?>

        <?= card(['corpo_html' =>
            '<div class="mb-2 flex flex-wrap items-center justify-between gap-2"><label for="campo-conteudo" class="text-sm font-medium">Conteúdo</label>'
            . '<div class="flex flex-wrap gap-1" data-barra-html' . ($html ? '' : ' hidden') . ' role="toolbar" aria-label="Formatação">'
            . botao('Título', ['tamanho' => 'xs', 'variante' => 'outline', 'attrs' => ['data-formatar' => 'h2']])
            . botao('Parágrafo', ['tamanho' => 'xs', 'variante' => 'outline', 'attrs' => ['data-formatar' => 'p']])
            . botao('Negrito', ['tamanho' => 'xs', 'variante' => 'outline', 'attrs' => ['data-formatar' => 'strong']])
            . botao('Itálico', ['tamanho' => 'xs', 'variante' => 'outline', 'attrs' => ['data-formatar' => 'em']])
            . botao('Lista', ['tamanho' => 'xs', 'variante' => 'outline', 'attrs' => ['data-formatar' => 'ul']])
            . botao('Numerada', ['tamanho' => 'xs', 'variante' => 'outline', 'attrs' => ['data-formatar' => 'ol']])
            . botao('Linha', ['tamanho' => 'xs', 'variante' => 'outline', 'attrs' => ['data-formatar' => 'hr']])
            . '</div></div>'
            . '<textarea id="campo-conteudo" name="conteudo" rows="24" class="textarea font-mono text-[13px]" spellcheck="false"'
            . (isset($erros['conteudo']) ? ' aria-invalid="true"' : '') . '>' . e($valores['conteudo'] ?? '') . '</textarea>'
            . (isset($erros['conteudo']) ? '<p class="text-destructive mt-1 text-sm" role="alert">' . e($erros['conteudo']) . '</p>' : '')
            . '<p class="text-muted-foreground mt-2 text-xs" data-dica-html' . ($html ? '' : ' hidden') . '>Contratos aceitam HTML simples (títulos, parágrafos, listas, tabelas, negrito). Scripts, estilos e outros elementos são removidos ao salvar.</p>'
            . '<p class="text-muted-foreground mt-2 text-xs" data-dica-texto' . ($html ? ' hidden' : '') . '>Texto simples: linhas em branco separam parágrafos.</p>']) ?>

        <div class="flex flex-wrap items-center gap-2">
            <?= botao('Salvar modelo', ['tipo' => 'submit', 'icone' => 'check']) ?>
            <?= botao('Cancelar', ['href' => $cancelar, 'variante' => 'outline']) ?>
        </div>
    </div>

    <aside class="grid content-start gap-4 xl:sticky xl:top-20 xl:self-start">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Pré-visualização', 'descricao' => 'Atualiza enquanto você digita.', 'corpo_html' =>
            '<div class="mb-3"><label class="text-muted-foreground mb-1 block text-xs" for="preview-negocio">Registro de exemplo</label>'
            . select('preview_negocio', $negocios, null, ['id' => 'preview-negocio', 'placeholder' => 'Dados fictícios de exemplo', 'attrs' => ['class' => 'select w-full', 'data-preview-negocio' => true]]) . '</div>'
            . '<div class="text-destructive mb-2 text-xs" data-preview-avisos role="status" hidden></div>'
            . '<div class="modelo-preview" data-preview aria-live="polite"><span class="text-muted-foreground text-sm">Carregando…</span></div>']) ?>

        <?php
        $grupos = '';
        foreach ($catalogo as $grupo => $itens) {
            $botoes = '';
            foreach ($itens as $token => $descricao) {
                $botoes .= '<button type="button" class="variavel-item" data-variavel="' . e($token) . '" title="' . e($descricao) . '"><code>' . e($token) . '</code><small>' . e($descricao) . '</small></button>';
            }
            $grupos .= '<details class="variavel-grupo"' . ($grupo === 'Empresa' ? ' open' : '') . '><summary>' . e($grupo) . ' <span class="text-muted-foreground text-xs">(' . count($itens) . ')</span></summary><div class="variavel-lista">' . $botoes . '</div></details>';
        }
        ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Variáveis', 'descricao' => 'Clique para inserir no cursor.', 'corpo_html' =>
            '<label class="sr-only" for="filtro-variaveis">Filtrar variáveis</label><input id="filtro-variaveis" class="input mb-2 h-8" type="search" placeholder="Filtrar…" data-filtro-variaveis>'
            . '<div class="variaveis-catalogo" data-catalogo>' . $grupos . '</div>']) ?>
    </aside>
</form>
