<?php

declare(strict_types=1);

use App\Services\Schema;

/** Tamanho de arquivo legível: 1536 → "1,5 KB". */
function tamanho_legivel(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $unidades = ['KB', 'MB', 'GB'];
    $v = $bytes / 1024;
    $i = 0;
    while ($v >= 1024 && $i < 2) {
        $v /= 1024;
        $i++;
    }
    return number_format($v, 1, ',', '.') . ' ' . $unidades[$i];
}

/**
 * Formulário de criação rápida de atividade (timeline).
 * $vinculos: ['empresa_id' => 3, 'contato_id' => ..., 'negocio_id' => ...] (os que se aplicam).
 */
function atividade_form(array $vinculos, string $voltar): string
{
    $tipos = array_diff_key(Schema::opcoes('tipo_atividade'), ['sistema' => 1, 'proposta' => 1, 'contrato' => 1]);
    $ocultos = csrf_field() . '<input type="hidden" name="voltar" value="' . e($voltar) . '">';
    foreach ($vinculos as $campo => $id) {
        $ocultos .= '<input type="hidden" name="' . e($campo) . '" value="' . (int) $id . '">';
    }
    $corpo = '<form method="post" action="' . e(url('/atividades')) . '" class="grid gap-3 md:grid-cols-[9rem_1fr_15rem]">' . $ocultos
        . '<div>' . campo(['nome' => 'tipo', 'rotulo' => 'Tipo', 'id' => 'atv-tipo', 'controle_html' => select('tipo', $tipos, 'nota', ['id' => 'atv-tipo', 'obrigatorio' => true])]) . '</div>'
        . '<div>' . campo(['nome' => 'assunto', 'rotulo' => 'Assunto', 'id' => 'atv-assunto', 'placeholder' => 'Resumo curto', 'attrs' => ['maxlength' => 200]]) . '</div>'
        . '<div>' . campo(['nome' => 'data_hora', 'rotulo' => 'Data e hora', 'id' => 'atv-data', 'tipo' => 'datetime-local', 'valor' => date('Y-m-d\TH:i')]) . '</div>'
        . '<div class="md:col-span-3">' . campo(['nome' => 'descricao', 'rotulo' => 'Detalhes', 'id' => 'atv-desc', 'tipo' => 'textarea', 'linhas' => 2, 'placeholder' => 'O que foi conversado ou combinado…']) . '</div>'
        . '<div class="md:col-span-3">' . botao('Registrar atividade', ['tipo' => 'submit', 'icone' => 'plus', 'tamanho' => 'sm']) . '</div></form>';
    return card(['classe' => 'mb-4', 'tamanho' => 'sm', 'titulo' => 'Nova atividade', 'corpo_html' => $corpo]);
}

/** Painel de anexos: upload + lista com download autenticado. */
function anexos_painel(array $anexos, string $entidade, int $registroId, string $voltar): string
{
    $form = '<form method="post" action="' . e(url('/anexos')) . '" enctype="multipart/form-data" class="mb-4 flex flex-wrap items-end gap-2">'
        . csrf_field() . '<input type="hidden" name="entidade" value="' . e($entidade) . '"><input type="hidden" name="registro_id" value="' . $registroId . '">'
        . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
        . '<div class="min-w-56 flex-1"><label class="sr-only" for="anexo-arquivo">Arquivo</label>'
        . '<input id="anexo-arquivo" class="input" type="file" name="arquivo" required></div>'
        . botao('Enviar arquivo', ['tipo' => 'submit', 'icone' => 'upload', 'variante' => 'outline'])
        . '<p class="text-muted-foreground w-full text-xs">Até 10 MB. PDF, imagens, texto/CSV e documentos do Office.</p></form>';

    if ($anexos === []) {
        return $form . vazio('Nenhum anexo', 'Envie propostas, contratos, briefings ou imagens.', ['icone' => 'paperclip']);
    }
    $html = '<ul>';
    foreach ($anexos as $a) {
        $html .= '<li class="flex items-center gap-3 border-b py-2 last:border-b-0">' . icone('paperclip', 'size-4 shrink-0')
            . '<div class="min-w-0 flex-1"><a class="truncate font-medium underline-offset-4 hover:underline" href="' . e(url('/anexos/' . (int) $a['id'])) . '">'
            . e($a['nome_original']) . '</a><div class="text-muted-foreground text-xs">' . e(tamanho_legivel((int) $a['tamanho']))
            . ' · ' . e(datahora_br($a['criado_em'])) . '</div></div>'
            . '<form method="post" action="' . e(url('/anexos/' . (int) $a['id'] . '/arquivar')) . '" data-confirmar="Arquivar este anexo?">'
            . csrf_field() . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
            . botao('Arquivar anexo', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'icon-sm', 'icone' => 'x']) . '</form></li>';
    }
    return $form . $html . '</ul>';
}

/** Chips de tags + diálogo para editar. */
function tags_painel(array $tags, array $opcoes, string $entidade, int $registroId, string $voltar): string
{
    $chips = '';
    foreach ($tags as $t) {
        $chips .= pill_etapa($t['nome'], $t['cor'] ?? null);
    }
    $chips = $chips !== '' ? $chips : '<span class="text-muted-foreground text-xs">Sem tags</span>';

    if ($opcoes === []) {
        return '<div class="flex flex-wrap items-center gap-1.5">' . $chips . ' '
            . '<a class="text-muted-foreground text-xs underline underline-offset-4" href="' . e(url('/configuracoes?aba=tags')) . '">Criar tags</a></div>';
    }
    $marcadas = array_map(static fn (array $t) => (int) $t['id'], $tags);
    $caixas = '';
    foreach ($opcoes as $id => $nome) {
        $caixas .= '<label class="flex items-center gap-2 py-1"><input type="checkbox" class="input" name="tags[]" value="' . (int) $id . '"'
            . (in_array((int) $id, $marcadas, true) ? ' checked' : '') . '> ' . e($nome) . '</label>';
    }
    $dialogId = 'modal-tags';
    return '<div class="flex flex-wrap items-center gap-1.5">' . $chips
        . botao('Editar tags', ['variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'tag', 'attrs' => ['data-abrir-modal' => $dialogId]]) . '</div>'
        . modal($dialogId, [
            'titulo' => 'Tags',
            'corpo_html' => '<form id="form-tags" method="post" action="' . e(url('/tags/definir')) . '" class="grid gap-1">' . csrf_field()
                . '<input type="hidden" name="entidade" value="' . e($entidade) . '"><input type="hidden" name="registro_id" value="' . $registroId . '">'
                . '<input type="hidden" name="voltar" value="' . e($voltar) . '">' . $caixas . '</form>',
            'rodape_html' => botao('Cancelar', ['variante' => 'outline', 'attrs' => ['onclick' => "this.closest('dialog').close()"]])
                . botao('Salvar', ['tipo' => 'submit', 'attrs' => ['form' => 'form-tags']]),
        ]);
}

/** Botão de arquivar com confirmação (formulário POST). */
function botao_arquivar(string $acao, string $mensagem, string $voltar = ''): string
{
    return '<form method="post" action="' . e($acao) . '" data-confirmar="' . e($mensagem) . '">' . csrf_field()
        . ($voltar !== '' ? '<input type="hidden" name="voltar" value="' . e($voltar) . '">' : '')
        . botao('Arquivar', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'x', 'classe' => 'text-destructive']) . '</form>';
}
