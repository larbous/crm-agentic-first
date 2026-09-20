<?php
/**
 * Lista de agentes.
 * @var list<array> $agentes linhas de AgenteRepository::todos() (com `def`)
 */
use App\Services\Schema;

$entradas = ['empresas' => 'Empresas', 'contatos' => 'Contatos', 'negocios' => 'Negócios', 'propostas' => 'Propostas', 'contratos' => 'Contratos', 'nenhuma' => 'Sem registro'];
$aprovacoes = ['sempre' => 'Sempre', 'escritas' => 'Escritas', 'nunca' => 'Nunca'];
$gatilhoTexto = static function (array $g): string {
    return match ($g['tipo'] ?? 'manual') {
        'evento' => 'Evento: ' . ($g['evento'] ?? ''),
        'agendado' => 'Agenda: ' . ($g['cron'] ?? ''),
        default => 'Manual',
    };
};

$linhas = [];
foreach ($agentes as $a) {
    $d = $a['def'];
    $id = (int) $a['id'];
    $ativo = (int) $a['ativo'] === 1;
    $alternar = '<form method="post" action="' . e(url("/agentes/{$id}/ativo")) . '">' . csrf_field()
        . '<input type="hidden" name="ativo" value="' . ($ativo ? '0' : '1') . '">'
        . botao($ativo ? 'Desativar' : 'Ativar', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'xs']) . '</form>';
    $linhas[] = [
        'agente' => ['html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url("/agentes/{$id}/editar")) . '">' . e($a['nome']) . '</a>'
            . '<div class="text-muted-foreground text-xs">@' . e($a['slug']) . ' · ' . e($a['descricao']) . '</div>'],
        'modelo' => ['html' => '<span class="text-xs">' . e($d['modelo']) . '</span>' . (!empty($d['web_search']) ? ' ' . badge('web', 'outline') : '')],
        'entrada' => $entradas[$d['entrada']] ?? $d['entrada'],
        'aprovacao' => $aprovacoes[$d['aprovacao']] ?? $d['aprovacao'],
        'gatilho' => $gatilhoTexto((array) $d['gatilho']),
        'versao' => 'v' . (int) $a['versao'],
        'situacao' => ['html' => $ativo ? badge('Ativo', 'success') : badge('Inativo', 'secondary')],
        'acoes' => ['html' => '<div class="flex items-center justify-end gap-1">'
            . botao('Editar', ['href' => url("/agentes/{$id}/editar"), 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'pencil'])
            . botao('Exportar', ['href' => url("/agentes/{$id}/exportar"), 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'download'])
            . $alternar . '</div>'],
    ];
}

$corpoImportar = '<form id="form-importar" method="post" action="' . e(url('/agentes/importar')) . '" enctype="multipart/form-data" class="grid gap-3">' . csrf_field()
    . campo(['nome' => 'arquivo', 'id' => 'imp-arquivo', 'rotulo' => 'Arquivo .agent.json', 'tipo' => 'file', 'attrs' => ['accept' => '.json,application/json']])
    . campo(['nome' => 'definicao', 'id' => 'imp-texto', 'rotulo' => 'ou cole o JSON', 'tipo' => 'textarea', 'linhas' => 6, 'attrs' => ['class' => 'textarea font-mono text-xs']])
    . '<p class="text-muted-foreground text-sm">Se o slug já existir, o agente ganha uma nova versão (a anterior fica no histórico).</p></form>';
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Agentes</h1>
        <p class="text-muted-foreground">Agentes de IA executam tarefas sobre um registro. Só executam as ações e gravam os campos permitidos na definição; com aprovação ativa, as ações aguardam sua decisão em <a class="underline underline-offset-4" href="<?= e(url('/acoes-pendentes')) ?>">Ações pendentes</a>.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Importar', ['variante' => 'outline', 'icone' => 'upload', 'attrs' => ['data-abrir-modal' => 'modal-importar']]) ?>
        <?= botao('Novo agente', ['href' => url('/agentes/nova'), 'icone' => 'plus']) ?>
    </div>
</div>

<?= data_table(
    ['agente' => ['rotulo' => 'Agente'], 'modelo' => ['rotulo' => 'Modelo'], 'entrada' => ['rotulo' => 'Trabalha sobre'], 'aprovacao' => ['rotulo' => 'Aprovação'],
     'gatilho' => ['rotulo' => 'Gatilho'], 'versao' => ['rotulo' => 'Versão'], 'situacao' => ['rotulo' => 'Situação'], 'acoes' => ['rotulo' => '']],
    $linhas,
    ['id' => 'tabela-agentes', 'vazio_html' => vazio('Nenhum agente', 'Rode "php scripts/seed.php" para importar a biblioteca inicial ou crie um agente.', ['icone' => 'bot'])],
) ?>
<p class="text-muted-foreground mt-3 text-sm">Gatilhos por evento e por agenda são guardados na definição, mas só passam a disparar sozinhos com o worker (Fase 6). Por enquanto todos rodam pelo botão do registro, por <code>@slug</code> no chat ou pelo teste.</p>

<?= modal('modal-importar', [
    'titulo' => 'Importar agente',
    'corpo_html' => $corpoImportar,
    'rodape_html' => botao('Cancelar', ['variante' => 'outline', 'attrs' => ['onclick' => "this.closest('dialog').close()"]])
        . botao('Importar', ['tipo' => 'submit', 'icone' => 'upload', 'attrs' => ['form' => 'form-importar']]),
]) ?>
