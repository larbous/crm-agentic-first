<?php

declare(strict_types=1);

use App\Services\Canais\Canais;

/** Componentes da caixa de entrada (Fase 14). */

/** Selo do canal: WhatsApp (verde), Instagram (rosa), e-mail (azul). */
function badge_canal(string $canal): string
{
    $icones = ['whatsapp' => 'message-square', 'instagram' => 'image', 'email' => 'mail'];
    $variantes = ['whatsapp' => 'success', 'instagram' => 'secondary', 'email' => 'info'];
    return badge(Canais::ROTULOS[$canal] ?? $canal, $variantes[$canal] ?? 'secondary');
}

/** Situação de uma mensagem enviada: relógio, um tique (enviada), dois tiques (entregue), dois tiques azuis (lida) ou falha. */
function tique_mensagem(array $m): string
{
    if ($m['direcao'] !== 'saida') {
        return '';
    }
    return match ($m['status']) {
        'enviando' => '<span class="text-muted-foreground" title="Enviando">' . icone('clock', 'inline size-3') . '</span>',
        'enviada' => '<span class="text-muted-foreground" title="Enviada">' . icone('check', 'inline size-3') . '</span>',
        'entregue' => '<span class="text-muted-foreground" title="Entregue">' . icone('check-check', 'inline size-3') . '</span>',
        'lida' => '<span class="text-info" title="Lida">' . icone('check-check', 'inline size-3') . '</span>',
        'falhou' => '<span class="text-destructive" title="' . e((string) ($m['erro'] ?? 'Falhou')) . '">' . icone('circle-alert', 'inline size-3') . ' falhou</span>',
        default => '',
    };
}

/** Bolha de uma mensagem na conversa. */
function bolha_mensagem(array $m): string
{
    $saida = $m['direcao'] === 'saida';
    $icones = ['audio' => 'mic', 'imagem' => 'image', 'video' => 'video', 'documento' => 'paperclip', 'sticker' => 'image', 'localizacao' => 'map-pin', 'outro' => 'file'];
    $conteudo = '';
    if ($m['tipo'] !== 'texto') {
        $conteudo .= '<div class="text-muted-foreground mb-1 flex items-center gap-1 text-xs">' . icone($icones[$m['tipo']] ?? 'file', 'size-3') . ' '
            . e(trim(strip_tags(Canais::descricao((string) $m['tipo'], null)), '[]')) . '</div>';
    }
    $texto = trim((string) $m['texto']);
    if ($texto !== '') {
        $conteudo .= '<div class="text-sm whitespace-pre-line break-words">' . e($texto) . '</div>';
    }
    return '<li class="flex ' . ($saida ? 'justify-end' : 'justify-start') . '">'
        . '<div class="max-w-[85%] rounded-lg border px-3 py-2 ' . ($saida ? 'bg-primary/10' : 'bg-card') . '">' . $conteudo
        . '<div class="text-muted-foreground mt-1 flex items-center justify-end gap-2 text-[11px]"><span>' . e(datahora_br((string) $m['data_hora'])) . '</span>' . tique_mensagem($m) . '</div></div></li>';
}
