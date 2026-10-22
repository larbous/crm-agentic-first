<?php

declare(strict_types=1);

use App\Services\Variaveis;

/**
 * Editor de texto formatado (HTML simples) para contratos — comportamento em public/assets/js/editor-html.js.
 * Sem JavaScript o campo continua sendo uma caixa de código HTML. O valor é sanitizado no servidor ao salvar.
 */
function editor_html(string $nome, string $valor, array $o = []): string
{
    $id = (string) ($o['id'] ?? 'campo-' . preg_replace('/[^a-z0-9_-]/i', '-', $nome));
    $btn = static fn (string $acao, string $rotulo, string $icone, ?string $valorAcao = null): string => botao($rotulo, [
        'variante' => 'ghost', 'tamanho' => 'icon-sm', 'icone' => $icone,
        'attrs' => ['type' => 'button', 'title' => $rotulo, 'aria-label' => $rotulo, 'data-ed' => $acao] + ($valorAcao !== null ? ['data-ed-valor' => $valorAcao] : []),
    ]);
    $sep = '<span class="editor-sep" aria-hidden="true"></span>';

    $blocos = ['p' => 'Texto normal', 'h1' => 'Título do contrato', 'h2' => 'Título de seção', 'h3' => 'Cláusula (subtítulo)', 'blockquote' => 'Destaque / citação'];
    $seletorBloco = '<select class="select editor-select" data-ed-bloco aria-label="Estilo do parágrafo">';
    foreach ($blocos as $tag => $rotulo) {
        $seletorBloco .= '<option value="' . e($tag) . '">' . e($rotulo) . '</option>';
    }
    $seletorBloco .= '</select>';

    $modelos = ['clausula' => 'Cláusula numerada', 'paragrafo' => 'Parágrafo numerado (1.1.)', 'lista' => 'Lista de itens (a, b, c)', 'valores' => 'Tabela de valores',
        'assinaturas' => 'Bloco de assinaturas', 'testemunhas' => 'Testemunhas', 'foro' => 'Cláusula de foro'];
    $seletorModelo = '<select class="select editor-select" data-ed-modelo aria-label="Inserir bloco pronto"><option value="">Inserir bloco pronto…</option>';
    foreach ($modelos as $chave => $rotulo) {
        $seletorModelo .= '<option value="' . e($chave) . '">' . e($rotulo) . '</option>';
    }
    $seletorModelo .= '</select>';

    $seletorVariavel = '<select class="select editor-select" data-ed-variavel aria-label="Inserir variável"><option value="">Inserir variável…</option>';
    foreach ($o['variaveis'] ?? Variaveis::catalogo() as $grupo => $itens) {
        if ($grupo === 'Negócio' || $grupo === 'Proposta') {
            continue;
        }
        $seletorVariavel .= '<optgroup label="' . e($grupo) . '">';
        foreach ($itens as $token => $descricao) {
            $seletorVariavel .= '<option value="' . e($token) . '">' . e($descricao) . ' — ' . e($token) . '</option>';
        }
        $seletorVariavel .= '</optgroup>';
    }
    $seletorVariavel .= '</select>';

    $barra = '<div class="editor-barra" role="toolbar" aria-label="Formatação do texto" hidden data-ed-barra>'
        . '<div class="editor-grupo">' . $seletorBloco . '</div>'
        . '<div class="editor-grupo">' . $btn('bold', 'Negrito', 'bold') . $btn('italic', 'Itálico', 'italic') . $btn('underline', 'Sublinhado', 'underline') . '</div>' . $sep
        . '<div class="editor-grupo">' . $btn('ul', 'Lista com marcadores', 'list') . $btn('ol', 'Lista numerada', 'list-ordered') . '</div>' . $sep
        . '<div class="editor-grupo">' . $btn('alinhar', 'Alinhar à esquerda', 'text-align-start', 'left') . $btn('alinhar', 'Centralizar', 'text-align-center', 'center')
        . $btn('alinhar', 'Alinhar à direita', 'text-align-end', 'right') . $btn('alinhar', 'Justificar', 'text-align-justify', 'justify') . '</div>' . $sep
        . '<div class="editor-grupo">' . $btn('link', 'Inserir link', 'link') . $btn('tabela', 'Inserir tabela', 'table') . $btn('linha', 'Linha divisória', 'minus')
        . $btn('limpar', 'Limpar formatação', 'remove-formatting') . '</div>' . $sep
        . '<div class="editor-grupo">' . $btn('desfazer', 'Desfazer', 'undo-2') . $btn('refazer', 'Refazer', 'redo-2') . '</div>' . $sep
        . '<div class="editor-grupo">' . $seletorModelo . $seletorVariavel . '</div>'
        . '<div class="editor-grupo editor-fonte">' . botao('Código HTML', ['variante' => 'outline', 'tamanho' => 'xs', 'icone' => 'code', 'attrs' => ['type' => 'button', 'data-ed' => 'fonte', 'aria-pressed' => 'false']]) . '</div>'
        . '</div>';

    return '<div class="editor-html" data-editor-html>'
        . $barra
        . '<div class="editor-area documento-corpo" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Texto do contrato" spellcheck="true" hidden data-ed-area></div>'
        . '<textarea id="' . e($id) . '" name="' . e($nome) . '" rows="' . (int) ($o['linhas'] ?? 22) . '" class="textarea editor-fonte-texto font-mono text-[13px]" spellcheck="false" data-ed-fonte'
        . (!empty($o['erro']) ? ' aria-invalid="true"' : '') . '>' . e($valor) . '</textarea>'
        . '</div>';
}
