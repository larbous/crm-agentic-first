<?php
/**
 * Log de auditoria com Desfazer.
 * @var array $filtros
 * @var array $resultado
 * @var int|null $ultimaId
 * @var array $entidades nome => plural
 */
$acoes = [
    'criar' => 'Criar', 'atualizar' => 'Atualizar', 'arquivar' => 'Arquivar', 'mover_etapa' => 'Mover etapa', 'concluir' => 'Concluir',
    'converter_cliente' => 'Converter em cliente', 'definir_tags' => 'Definir tags', 'vincular_contato' => 'Vincular contato',
    'desvincular_contato' => 'Desvincular contato', 'enviar_proposta' => 'Enviar proposta', 'visualizar_proposta' => 'Proposta visualizada',
    'aceitar_proposta' => 'Aceite de proposta', 'recusar_proposta' => 'Recusa de proposta', 'nova_versao' => 'Nova versão', 'enviar_contrato' => 'Enviar contrato',
    'visualizar_contrato' => 'Contrato visualizado', 'assinar_contrato' => 'Assinatura de contrato', 'cancelar_contrato' => 'Cancelar contrato',
    'renovar_contrato' => 'Renovar contrato', 'desfazer' => 'Desfazer',
];
$origens = ['humano' => 'Humano', 'ia' => 'IA', 'agente' => 'Agente', 'formulario' => 'Formulário', 'sistema' => 'Sistema'];
$linksEntidade = ['empresas' => '/empresas/', 'contatos' => '/contatos/', 'negocios' => '/negocios/', 'propostas' => '/propostas/', 'contratos' => '/contratos/'];
$voltar = $_SERVER['REQUEST_URI'] ?? '/auditoria';
$variantesAcao = ['criar' => 'success', 'arquivar' => 'destructive', 'desfazer' => 'warning'];

$linhas = [];
foreach ($resultado['linhas'] as $l) {
    $antes = $l['antes'] !== null ? json_decode($l['antes'], true) : null;
    $depois = $l['depois'] !== null ? json_decode($l['depois'], true) : null;
    $rotuloEntidade = $entidades[$l['entidade']] ?? $l['entidade'];
    $alvo = e($rotuloEntidade) . ' #' . (int) $l['registro_id'];
    if (isset($linksEntidade[$l['entidade']]) && $l['registro_id']) {
        $alvo = '<a class="underline-offset-4 hover:underline" href="' . e(url($linksEntidade[$l['entidade']] . (int) $l['registro_id'])) . '">' . $alvo . '</a>';
    }

    if ($l['acao'] === 'criar') {
        $detalhe = diff_html(null, is_array($depois) ? array_filter($depois, static fn ($v, $k) => $v !== null && $v !== '' && $k !== 'id' && $k !== 'arquivado_em', ARRAY_FILTER_USE_BOTH) : null, $l['entidade']);
    } else {
        $detalhe = diff_html($antes, $depois, $l['entidade']);
    }

    $estado = '';
    $podeDesfazer = $l['acao'] !== 'desfazer' && $l['desfeito_em'] === null;
    if ($l['desfeito_em'] !== null) {
        $estado = '<span class="text-muted-foreground text-xs">Desfeito em ' . e(datahora_br($l['desfeito_em'])) . '</span>';
    } elseif ($podeDesfazer) {
        $estado = '<form method="post" action="' . e(url('/auditoria/' . (int) $l['id'] . '/desfazer')) . '" data-confirmar="Desfazer esta ação?">' . csrf_field()
            . '<input type="hidden" name="voltar" value="' . e($voltar) . '">'
            . botao('Desfazer', ['tipo' => 'submit', 'variante' => 'outline', 'tamanho' => 'xs', 'icone' => 'undo-2']) . '</form>';
    }

    $linhas[] = [
        'data'    => datahora_br($l['data']),
        'origem'  => ['html' => badge($l['origem'], 'outline')],
        'alvo'    => ['html' => $alvo],
        'acao'    => ['html' => badge($acoes[$l['acao']] ?? $l['acao'], $variantesAcao[$l['acao']] ?? 'secondary')],
        'detalhe' => ['html' => $detalhe],
        'estado'  => ['html' => $estado],
    ];
}

$url = static fn (array $extra): string => url('/auditoria?' . http_build_query(array_filter($extra + $filtros, static fn ($v) => $v !== '' && $v !== null)));
$temFiltro = array_filter($filtros, static fn ($v) => $v !== '' && $v !== null) !== [];
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Auditoria</h1>
        <p class="text-muted-foreground"><?= (int) $resultado['total'] ?> registro(s). Toda alteração feita por você, pela IA, por agentes ou pelo sistema aparece aqui.</p>
    </div>
    <?php if ($ultimaId !== null): ?>
        <form method="post" action="<?= e(url('/desfazer')) ?>" data-confirmar="Desfazer a última ação registrada?">
            <?= csrf_field() ?><input type="hidden" name="voltar" value="<?= e($voltar) ?>">
            <?= botao('Desfazer última ação', ['tipo' => 'submit', 'variante' => 'secondary', 'icone' => 'undo-2']) ?>
        </form>
    <?php endif; ?>
</div>

<form method="get" action="<?= e(url('/auditoria')) ?>" class="filter-bar">
    <?= select('entidade', $entidades, $filtros['entidade'] ?: null, ['id' => 'f-entidade', 'placeholder' => 'Entidade: todas', 'attrs' => ['class' => 'select w-auto', 'aria-label' => 'Entidade', 'onchange' => 'this.form.requestSubmit()']]) ?>
    <?= select('origem', $origens, $filtros['origem'] ?: null, ['id' => 'f-origem', 'placeholder' => 'Origem: todas', 'attrs' => ['class' => 'select w-auto', 'aria-label' => 'Origem', 'onchange' => 'this.form.requestSubmit()']]) ?>
    <?= select('acao', $acoes, $filtros['acao'] ?: null, ['id' => 'f-acao', 'placeholder' => 'Ação: todas', 'attrs' => ['class' => 'select w-auto', 'aria-label' => 'Ação', 'onchange' => 'this.form.requestSubmit()']]) ?>
    <input type="text" name="registro_id" class="input w-28" placeholder="ID do registro" inputmode="numeric" value="<?= e($filtros['registro_id'] ?? '') ?>" aria-label="ID do registro">
    <?= botao('Filtrar', ['tipo' => 'submit', 'variante' => 'secondary', 'tamanho' => 'sm']) ?>
    <?php if ($temFiltro): ?><?= botao('Limpar', ['href' => url('/auditoria'), 'variante' => 'ghost', 'tamanho' => 'sm', 'icone' => 'x']) ?><?php endif; ?>
</form>

<?= data_table(
    ['data' => ['rotulo' => 'Data'], 'origem' => ['rotulo' => 'Origem'], 'alvo' => ['rotulo' => 'Registro'], 'acao' => ['rotulo' => 'Ação'], 'detalhe' => ['rotulo' => 'Alterações'], 'estado' => ['rotulo' => '']],
    $linhas,
    ['id' => 'tabela-auditoria', 'vazio_html' => vazio('Nada registrado', $temFiltro ? 'Nenhum registro com esses filtros.' : 'As alterações aparecerão aqui.', ['icone' => 'scroll-text'])],
) ?>

<?= paginacao($resultado['pagina'], $resultado['paginas'], $resultado['total'], $resultado['por_pagina'], static fn (int $p): string => $url(['pagina' => $p])) ?>
