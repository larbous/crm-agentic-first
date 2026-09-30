<?php

declare(strict_types=1);

/**
 * Gráficos do Início em SVG/HTML puro, sem biblioteca (o CRM não usa CDN nem dependências).
 * As cores são variáveis do tema (--chart-1…5, --success, --warning, --destructive), então acompanham o modo escuro.
 * Cada gráfico traz alternativa em texto (aria-label/<title>) e a legenda mostra os valores.
 */

/** Cor de um item: nome de variável do tema (ex.: "chart-1", "success") → var(--nome). */
function grafico_cor(string $nome): string
{
    return 'var(--' . preg_replace('/[^a-z0-9-]/', '', $nome) . ')';
}

/**
 * Indicador em destaque (número grande + detalhe). Opções: href, icone, tom (destructive|warning|success), detalhe.
 */
function kpi(string $rotulo, string $valor, array $o = []): string
{
    $tom = match ($o['tom'] ?? '') {
        'destructive' => 'text-destructive',
        'warning'     => 'text-amber-600 dark:text-amber-400',
        'success'     => 'text-emerald-600 dark:text-emerald-400',
        default       => '',
    };
    $miolo = '<div class="text-muted-foreground flex items-center justify-between gap-2 text-xs font-medium">'
        . '<span>' . e($rotulo) . '</span>' . (isset($o['icone']) ? icone($o['icone']) : '') . '</div>'
        . '<div class="' . classes('pt-1 text-2xl font-semibold tracking-tight', $tom) . '">' . e($valor) . '</div>'
        . (isset($o['detalhe']) ? '<div class="text-muted-foreground pt-0.5 text-xs">' . e($o['detalhe']) . '</div>' : '');
    $classe = 'bg-card block rounded-xl border p-4 shadow-xs';
    return isset($o['href'])
        ? '<a href="' . e(url($o['href'])) . '" class="' . $classe . ' hover:bg-accent/40 transition-colors">' . $miolo . '</a>'
        : '<div class="' . $classe . '">' . $miolo . '</div>';
}

/** Legenda: lista de [rotulo, texto do valor, cor]. */
function grafico_legenda(array $itens): string
{
    $html = '<ul class="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 pt-3 text-xs">';
    foreach ($itens as [$rotulo, $texto, $cor]) {
        $html .= '<li class="flex items-center gap-1.5"><span class="size-2.5 shrink-0 rounded-sm" style="background:' . grafico_cor($cor) . '"></span>'
            . '<span>' . e($rotulo) . ($texto !== '' ? ' <strong class="text-foreground font-medium">' . e($texto) . '</strong>' : '') . '</span></li>';
    }
    return $html . '</ul>';
}

/**
 * Colunas agrupadas (uma ou mais séries) por rótulo.
 * @param list<string> $rotulos eixo X
 * @param list<array{nome:string,valores:list<int|float>,cor:string}> $series
 * @param callable(int|float):string $formato texto do valor (tooltip e topo do eixo)
 */
function grafico_colunas(array $rotulos, array $series, callable $formato, string $descricao = ''): string
{
    $largura = 420;
    $altura = 190;
    $margemBase = 22;
    $margemTopo = 16;
    $area = $altura - $margemBase - $margemTopo;
    $max = 0;
    foreach ($series as $s) {
        $max = max($max, ...($s['valores'] ?: [0]));
    }
    if ($max <= 0) {
        return '<p class="text-muted-foreground py-10 text-center text-sm">Sem dados no período.</p>';
    }
    $n = count($rotulos);
    $faixa = $largura / max(1, $n);
    $porGrupo = count($series);
    $larguraBarra = min(28.0, ($faixa * 0.7) / $porGrupo);
    $svg = '<svg viewBox="0 0 ' . $largura . ' ' . $altura . '" class="w-full" role="img" aria-label="' . e($descricao) . '">';
    // linhas de grade (0, 50%, 100%) e o valor máximo
    foreach ([0, 0.5, 1] as $f) {
        $y = $margemTopo + $area * (1 - $f);
        $svg .= '<line x1="0" x2="' . $largura . '" y1="' . $y . '" y2="' . $y . '" stroke="var(--border)" stroke-width="1"' . ($f > 0 ? ' stroke-dasharray="3 3"' : '') . '/>';
    }
    $svg .= '<text x="0" y="' . ($margemTopo - 4) . '" font-size="10" fill="var(--muted-foreground)">' . e($formato($max)) . '</text>';
    foreach ($rotulos as $i => $rotulo) {
        $x0 = $faixa * $i + ($faixa - $larguraBarra * $porGrupo) / 2;
        foreach ($series as $k => $s) {
            $v = $s['valores'][$i] ?? 0;
            $h = $v > 0 ? max(2.0, $area * ($v / $max)) : 0;
            $x = $x0 + $larguraBarra * $k;
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($margemTopo + $area - $h, 1) . '" width="' . round($larguraBarra - 2, 1) . '" height="' . round($h, 1)
                . '" rx="3" style="fill:' . grafico_cor($s['cor']) . '"><title>' . e($rotulo . ' — ' . $s['nome'] . ': ' . $formato($v)) . '</title></rect>';
        }
        $svg .= '<text x="' . round($faixa * $i + $faixa / 2, 1) . '" y="' . ($altura - 6) . '" text-anchor="middle" font-size="10" fill="var(--muted-foreground)">' . e($rotulo) . '</text>';
    }
    $svg .= '</svg>';
    $legenda = count($series) > 1
        ? grafico_legenda(array_map(static fn (array $s): array => [$s['nome'], '', $s['cor']], $series))
        : '';
    return $svg . $legenda;
}

/**
 * Barras horizontais com rótulo e valor. Cada item: rotulo, valor (base da largura), texto, cor, href opcional.
 * @param list<array{rotulo:string,valor:int|float,texto:string,cor?:string,href?:string}> $itens
 */
function grafico_barras(array $itens): string
{
    $max = 0;
    foreach ($itens as $i) {
        $max = max($max, $i['valor']);
    }
    $html = '<ul class="grid gap-2.5">';
    foreach ($itens as $i) {
        $pct = $max > 0 ? round(100 * $i['valor'] / $max, 1) : 0;
        $rotulo = isset($i['href'])
            ? '<a class="underline-offset-4 hover:underline" href="' . e(url($i['href'])) . '">' . e($i['rotulo']) . '</a>'
            : e($i['rotulo']);
        $html .= '<li class="grid gap-1"><div class="flex items-baseline justify-between gap-3 text-sm"><span class="min-w-0 truncate">' . $rotulo . '</span>'
            . '<span class="text-muted-foreground whitespace-nowrap text-xs">' . e($i['texto']) . '</span></div>'
            . '<div class="bg-muted h-2 overflow-hidden rounded-full"><div class="h-full rounded-full" style="width:' . $pct . '%;background:' . grafico_cor($i['cor'] ?? 'chart-1') . '"></div></div></li>';
    }
    return $html . '</ul>';
}

/**
 * Rosca com total no centro e legenda. Itens: [rotulo, valor, cor]. $formato formata o valor da legenda.
 * @param list<array{0:string,1:int|float,2:string}> $itens
 */
function grafico_rosca(array $itens, string $centro, string $centroRotulo, callable $formato, string $descricao = ''): string
{
    $total = array_sum(array_column($itens, 1));
    if ($total <= 0) {
        return '<p class="text-muted-foreground py-10 text-center text-sm">Sem dados no período.</p>';
    }
    $svg = '<svg viewBox="0 0 42 42" class="size-32 shrink-0 -rotate-90" role="img" aria-label="' . e($descricao) . '">'
        . '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="var(--muted)" stroke-width="6"/>';
    $acumulado = 0.0;
    foreach ($itens as [$rotulo, $valor, $cor]) {
        if ($valor <= 0) {
            continue;
        }
        $parte = 100 * $valor / $total;
        $svg .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke-width="6" style="stroke:' . grafico_cor($cor) . '"'
            . ' stroke-dasharray="' . round($parte, 2) . ' ' . round(100 - $parte, 2) . '" stroke-dashoffset="' . round(-$acumulado, 2) . '">'
            . '<title>' . e($rotulo . ': ' . $formato($valor)) . '</title></circle>';
        $acumulado += $parte;
    }
    $svg .= '</svg>';
    return '<div class="flex flex-wrap items-center gap-4"><div class="relative">' . $svg
        . '<div class="absolute inset-0 grid place-content-center text-center"><div class="text-lg leading-none font-semibold">' . e($centro) . '</div>'
        . '<div class="text-muted-foreground pt-0.5 text-[10px]">' . e($centroRotulo) . '</div></div></div>'
        . '<div class="min-w-0 flex-1">' . grafico_legenda(array_map(static fn (array $i): array => [$i[0], $formato($i[1]), $i[2]], $itens)) . '</div></div>';
}

/** Valor curto para eixos e legendas: 1250000 (centavos) → "R$ 12,5 mil"; abaixo de mil, o valor inteiro. */
function moeda_curta(int|float $centavos): string
{
    $reais = $centavos / 100;
    if (abs($reais) >= 1000000) {
        return 'R$ ' . rtrim(rtrim(number_format($reais / 1000000, 1, ',', '.'), '0'), ',') . ' mi';
    }
    if (abs($reais) >= 1000) {
        return 'R$ ' . rtrim(rtrim(number_format($reais / 1000, 1, ',', '.'), '0'), ',') . ' mil';
    }
    return 'R$ ' . number_format($reais, 0, ',', '.');
}

/** "2026-09" → "set"; "2026-09-29" → "29/09". */
function rotulo_periodo(string $iso): string
{
    static $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    return strlen($iso) === 7 ? $meses[(int) substr($iso, 5, 2) - 1] : substr($iso, 8, 2) . '/' . substr($iso, 5, 2);
}
