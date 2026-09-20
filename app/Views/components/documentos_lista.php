<?php

declare(strict_types=1);

/** Tabela compacta de propostas (detalhe de empresa/negócio). */
function tabela_propostas(array $propostas, string $vazioTexto = 'Nenhuma proposta ainda.'): string
{
    if ($propostas === []) {
        return vazio('Sem propostas', $vazioTexto, ['icone' => 'file-text']);
    }
    $linhas = [];
    foreach ($propostas as $p) {
        $linhas[] = [
            'numero' => ['html' => link_para('/propostas/' . (int) $p['id'], $p['numero'], 'font-medium underline-offset-4 hover:underline') . ' <span class="text-muted-foreground text-xs">v' . (int) $p['versao'] . '</span>'],
            'titulo' => $p['titulo'],
            'status' => ['html' => badge_proposta($p)],
            'total'  => moeda((int) $p['total']),
            'validade' => data_br($p['validade']),
        ];
    }
    return tabela(['numero' => 'Número', 'titulo' => 'Título', 'status' => 'Status', 'total' => 'Total', 'validade' => 'Validade'], $linhas);
}

/** Tabela compacta de contratos (detalhe de empresa/negócio). */
function tabela_contratos(array $contratos, string $vazioTexto = 'Nenhum contrato ainda.'): string
{
    if ($contratos === []) {
        return vazio('Sem contratos', $vazioTexto, ['icone' => 'file-pen']);
    }
    $linhas = [];
    foreach ($contratos as $c) {
        $linhas[] = [
            'numero' => ['html' => link_para('/contratos/' . (int) $c['id'], $c['numero'], 'font-medium underline-offset-4 hover:underline')],
            'titulo' => $c['titulo'],
            'status' => ['html' => badge_contrato($c)],
            'mensal' => $c['valor_mensal'] !== null ? moeda((int) $c['valor_mensal']) : '',
            'fim'    => ['html' => fim_vigencia_html($c)],
        ];
    }
    return tabela(['numero' => 'Número', 'titulo' => 'Título', 'status' => 'Status', 'mensal' => 'Mensal', 'fim' => 'Fim da vigência'], $linhas);
}
