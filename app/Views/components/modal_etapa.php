<?php

declare(strict_types=1);

/**
 * Modal de mudança de etapa do negócio (compartilhado por kanban e detalhe). O comportamento está em kanban.js:
 * mostra o bloco de ganho (valor fechado) ou de perda (motivo) conforme o tipo da etapa de destino.
 * @param array<int|string,string> $motivosPerda id => nome
 */
function modal_etapa(array $motivosPerda): string
{
    $corpo = '<form id="form-etapa" class="grid gap-4" novalidate>'
        . '<input type="hidden" name="negocio_id"><input type="hidden" name="etapa_id">'
        . '<p class="text-sm" data-etapa-resumo></p>'
        . '<div data-bloco="ganho" hidden>'
        . campo(['nome' => 'valor_fechado', 'id' => 'etapa-valor-fechado', 'rotulo' => 'Valor fechado (R$)', 'obrigatorio' => true, 'placeholder' => '0,00',
            'attrs' => ['inputmode' => 'decimal', 'data-dinheiro' => true]])
        . '</div>'
        . '<div data-bloco="perdido" hidden class="grid gap-4">'
        . campo(['nome' => 'motivo_perda_id', 'id' => 'etapa-motivo', 'rotulo' => 'Motivo da perda', 'obrigatorio' => true,
            'controle_html' => select('motivo_perda_id', $motivosPerda, null, ['id' => 'etapa-motivo', 'placeholder' => 'Selecione…'])])
        . campo(['nome' => 'detalhe_perda', 'id' => 'etapa-detalhe', 'rotulo' => 'Detalhes (opcional)', 'tipo' => 'textarea', 'ia' => true, 'linhas' => 3])
        . '</div>'
        . '<p class="text-destructive text-sm" role="alert" data-etapa-erro hidden></p>'
        . '</form>';

    return modal('modal-etapa', [
        'titulo'      => 'Mover negócio de etapa',
        'corpo_html'  => $corpo,
        'rodape_html' => botao('Cancelar', ['variante' => 'outline', 'attrs' => ['data-etapa-cancelar' => true]])
            . botao('Confirmar', ['tipo' => 'submit', 'attrs' => ['form' => 'form-etapa', 'data-etapa-confirmar' => true]]),
    ]);
}
