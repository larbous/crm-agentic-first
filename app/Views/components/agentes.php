<?php

declare(strict_types=1);

use App\Repositories\AgenteRepository;
use App\Repositories\SquadRepository;
use App\Services\AI\AcaoAgente;

/**
 * Botões de agentes e squads no detalhe de um registro: menu "Agentes" com os agentes e squads ativos que trabalham sobre a
 * entidade e um diálogo para o texto de apoio opcional (squads vão para a fila do worker e não têm simulação). O comportamento vem de /assets/js/agentes.js.
 */
function agentes_botoes(string $entidade, int $registroId): string
{
    $agentes = (new AgenteRepository())->ativosDaEntrada($entidade);
    $squads = (new SquadRepository())->ativosDaEntrada($entidade);
    if ($agentes === [] && $squads === []) {
        return '';
    }
    $itens = [['grupo' => 'Executar agente']];
    foreach ($agentes as $a) {
        $itens[] = ['rotulo' => $a['nome'], 'icone' => 'bot', 'attrs' => [
            'data-agente-executar' => (int) $a['id'], 'data-agente-nome' => $a['nome'], 'data-executar-url' => url('/api/agentes/' . (int) $a['id'] . '/executar'),
            'data-agente-descricao' => (string) $a['descricao'], 'data-registro' => $registroId,
        ]];
    }
    if ($squads !== []) {
        $itens[] = ['grupo' => 'Executar squad (em segundo plano)'];
        foreach ($squads as $s) {
            $itens[] = ['rotulo' => $s['nome'], 'icone' => 'workflow', 'attrs' => [
                'data-agente-executar' => (int) $s['id'], 'data-agente-nome' => $s['nome'], 'data-executar-url' => url('/api/squads/' . (int) $s['id'] . '/executar'),
                'data-agente-descricao' => (string) $s['descricao'], 'data-registro' => $registroId, 'data-sem-simulacao' => 1,
            ]];
        }
    }
    $itens[] = ['separador' => true];
    $itens[] = ['rotulo' => 'Ações pendentes', 'icone' => 'inbox', 'href' => url('/acoes-pendentes')];

    $corpo = '<form id="form-agente" class="grid gap-3" data-agente-form>'
        . '<p class="text-muted-foreground text-sm" data-agente-descricao-texto></p>'
        . campo(['nome' => 'entrada', 'id' => 'agente-entrada', 'rotulo' => 'Texto de apoio (opcional)', 'tipo' => 'textarea', 'linhas' => 5,
            'ajuda' => 'Notas de reunião, uma instrução extra… Alguns agentes usam este texto.', 'attrs' => ['maxlength' => 2000]])
        . '<label class="flex items-center gap-2 text-sm" data-agente-simulacao><input type="checkbox" class="input" name="simulacao" value="1"> Só simular (não grava nada)</label>'
        . '</form><div data-agente-saida></div>';
    return menu('menu-agentes', icone('bot') . '<span>Agentes</span>', $itens, ['alinhar' => 'end'])
        . modal('modal-agente', [
            'titulo' => 'Executar agente',
            'corpo_html' => $corpo,
            'rodape_html' => botao('Cancelar', ['variante' => 'outline', 'attrs' => ['onclick' => "this.closest('dialog').close()"]])
                . botao('Executar', ['tipo' => 'submit', 'icone' => 'play', 'attrs' => ['form' => 'form-agente', 'data-agente-enviar' => true]]),
        ]);
}

/**
 * Resultado de uma execução (tela de teste, botão do detalhe): resumo, texto, ações aplicadas/pendentes/simuladas e recusadas.
 * @param array $r retorno de AgentRunner::executar()
 */
function agente_resultado_html(array $r): string
{
    if (!$r['ok']) {
        return alerta('Não foi possível concluir', (string) $r['erro'], 'destructive', 'triangle-alert');
    }
    $html = '<div class="grid gap-3">';
    if ($r['simulacao']) {
        $html .= '<p class="text-muted-foreground text-sm">Simulação: nada foi gravado.</p>';
    }
    if ($r['resumo'] !== '') {
        $html .= '<div><div class="text-muted-foreground text-xs font-medium uppercase">Resumo</div><p class="text-sm">' . e($r['resumo']) . '</p></div>';
    }
    if ($r['status'] !== null) {
        $html .= '<div>' . badge('status: ' . $r['status'], 'outline') . '</div>';
    }
    if ($r['texto'] !== '') {
        $html .= '<div><div class="text-muted-foreground text-xs font-medium uppercase">Texto</div><div class="rounded-md border p-3 text-sm whitespace-pre-line">' . e($r['texto']) . '</div></div>';
    }
    foreach ($r['previas'] as $p) {
        $html .= '<div class="rounded-md border p-3"><div class="mb-2 flex flex-wrap items-center gap-2 text-sm font-medium">' . e($p['descricao'])
            . ($p['direto'] ? badge('aplicaria direto', 'secondary') : badge('pediria aprovação', 'warning'))
            . (!empty($p['motivo']) ? ' <span class="text-muted-foreground text-xs font-normal">' . e($p['motivo']) . '</span>' : '') . '</div>'
            . diff_html($p['antes'], $p['depois'], $p['entidade']) . '</div>';
    }
    foreach ($r['aplicadas'] as $a) {
        $html .= '<div class="flex items-center gap-2 text-sm">' . icone('circle-check', 'size-4 text-[var(--success)]') . e($a['descricao']) . '</div>';
    }
    foreach ($r['pendentes'] as $p) {
        $html .= '<div class="flex items-center gap-2 text-sm">' . icone('clock', 'size-4') . e($p['descricao']) . ' ' . badge('aguardando aprovação', 'warning')
            . (!empty($p['motivo']) ? ' <span class="text-muted-foreground text-xs">' . e($p['motivo']) . '</span>' : '') . '</div>';
    }
    if ($r['pendentes'] !== []) {
        $html .= '<div>' . botao('Revisar ações pendentes', ['href' => url('/acoes-pendentes'), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'inbox']) . '</div>';
    }
    foreach ($r['recusadas'] as $motivo) {
        $html .= '<div class="flex items-start gap-2 text-sm text-destructive">' . icone('circle-x', 'mt-0.5 size-4 shrink-0') . '<span>' . e($motivo) . '</span></div>';
    }
    if ($r['resumo'] === '' && $r['texto'] === '' && $r['previas'] === [] && $r['aplicadas'] === [] && $r['pendentes'] === [] && $r['recusadas'] === []) {
        $html .= '<p class="text-muted-foreground text-sm">O agente não propôs nenhuma ação.</p>';
    }
    return $html . '</div>';
}

/** Ação pendente para a tela de aprovação: o que será feito e o diff antes/depois calculado agora. */
function acao_pendente_diff(array $pendente): string
{
    $previa = AcaoAgente::previa((array) $pendente['def']);
    return diff_html($previa['antes'], $previa['depois'] === [] ? null : $previa['depois'], $previa['entidade']);
}
