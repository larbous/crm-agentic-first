<?php

declare(strict_types=1);

/** Indicador de atalho de teclado. Aceita várias teclas: kbd('Ctrl', 'K'). */
function kbd(string ...$teclas): string
{
    return implode('', array_map(static fn (string $t): string => '<kbd class="kbd">' . e($t) . '</kbd>', $teclas));
}
