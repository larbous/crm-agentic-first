<?php
/**
 * Detalhe do negócio.
 * @var array $registro
 * @var list<array> $etapas
 * @var list<array> $vinculados
 * @var list<array> $propostas
 * @var list<array> $contratos
 * @var list<array> $tarefas
 * @var list<array> $atividades
 * @var list<array> $anexos
 * @var list<array> $tags
 * @var array $tagsOpcoes
 * @var int|null $diasNaEtapa
 * @var array $contatosOpcoes
 * @var array $motivosPerda
 */
use App\Services\Schema;

$r = $registro;
$id = (int) $r['id'];
$voltar = '/negocios/' . $id;
$op = static fn (string $grupo, ?string $v) => $v !== null ? (Schema::opcoes($grupo)[$v] ?? $v) : '';
$dinheiro = static fn (?int $c) => $c !== null ? moeda($c) : '';
$temperatura = $r['temperatura'] ? ['html' => '<span class="text-temp-' . e($r['temperatura']) . ' font-medium">' . e($op('temperatura', $r['temperatura'])) . '</span>'] : '';

$linksContato = static function (?int $cid, ?string $nome): array|string {
    return $cid && $nome ? ['html' => link_para('/contatos/' . $cid, $nome)] : '';
};
$decisor = '';
if ($r['decisor_id']) {
    $d = \App\Repositories\Repositorios::contatos()->encontrar((int) $r['decisor_id']);
    $decisor = $d ? ['html' => link_para('/contatos/' . (int) $d['id'], $d['nome_completo'])] : '';
}

// Contatos vinculados
$linhasVinc = '';
foreach ($vinculados as $c) {
    $linhasVinc .= '<li class="flex items-center gap-3 border-b py-2 last:border-b-0">' . avatar($c['nome_completo'], null, 'sm')
        . '<div class="min-w-0 flex-1"><a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/contatos/' . (int) $c['id'])) . '">' . e($c['nome_completo']) . '</a>'
        . '<div class="text-muted-foreground text-xs">' . e(implode(' · ', array_filter([$c['papel'], $c['cargo'], $c['email']]))) . '</div></div>'
        . '<form method="post" action="' . e(url('/negocios/' . $id . '/contatos/' . (int) $c['id'] . '/remover')) . '" data-confirmar="Desvincular este contato do negócio?">' . csrf_field()
        . botao('Desvincular contato', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'icon-sm', 'icone' => 'x']) . '</form></li>';
}
$formVinc = '<form method="post" action="' . e(url('/negocios/' . $id . '/contatos')) . '" class="mb-3 flex flex-wrap items-end gap-2">' . csrf_field()
    . '<div class="min-w-56 flex-1">' . campo(['nome' => 'contato_id', 'id' => 'vinc-contato', 'rotulo' => 'Vincular contato', 'controle_html' => select('contato_id', $contatosOpcoes, null, ['id' => 'vinc-contato', 'placeholder' => 'Selecione…', 'obrigatorio' => true])]) . '</div>'
    . '<div class="w-44">' . campo(['nome' => 'papel', 'id' => 'vinc-papel', 'rotulo' => 'Papel', 'placeholder' => 'Ex.: financeiro', 'attrs' => ['maxlength' => 80]]) . '</div>'
    . botao('Vincular', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'plus']) . '</form>';

$abas = [
    ['rotulo' => 'Timeline', 'html' => atividade_form(['negocio_id' => $id, 'empresa_id' => (int) $r['empresa_id']], $voltar) . timeline_ia('negocios', $id) . timeline($atividades, ['voltar' => $voltar, 'contexto' => true])],
    ['rotulo' => 'Contatos', 'contagem' => count($vinculados), 'html' => $formVinc . ($linhasVinc !== '' ? '<ul>' . $linhasVinc . '</ul>' : vazio('Nenhum contato vinculado', 'Além do contato principal, vincule quem participa da decisão.', ['icone' => 'users']))],
    ['rotulo' => 'Propostas', 'contagem' => count($propostas), 'html' =>
        '<div class="mb-2 flex justify-end">' . botao('Nova proposta', ['href' => url('/propostas/nova?negocio_id=' . $id), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus']) . '</div>'
        . tabela_propostas($propostas)],
    ['rotulo' => 'Contratos', 'contagem' => count($contratos), 'html' =>
        '<div class="mb-2 flex justify-end">' . botao('Novo contrato', ['href' => url('/contratos/nova?negocio_id=' . $id), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus']) . '</div>'
        . tabela_contratos($contratos)],
    ['rotulo' => 'Tarefas', 'contagem' => count(array_filter($tarefas, 'tarefa_aberta')), 'html' => tarefas_mini($tarefas, 'negocio_id', $id, $voltar)],
    ['rotulo' => 'Anexos', 'contagem' => count($anexos), 'html' => anexos_painel($anexos, 'negocios', $id, $voltar)],
];

$opcoesEtapa = '';
foreach ($etapas as $e) {
    $opcoesEtapa .= '<option value="' . (int) $e['id'] . '" data-tipo="' . e($e['tipo']) . '" data-nome="' . e($e['nome']) . '"'
        . ((int) $e['id'] === (int) $r['etapa_id'] ? ' selected' : '') . '>' . e($e['nome']) . '</option>';
}
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="text-muted-foreground text-sm"><?= e($r['codigo']) ?></div>
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e($r['titulo']) ?></h1>
            <?= badge_status('status_negocio', $r['status']) ?>
            <?= pill_etapa((string) $r['etapa_nome'], $r['etapa_cor']) ?>
        </div>
        <p class="text-muted-foreground">
            <?php if ($r['empresa_id']): ?><?= link_para('/empresas/' . (int) $r['empresa_id'], (string) $r['empresa_nome']) ?><?php endif; ?>
            <?php if ($diasNaEtapa !== null): ?> · <?= (int) $diasNaEtapa ?> dia(s) na etapa<?php endif; ?>
        </p>
        <div class="mt-2"><?= tags_painel($tags, $tagsOpcoes, 'negocios', $id, $voltar) ?></div>
    </div>
    <div class="flex flex-wrap items-end gap-2" data-mover-negocio data-negocio-id="<?= $id ?>" data-valor-estimado="<?= (int) $r['valor_estimado'] ?>">
        <?= agentes_botoes('negocios', $id) ?>
        <?php if ($etapas !== []): ?>
            <div>
                <label class="sr-only" for="mover-etapa">Etapa</label>
                <select id="mover-etapa" class="select w-auto" data-mover-select><?= $opcoesEtapa ?></select>
            </div>
            <?= botao('Mover etapa', ['variante' => 'secondary', 'icone' => 'chevron-right', 'attrs' => ['data-mover-confirmar' => true]]) ?>
        <?php endif; ?>
        <?= botao('Editar', ['href' => url('/negocios/' . $id . '/editar'), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?= botao_arquivar(url('/negocios/' . $id . '/arquivar'), 'Arquivar este negócio? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-4">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Valores', 'corpo_html' => ficha([
            'Valor estimado' => $dinheiro($r['valor_estimado']), 'Probabilidade' => $r['probabilidade'] !== null ? $r['probabilidade'] . '%' : '',
            'Valor ponderado' => moeda((int) $r['valor_ponderado']), 'Valor fechado' => $dinheiro($r['valor_fechado']),
            'Tipo de receita' => $op('tipo_receita', $r['tipo_receita']), 'Valor recorrente' => $dinheiro($r['valor_recorrente']),
            'Previsão de fechamento' => data_br($r['previsao_fechamento']), 'Data de fechamento' => data_br($r['data_fechamento']),
        ])]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Pessoas e origem', 'corpo_html' => ficha([
            'Empresa' => $r['empresa_id'] ? ['html' => link_para('/empresas/' . (int) $r['empresa_id'], (string) $r['empresa_nome'])] : '',
            'Contato principal' => $linksContato($r['contato_principal_id'] ? (int) $r['contato_principal_id'] : null, $r['contato_nome']),
            'Decisor' => $decisor, 'Origem' => (string) $r['origem_nome'],
        ])]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Qualificação e andamento', 'corpo_html' => ficha([
            'Temperatura' => $temperatura, 'Prioridade' => $op('prioridade_neg', $r['prioridade']),
            'Dor principal' => (string) $r['dor_principal'], 'Objetivo' => (string) $r['objetivo_cliente'],
            'Orçamento do cliente' => (string) $r['orcamento_cliente'], 'Prazo desejado' => (string) $r['prazo_desejado'],
            'Critério de decisão' => (string) $r['criterio_decisao'], 'Concorrentes' => (string) $r['concorrentes'],
            'Próximo passo' => (string) $r['proximo_passo'], 'Próximo passo em' => data_br($r['proximo_passo_em']),
            'Motivo da perda' => (string) $r['motivo_perda_nome'], 'Detalhe da perda' => (string) $r['detalhe_perda'],
        ], 1)]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Qualificação CHAMP', 'descricao' => $r['champ_pontos'] !== null ? 'Pontuação ' . (int) $r['champ_pontos'] . ' de ' . \App\Services\Champ::MAXIMO . ' · avaliado em ' . datahora_br((string) $r['champ_avaliado_em']) : 'Ainda não avaliado. Rode o agente Qualificador de Lead ou preencha ao editar o negócio.', 'corpo_html' => ficha([
            'Desafios (dor confirmada)' => $op('champ', $r['champ_desafios']), 'Autoridade (decisor)' => $op('champ', $r['champ_autoridade']),
            'Dinheiro (orçamento)' => $op('champ', $r['champ_dinheiro']), 'Prioridade (urgência)' => $op('champ', $r['champ_prioridade']),
            'Justificativa' => (string) $r['champ_resumo'],
        ], 1)]) ?>
        <?= card_campos_extras('negocios', $r) ?>
        <?php if ($r['notas']): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Notas', 'corpo_html' => '<p class="text-sm whitespace-pre-line">' . e($r['notas']) . '</p>']) ?>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2">
        <?= card(['corpo_html' => abas('abas-negocio', $abas, 0, ['linha' => true])]) ?>
    </div>
</div>

<?= modal_etapa($motivosPerda) ?>
