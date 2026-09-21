<?php

declare(strict_types=1);

/**
 * Timeline de atividades (componente próprio). Cada item mostra ícone por tipo, assunto, descrição e origem.
 * Opções: voltar (caminho interno para redirecionar após arquivar), contexto (bool: mostra contato/negócio vinculados).
 */
function timeline(array $atividades, array $o = []): string
{
    if ($atividades === []) {
        return vazio('Nenhuma atividade ainda', 'Registre uma nota, ligação ou reunião acima.', ['icone' => 'file-text']);
    }
    $icones = [
        'nota' => 'file-text', 'ligacao' => 'phone', 'whatsapp' => 'message-square', 'email' => 'mail', 'instagram' => 'image', 'reuniao' => 'users',
        'visita' => 'map-pin', 'proposta' => 'file-text', 'contrato' => 'file-text', 'sistema' => 'sparkles',
    ];
    $tipos = \App\Services\Schema::opcoes('tipo_atividade');
    $html = '<ol class="timeline">';
    foreach ($atividades as $a) {
        $tipo = (string) $a['tipo'];
        $sistema = $tipo === 'sistema';
        $meta = [$tipos[$tipo] ?? $tipo, datahora_br($a['data_hora'])];
        if (!empty($a['direcao'])) {
            $meta[] = \App\Services\Schema::opcoes('direcao')[$a['direcao']] ?? $a['direcao'];
        }
        if (!empty($a['duracao_min'])) {
            $meta[] = (int) $a['duracao_min'] . ' min';
        }
        if (!$sistema && !empty($a['criado_por']) && $a['criado_por'] !== 'humano') {
            $meta[] = 'por ' . $a['criado_por'];
        }

        $html .= '<li class="timeline-item" data-tipo="' . e($tipo) . '">';
        $html .= '<span class="timeline-icone" aria-hidden="true">' . icone($icones[$tipo] ?? 'circle', 'size-3.5') . '</span>';
        $html .= '<div class="timeline-corpo"><div class="flex items-start justify-between gap-2"><div class="min-w-0">';
        $html .= '<div class="font-medium">' . e($a['assunto'] ?: ($tipos[$tipo] ?? $tipo)) . '</div>';
        $html .= '<div class="text-muted-foreground text-xs">' . e(implode(' · ', $meta)) . '</div></div>';
        if (!$sistema && isset($o['voltar'])) {
            $html .= '<form method="post" action="' . e(url('/atividades/' . (int) $a['id'] . '/arquivar')) . '" data-confirmar="Arquivar esta atividade?">'
                . csrf_field() . '<input type="hidden" name="voltar" value="' . e($o['voltar']) . '">'
                . botao('Arquivar atividade', ['variante' => 'ghost', 'tamanho' => 'icon-xs', 'icone' => 'x', 'tipo' => 'submit']) . '</form>';
        }
        $html .= '</div>';
        if (!empty($a['descricao'])) {
            $html .= '<p class="mt-1 text-sm whitespace-pre-line">' . e($a['descricao']) . '</p>';
        }
        if (!empty($o['contexto'])) {
            $liga = [];
            if (!empty($a['contato_id']) && !empty($a['contato_nome'])) {
                $liga[] = '<a class="underline underline-offset-4" href="' . e(url('/contatos/' . (int) $a['contato_id'])) . '">' . e($a['contato_nome']) . '</a>';
            }
            if (!empty($a['negocio_id']) && !empty($a['negocio_titulo'])) {
                $liga[] = '<a class="underline underline-offset-4" href="' . e(url('/negocios/' . (int) $a['negocio_id'])) . '">' . e($a['negocio_titulo']) . '</a>';
            }
            if ($liga !== []) {
                $html .= '<div class="text-muted-foreground mt-1 text-xs">' . implode(' · ', $liga) . '</div>';
            }
        }
        $html .= '</div></li>';
    }
    return $html . '</ol>';
}
