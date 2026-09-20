<?php
/**
 * Editor de squad: JSON com validação, executar (squads sem registro), execuções recentes, versões e referência do formato.
 * @var array|null $squad linha de SquadRepository (null = novo)
 * @var string $json
 * @var array<string,string> $erros
 * @var list<array> $versoes
 * @var list<array> $execucoes cabeçalhos recentes
 * @var array|null $agenda agendamento (próximo disparo)
 */
use App\Services\AI\AgenteDefinicao;
use App\Services\AI\SquadDefinicao;
use App\Services\Events;

$id = $squad !== null ? (int) $squad['id'] : null;
$acao = $id !== null ? url("/squads/{$id}") : url('/squads');
$def = $squad['def'] ?? [];

$listaErros = '';
if ($erros !== []) {
    $listaErros = '<div class="alert" role="alert" data-variant="destructive">' . icone('circle-alert') . '<h2>A definição não foi salva</h2><section><ul class="list-inside list-disc">';
    foreach ($erros as $mensagem) {
        $listaErros .= '<li>' . e($mensagem) . '</li>';
    }
    $listaErros .= '</ul></section></div>';
}

// Mesmos atributos data-agente-* do editor de agentes: agentes.js valida a definição pelo endpoint indicado.
$editor = '<form method="post" action="' . e($acao) . '" class="grid gap-3" data-agente-editor>' . csrf_field()
    . '<label class="sr-only" for="agente-json">Definição do squad (JSON)</label>'
    . '<textarea id="agente-json" name="definicao" class="textarea font-mono text-xs leading-relaxed" rows="26" spellcheck="false" data-agente-json'
    . ' data-validar-url="' . e(url('/api/squads/validar')) . '">' . e($json) . '</textarea>'
    . '<div class="text-sm" data-agente-validacao aria-live="polite"></div>'
    . '<div class="flex flex-wrap items-center gap-2">'
    . botao('Salvar', ['tipo' => 'submit', 'icone' => 'check'])
    . botao('Validar', ['variante' => 'outline', 'icone' => 'shield-check', 'attrs' => ['data-agente-validar' => true]])
    . ($id !== null ? botao('Exportar', ['href' => url("/squads/{$id}/exportar"), 'variante' => 'ghost', 'icone' => 'download']) : '')
    . botao('Voltar', ['href' => url('/squads'), 'variante' => 'ghost'])
    . '</div></form>';

// ---- Executar
$executar = '';
if ($id !== null) {
    if (($def['entrada'] ?? '') === 'nenhuma') {
        $corpo = '<div data-squad-executar data-url="' . e(url("/api/squads/{$id}/executar")) . '">'
            . '<p class="text-muted-foreground mb-3 text-sm">Põe o squad na fila; o worker o executa em até 1 minuto.</p>'
            . botao('Executar agora', ['icone' => 'play', 'attrs' => ['data-squad-executar-botao' => true, 'disabled' => (int) ($squad['ativo'] ?? 0) !== 1 ? true : null]]) . '</div>';
    } else {
        $corpo = '<p class="text-muted-foreground text-sm">Este squad trabalha sobre um registro: abra a empresa, o negócio… e use o menu <b>Agentes</b> do registro, ou digite <code>#' . e($squad['slug']) . ' Nome</code> no chat.</p>';
    }
    if ($agenda !== null && (int) $squad['ativo'] === 1) {
        $corpo .= '<p class="text-muted-foreground mt-3 text-sm">Próxima execução agendada: <b>' . e(datahora_br((string) $agenda['proximo_run_em'])) . '</b> (<code>' . e((string) $agenda['cron']) . '</code>).</p>';
    }
    $executar = card(['titulo' => 'Executar', 'corpo_html' => $corpo]);
}

// ---- Execuções recentes
$recentes = '';
if ($id !== null) {
    $itens = '';
    foreach ($execucoes as $x) {
        [$rotulo, $variante] = squad_status_rotulo((string) $x['status']);
        $itens .= '<li class="flex items-center gap-2 border-b py-2 last:border-b-0"><a class="min-w-0 flex-1 underline-offset-4 hover:underline" href="' . e(url('/squads/execucoes/' . (int) $x['id'])) . '">'
            . '#' . (int) $x['id'] . ' · ' . e(datahora_br((string) $x['iniciado_em'])) . '</a>' . badge($rotulo, $variante) . '</li>';
    }
    $recentes = card(['titulo' => 'Execuções recentes', 'tamanho' => 'sm',
        'corpo_html' => $itens !== '' ? '<ul>' . $itens . '</ul>' : '<p class="text-muted-foreground text-sm">Nenhuma execução ainda.</p>']);
}

// ---- Versões
$versoesHtml = '';
if ($id !== null) {
    $itens = '';
    foreach ($versoes as $v) {
        $atual = (int) $v['versao'] === (int) $squad['versao'];
        $itens .= '<li class="flex items-center gap-2 border-b py-2 last:border-b-0"><div class="min-w-0 flex-1"><span class="font-medium">v' . (int) $v['versao'] . '</span>'
            . ($atual ? ' ' . badge('atual', 'success') : '') . '<div class="text-muted-foreground text-xs">' . e(datahora_br($v['criado_em'])) . ' · ' . count((array) ($v['definicao']['etapas'] ?? [])) . ' etapa(s)</div></div>';
        if (!$atual) {
            $itens .= '<form method="post" action="' . e(url("/squads/{$id}/versoes/" . (int) $v['versao'] . '/restaurar')) . '" data-confirmar="Restaurar a versão ' . (int) $v['versao'] . '? Ela vira uma nova versão.">'
                . csrf_field() . botao('Restaurar', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'rotate-ccw']) . '</form>';
        }
        $itens .= '</li>';
    }
    $versoesHtml = card(['titulo' => 'Versões', 'descricao' => 'Cada salvamento com mudanças cria uma versão nova.', 'corpo_html' => '<ul>' . $itens . '</ul>']);
}

// ---- Referência
$ref = '<dl class="grid gap-2 text-sm">'
    . '<div><dt class="font-medium">entrada</dt><dd class="text-muted-foreground">' . e(implode(', ', AgenteDefinicao::ENTRADAS)) . '. Agentes de outra entidade usam o registro relacionado (a empresa de um negócio; o negócio aberto mais recente de uma empresa); sem registro relacionado, a etapa é pulada.</dd></div>'
    . '<div><dt class="font-medium">etapas</dt><dd class="text-muted-foreground">Em ordem. <code>{"agente":"slug"}</code> ou <code>{"acao":"tarefa"|"converter_cliente"}</code> (ação fixa, sem IA; tarefa leva <code>dados</code>: titulo, descricao, prioridade, tipo, vencimento_em_dias). Etapa com aprovação pendente pausa o squad.</dd></div>'
    . '<div><dt class="font-medium">condicao / parar_se</dt><dd class="text-muted-foreground">Uma comparação: <code>variável operador valor</code> com ==, !=, in [A,B], &gt;, &lt;, &gt;=, &lt;=. Variáveis: <code>origem</code> (humano, ia, sistema, agente, formulario); <code>agente.status</code> e <code>agente.resumo</code> (de etapas anteriores); <code>' . e(implode('|', array_keys(SquadDefinicao::ENTIDADES_DE_CONDICAO))) . '.campo</code> (lido na hora, dinheiro em reais).</dd></div>'
    . '<div><dt class="font-medium">usa_saida_de</dt><dd class="text-muted-foreground">Envia o resumo das etapas indicadas ao agente como texto de apoio.</dd></div>'
    . '<div><dt class="font-medium">gatilho</dt><dd class="text-muted-foreground">manual; <code>{"tipo":"evento","evento":"..."}</code> (' . e(implode(', ', Events::CONHECIDOS)) . '); <code>{"tipo":"agendado","cron":"0 8 * * 1"}</code> (só com entrada "nenhuma").</dd></div>'
    . '</dl>';
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo"><?= $squad !== null ? e($squad['nome']) : 'Novo squad' ?></h1>
        <p class="text-muted-foreground">
            <?php if ($squad !== null): ?>#<?= e($squad['slug']) ?> · versão <?= (int) $squad['versao'] ?> · <?= (int) $squad['ativo'] === 1 ? 'ativo' : 'inativo' ?>
            <?php else: ?>Escreva a definição em JSON (formato .squad.json). Ela é validada ao salvar.<?php endif; ?>
        </p>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-3 lg:col-span-2">
        <?= $listaErros ?>
        <?= card(['corpo_html' => $editor]) ?>
    </div>
    <div class="grid content-start gap-4">
        <?= $executar ?>
        <?= $recentes ?>
        <?= $versoesHtml ?>
        <?= card(['titulo' => 'Referência do formato', 'tamanho' => 'sm', 'corpo_html' => $ref]) ?>
    </div>
</div>
