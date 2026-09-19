<?php

declare(strict_types=1);

/** Avatar (Basecoat .avatar): imagem quando houver, senão as iniciais do nome. Tamanho: sm|default|lg. */
function avatar(string $nome, ?string $src = null, string $tamanho = 'default'): string
{
    $attrs = attrs_html(['class' => 'avatar', 'data-size' => $tamanho === 'default' ? null : $tamanho, 'title' => $nome]);
    $interno = $src !== null && $src !== ''
        ? '<img src="' . e($src) . '" alt="' . e($nome) . '">'
        : '<span aria-hidden="true">' . e(iniciais($nome)) . '</span><span class="sr-only">' . e($nome) . '</span>';
    return '<span' . $attrs . '>' . $interno . '</span>';
}
