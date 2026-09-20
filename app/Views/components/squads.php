<?php

declare(strict_types=1);

/**
 * Rótulo e variante do badge de um status de execução (cabeçalho ou etapa de squad).
 * @return array{0:string,1:string}
 */
function squad_status_rotulo(string $estado): array
{
    return match ($estado) {
        'fila', 'pendente'      => ['Na fila', 'secondary'],
        'rodando'               => ['Rodando', 'info'],
        'concluida'             => ['Concluída', 'success'],
        'erro'                  => ['Erro', 'destructive'],
        'aguardando_aprovacao'  => ['Aguardando aprovação', 'warning'],
        'cancelada'             => ['Cancelada', 'secondary'],
        'pulada'                => ['Pulada', 'outline'],
        'nao_executada'         => ['Não executada', 'outline'],
        default                 => [$estado, 'secondary'],
    };
}

/**
 * Andamento de uma execução de squad: status, etapas com resultado, tokens. Usado pela página e pelo polling (HTML escapado aqui).
 * @param array $cab linha de execucoes (cabeçalho)
 * @param array $estado JSON de progresso (`saida` do cabeçalho)
 * @param array<int,array> $etapas linhas de execucoes das etapas de IA, por etapa_ordem
 * @param array{entrada:int,saida:int} $tokens
 */
function squad_execucao_html(array $cab, array $estado, array $etapas, array $tokens): string
{
    [$rotulo, $variante] = squad_status_rotulo((string) $cab['status']);
    $num = static fn (int $n): string => number_format($n, 0, ',', '.');
    $html = '<div class="grid gap-3"><div class="flex flex-wrap items-center gap-2">' . badge($rotulo, $variante)
        . '<span class="text-muted-foreground text-sm">Início ' . e(datahora_br((string) $cab['iniciado_em'])) . '</span>'
        . ($cab['concluido_em'] ? '<span class="text-muted-foreground text-sm">· fim ' . e(datahora_br((string) $cab['concluido_em'])) . '</span>' : '')
        . '<span class="text-muted-foreground text-sm">· ' . $num($tokens['entrada']) . ' / ' . $num($tokens['saida']) . ' tokens (entrada / saída)</span></div>';

    if ($cab['status'] === 'fila') {
        $html .= '<p class="text-muted-foreground text-sm">Esperando o worker (cron), que roda a cada minuto. Em desenvolvimento, rode <code>php cron/worker.php</code>.</p>';
    }
    if ($cab['status'] === 'aguardando_aprovacao') {
        $html .= '<div>' . botao('Revisar ações pendentes', ['href' => url('/acoes-pendentes'), 'variante' => 'default', 'tamanho' => 'sm', 'icone' => 'inbox'])
            . ' <span class="text-muted-foreground text-sm">O squad continua depois que você decidir todas as ações da etapa.</span></div>';
    }
    if ($cab['erro']) {
        $html .= '<div class="alert" role="alert" data-variant="destructive">' . icone('circle-alert') . '<h2>O squad parou</h2><section><p>' . e((string) $cab['erro']) . '</p></section></div>';
    }
    if (!empty($estado['parada'])) {
        $html .= '<p class="text-sm">' . icone('circle-check', 'size-4 inline text-[var(--success)]') . ' ' . e((string) $estado['parada']) . '</p>';
    }

    $html .= '<ol class="grid gap-2">';
    foreach ((array) ($estado['etapas'] ?? []) as $e) {
        [$r, $v] = squad_status_rotulo((string) $e['estado']);
        $titulo = $e['agente'] !== null
            ? '<span class="font-medium">' . e((string) $e['agente']) . '</span>'
            : '<span class="font-medium">' . e($e['acao'] === 'tarefa' ? 'Criar tarefa: ' . ($e['dados']['titulo'] ?? '') : 'Converter empresa em cliente') . '</span>';
        $linha = '<li class="rounded-md border p-3"><div class="flex flex-wrap items-center gap-2"><span class="text-muted-foreground text-sm">' . (int) $e['ordem'] . '.</span>'
            . $titulo . badge($r, $v);
        if ($e['condicao'] !== null) {
            $linha .= '<span class="text-muted-foreground text-xs">se ' . e((string) $e['condicao']) . '</span>';
        }
        if ($e['status'] !== null) {
            $linha .= badge('status: ' . $e['status'], 'outline');
        }
        $linha .= '</div>';
        foreach (['resumo' => '', 'motivo' => 'text-muted-foreground'] as $chave => $classe) {
            if (!empty($e[$chave])) {
                $linha .= '<p class="mt-1 text-sm ' . $classe . '">' . e((string) $e[$chave]) . '</p>';
            }
        }
        $x = $e['ordem'] !== null ? ($etapas[(int) $e['ordem']] ?? null) : null;
        if ($x !== null) {
            $linha .= '<div class="text-muted-foreground mt-1 text-xs">' . ($x['tokens_entrada'] !== null ? $num((int) $x['tokens_entrada']) . ' / ' . $num((int) $x['tokens_saida']) . ' tokens' : '')
                . ((int) $x['pendentes'] > 0 ? ' · ' . (int) $x['pendentes'] . ' ação(ões) pendente(s)' : '')
                . ' · <a class="underline underline-offset-4" href="' . e(url('/auditoria?execucao_id=' . (int) $x['id'])) . '">alterações</a></div>';
        }
        $html .= $linha . '</li>';
    }
    return $html . '</ol></div>';
}
