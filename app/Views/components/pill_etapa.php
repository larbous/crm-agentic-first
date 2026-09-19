<?php

declare(strict_types=1);

/** Pílula de etapa do funil; a cor vem do banco via variável CSS --etapa (validada como #hex). */
function pill_etapa(string $nome, ?string $cor = null): string
{
    $cor = $cor !== null && preg_match('/^#[0-9a-fA-F]{3,8}$/', $cor) ? $cor : '#64748b';
    return '<span class="pill-etapa" style="--etapa: ' . e($cor) . '"><span class="pill-etapa-ponto" aria-hidden="true"></span>'
        . e($nome) . '</span>';
}
