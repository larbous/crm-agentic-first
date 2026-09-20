<?php
/**
 * Lista de squads.
 * @var list<array> $squads linhas de SquadRepository::todos() (com `def`)
 * @var array<int,array> $agenda agendamentos por squad_id
 */
$entradas = ['empresas' => 'Empresas', 'contatos' => 'Contatos', 'negocios' => 'Negócios', 'propostas' => 'Propostas', 'contratos' => 'Contratos', 'nenhuma' => 'Sem registro'];

$linhas = [];
foreach ($squads as $s) {
    $d = $s['def'];
    $id = (int) $s['id'];
    $ativo = (int) $s['ativo'] === 1;
    $g = (array) $d['gatilho'];
    $gatilho = match ($g['tipo'] ?? 'manual') {
        'evento' => 'Evento: ' . ($g['evento'] ?? ''),
        'agendado' => 'Agenda: ' . ($g['cron'] ?? '') . (isset($agenda[$id]) && $ativo ? ' (próx.: ' . datahora_br((string) $agenda[$id]['proximo_run_em']) . ')' : ''),
        default => 'Manual',
    };
    $etapas = implode(' → ', array_map(static fn (array $e): string => $e['agente'] ?? ($e['acao'] === 'tarefa' ? 'tarefa' : 'cliente'), $d['etapas']));
    $alternar = '<form method="post" action="' . e(url("/squads/{$id}/ativo")) . '">' . csrf_field()
        . '<input type="hidden" name="ativo" value="' . ($ativo ? '0' : '1') . '">'
        . botao($ativo ? 'Desativar' : 'Ativar', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'xs']) . '</form>';
    $linhas[] = [
        'squad' => ['html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url("/squads/{$id}/editar")) . '">' . e($s['nome']) . '</a>'
            . '<div class="text-muted-foreground text-xs">#' . e($s['slug']) . ' · ' . e($s['descricao']) . '</div>'],
        'etapas' => ['html' => '<span class="text-xs">' . e($etapas) . '</span>'],
        'entrada' => $entradas[$d['entrada']] ?? $d['entrada'],
        'gatilho' => $gatilho,
        'versao' => 'v' . (int) $s['versao'],
        'situacao' => ['html' => $ativo ? badge('Ativo', 'success') : badge('Inativo', 'secondary')],
        'acoes' => ['html' => '<div class="flex items-center justify-end gap-1">'
            . botao('Editar', ['href' => url("/squads/{$id}/editar"), 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'pencil'])
            . botao('Exportar', ['href' => url("/squads/{$id}/exportar"), 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'download'])
            . $alternar . '</div>'],
    ];
}

$corpoImportar = '<form id="form-importar" method="post" action="' . e(url('/squads/importar')) . '" enctype="multipart/form-data" class="grid gap-3">' . csrf_field()
    . campo(['nome' => 'arquivo', 'id' => 'imp-arquivo', 'rotulo' => 'Arquivo .squad.json', 'tipo' => 'file', 'attrs' => ['accept' => '.json,application/json']])
    . campo(['nome' => 'definicao', 'id' => 'imp-texto', 'rotulo' => 'ou cole o JSON', 'tipo' => 'textarea', 'linhas' => 6, 'attrs' => ['class' => 'textarea font-mono text-xs']])
    . '<p class="text-muted-foreground text-sm">Os agentes citados nas etapas precisam existir. Se o slug já existir, o squad ganha uma nova versão.</p></form>';
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Squads</h1>
        <p class="text-muted-foreground">Squads encadeiam agentes (e ações fixas) em etapas. Rodam em segundo plano pelo worker: disparam por evento, por agenda ou manualmente (botão do registro, <code>#slug</code> no chat). Acompanhe em <a class="underline underline-offset-4" href="<?= e(url('/execucoes?tipo=squads')) ?>">Execuções</a>.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Importar', ['variante' => 'outline', 'icone' => 'upload', 'attrs' => ['data-abrir-modal' => 'modal-importar']]) ?>
        <?= botao('Novo squad', ['href' => url('/squads/nova'), 'icone' => 'plus']) ?>
    </div>
</div>

<?= data_table(
    ['squad' => ['rotulo' => 'Squad'], 'etapas' => ['rotulo' => 'Etapas'], 'entrada' => ['rotulo' => 'Trabalha sobre'], 'gatilho' => ['rotulo' => 'Gatilho'],
     'versao' => ['rotulo' => 'Versão'], 'situacao' => ['rotulo' => 'Situação'], 'acoes' => ['rotulo' => '']],
    $linhas,
    ['id' => 'tabela-squads', 'vazio_html' => vazio('Nenhum squad', 'Rode "php scripts/seed.php" para importar a biblioteca inicial ou crie um squad.', ['icone' => 'workflow'])],
) ?>

<?= modal('modal-importar', [
    'titulo' => 'Importar squad',
    'corpo_html' => $corpoImportar,
    'rodape_html' => botao('Cancelar', ['variante' => 'outline', 'attrs' => ['onclick' => "this.closest('dialog').close()"]])
        . botao('Importar', ['tipo' => 'submit', 'icone' => 'upload', 'attrs' => ['form' => 'form-importar']]),
]) ?>
