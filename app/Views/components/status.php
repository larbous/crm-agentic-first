<?php

declare(strict_types=1);

use App\Services\Schema;

/** Badge de status por grupo de opções (status_empresa, status_negocio, status_contato, status_tarefa). */
function badge_status(string $grupo, ?string $valor): string
{
    if ($valor === null || $valor === '') {
        return '';
    }
    $variantes = [
        'status_empresa' => ['lead' => 'secondary', 'prospect' => 'info', 'cliente' => 'success', 'ex_cliente' => 'outline', 'inativo' => 'ghost'],
        'status_negocio' => ['aberto' => 'info', 'ganho' => 'success', 'perdido' => 'destructive', 'pausado' => 'warning'],
        'status_contato' => ['ativo' => 'success', 'inativo' => 'secondary'],
        'status_tarefa'  => ['pendente' => 'secondary', 'andamento' => 'info', 'concluida' => 'success', 'cancelada' => 'ghost'],
    ];
    return badge(Schema::opcoes($grupo)[$valor] ?? $valor, $variantes[$grupo][$valor] ?? 'secondary');
}

/** Chips de tags de uma linha da lista. */
function chips_tags(array $tags): string
{
    $html = '';
    foreach ($tags as $t) {
        $html .= pill_etapa($t['nome'], $t['cor'] ?? null) . ' ';
    }
    return $html;
}
