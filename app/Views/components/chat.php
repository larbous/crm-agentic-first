<?php

declare(strict_types=1);

use App\Services\AI\ContextBuilder;

/**
 * Uma mensagem do chat (linha de chat_mensagens já decodificada). O conteúdo é texto puro (escapado aqui);
 * links e botões vêm do payload. Botões usam data-chat-acao e são tratados por chat.js.
 */
function chat_mensagem(array $m): string
{
    $p = is_array($m['payload'] ?? null) ? $m['payload'] : [];
    $tipo = (string) ($p['tipo'] ?? 'texto');
    $sistema = $m['papel'] === 'sistema';
    $id = (int) $m['id'];

    $html = '<div class="chat-msg" data-papel="' . e($m['papel']) . '"' . ($tipo === 'erro' ? ' data-erro' : '') . ' data-id="' . $id . '">';
    $html .= '<div class="chat-balao">' . e($m['conteudo']) . '</div>';

    if ($sistema) {
        $html .= match ($tipo) {
            'acao'      => chat_extra_acao($id, $p),
            'consulta'  => chat_extra_consulta($p),
            'busca'     => chat_extra_busca($p),
            'escolha'   => chat_extra_escolha($id, $p),
            'criar_ref' => chat_extra_pergunta($id, $p, [['criar', 'Criar "' . ($p['termo'] ?? '') . '"', 'default']]),
            'confirmar' => chat_extra_pergunta($id, $p, [['confirmar', 'Confirmar', 'destructive']]),
            default     => '',
        };
    }
    return $html . '</div>';
}

function chat_botao_acao(int $mensagemId, string $acao, string $rotulo, string $variante = 'outline', ?int $opcao = null): string
{
    return botao($rotulo, [
        'variante' => $variante, 'tamanho' => 'sm',
        'attrs' => ['data-chat-acao' => $acao, 'data-mensagem' => $mensagemId, 'data-opcao' => $opcao],
    ]);
}

function chat_extra_acao(int $id, array $p): string
{
    $partes = [];
    if (!empty($p['link'])) {
        $partes[] = '<a class="btn" data-variant="outline" data-size="sm" href="' . e(url((string) $p['link'])) . '">abrir</a>';
    }
    if (!empty($p['ok']) && !empty($p['log_id'])) {
        $partes[] = !empty($p['desfeito'])
            ? '<span class="text-muted-foreground text-xs">desfeito</span>'
            : chat_botao_acao($id, 'desfazer', 'Desfazer', 'ghost');
    }
    return $partes === [] ? '' : '<div class="chat-acoes">' . implode('', $partes) . '</div>';
}

/** Tabela de "consultar": primeira coluna com link para o registro. */
function chat_extra_consulta(array $p): string
{
    if (empty($p['linhas'])) {
        return '';
    }
    $colunas = [];
    foreach ($p['colunas'] as $i => $rotulo) {
        $colunas['c' . $i] = $rotulo;
    }
    $linhas = [];
    foreach ($p['linhas'] as $l) {
        $linha = [];
        foreach ($l['celulas'] as $i => $celula) {
            $linha['c' . $i] = $i === 0 && ($p['link'] ?? '') !== ''
                ? ['html' => '<a class="font-medium underline-offset-4 hover:underline" href="'
                    . e(url(str_ends_with((string) $p['link'], '/') ? $p['link'] . (int) $l['id'] : (string) $p['link'])) . '">' . e($celula) . '</a>']
                : $celula;
        }
        $linhas[] = $linha;
    }
    return '<div class="chat-tabela">' . tabela($colunas, $linhas) . '</div>';
}

/** Resultado de /buscar: grupos com links. */
function chat_extra_busca(array $p): string
{
    $html = '';
    foreach ($p['grupos'] ?? [] as $g) {
        $html .= '<div class="chat-grupo"><div class="text-muted-foreground text-xs font-medium uppercase">' . e($g['rotulo']) . '</div><ul>';
        foreach ($g['itens'] as $i) {
            $html .= '<li><a class="underline-offset-4 hover:underline" href="' . e(url($i['link'])) . '">' . e($i['titulo']) . '</a>'
                . ($i['subtitulo'] !== '' ? ' <span class="text-muted-foreground text-xs">' . e($i['subtitulo']) . '</span>' : '') . '</li>';
        }
        $html .= '</ul></div>';
    }
    return $html;
}

/** Desambiguação: um botão por opção; depois de respondida, só o resumo. */
function chat_extra_escolha(int $id, array $p): string
{
    if (($p['estado'] ?? '') !== 'aberta') {
        return chat_estado_resolvido($p);
    }
    $botoes = '';
    foreach ($p['opcoes'] as $i => $o) {
        $botoes .= chat_botao_acao($id, 'escolher', $o['rotulo'] . ($o['sub'] !== '' ? ' · ' . $o['sub'] : ''), 'outline', (int) $i);
    }
    return '<div class="chat-acoes">' . $botoes . chat_botao_acao($id, 'cancelar', 'Cancelar', 'ghost') . '</div>';
}

/** Pergunta com ação principal + Cancelar (criar referência, confirmar). */
function chat_extra_pergunta(int $id, array $p, array $principais): string
{
    if (($p['estado'] ?? '') !== 'aberta') {
        return chat_estado_resolvido($p);
    }
    $botoes = '';
    foreach ($principais as [$acao, $rotulo, $variante]) {
        $botoes .= chat_botao_acao($id, $acao, $rotulo, $variante);
    }
    return '<div class="chat-acoes">' . $botoes . chat_botao_acao($id, 'cancelar', 'Cancelar', 'ghost') . '</div>';
}

function chat_estado_resolvido(array $p): string
{
    $texto = match ($p['estado'] ?? '') {
        'cancelada' => 'cancelado',
        'confirmada' => 'confirmado',
        'resolvida' => 'respondido',
        default => '',
    };
    return $texto === '' ? '' : '<div class="chat-acoes"><span class="text-muted-foreground text-xs">' . e($texto) . '</span></div>';
}

/**
 * Caixa de chat (lista de mensagens + campo de entrada). O histórico é carregado por chat.js.
 * Opções: modo (painel|pagina), caminho (URL da tela aberta, para mostrar o contexto).
 */
function chat_caixa(array $o = []): string
{
    $modo = $o['modo'] ?? 'painel';
    $tela = ContextBuilder::tela($o['caminho'] ?? null);

    $html = '<div class="chat-caixa" data-chat data-modo="' . e($modo) . '">';
    if ($tela !== null) {
        $html .= '<div class="chat-contexto">' . icone('link', 'size-3.5') . '<span>Contexto: <strong>' . e($tela['nome']) . '</strong></span></div>';
    }
    $html .= '<div class="chat-log" role="log" aria-live="polite" aria-label="Mensagens do chat" data-chat-log></div>';
    $html .= '<form class="chat-form" data-chat-form>'
        . '<label for="chat-entrada-' . e($modo) . '" class="sr-only">Mensagem</label>'
        . '<textarea id="chat-entrada-' . e($modo) . '" class="textarea" rows="1" maxlength="2000" data-chat-entrada'
        . ' placeholder="Comando (/ajuda) ou linguagem natural…"></textarea>'
        . botao('Enviar', ['tipo' => 'submit', 'tamanho' => 'icon', 'icone' => 'send', 'attrs' => ['data-chat-enviar' => true]])
        . '</form>'
        . '<p class="chat-dica">Enter envia · Shift+Enter quebra a linha · <span data-chat-ajuda class="underline-offset-4 hover:underline cursor-pointer">/ajuda</span></p>'
        . '</div>';
    return $html;
}
