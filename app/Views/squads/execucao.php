<?php
/**
 * Andamento de uma execução de squad (atualiza por polling enquanto não terminar).
 * @var array $cabecalho linha de execucoes (cabeçalho)
 * @var array|null $squad
 * @var array $estado
 * @var array<int,array> $etapas
 * @var array{entrada:int,saida:int} $tokens
 */
$rotaEntidade = ['empresas' => '/empresas/', 'contatos' => '/contatos/', 'negocios' => '/negocios/', 'propostas' => '/propostas/', 'contratos' => '/contratos/'];
$id = (int) $cabecalho['id'];
$alvo = '';
if ($cabecalho['entidade'] !== null && isset($rotaEntidade[$cabecalho['entidade']])) {
    $alvo = ' · sobre <a class="underline underline-offset-4" href="' . e(url($rotaEntidade[$cabecalho['entidade']] . (int) $cabecalho['registro_id'])) . '">' . e($cabecalho['entidade']) . ' #' . (int) $cabecalho['registro_id'] . '</a>';
}
$aberta = in_array($cabecalho['status'], ['fila', 'rodando', 'aguardando_aprovacao'], true);
$dadosDeEntrada = (array) json_decode((string) $cabecalho['entrada'], true);
$origem = (string) ($dadosDeEntrada['origem'] ?? '');
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo"><?= e($estado['nome'] ?? ($squad['nome'] ?? 'Squad')) ?></h1>
        <p class="text-muted-foreground">
            Execução #<?= $id ?> · disparada por <?= e($origem !== '' ? $origem : 'humano') ?><?= $alvo ?>
            <?php if ($squad !== null): ?> · <a class="underline underline-offset-4" href="<?= e(url('/squads/' . (int) $squad['id'] . '/editar')) ?>">ver squad</a><?php endif; ?>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if (in_array($cabecalho['status'], ['fila', 'aguardando_aprovacao'], true)): ?>
            <form method="post" action="<?= e(url("/squads/execucoes/{$id}/cancelar")) ?>" data-confirmar="Cancelar esta execução?">
                <?= csrf_field() ?><?= botao('Cancelar execução', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'x']) ?>
            </form>
        <?php endif; ?>
        <?= botao('Execuções', ['href' => url('/execucoes?tipo=squads'), 'variante' => 'ghost', 'icone' => 'activity']) ?>
    </div>
</div>

<?= card(['corpo_html' => '<div data-squad-progresso data-url="' . e(url('/api/squads/execucoes/' . $id)) . '" data-aberta="' . ($aberta ? '1' : '0') . '" aria-live="polite">'
    . squad_execucao_html($cabecalho, $estado, $etapas, $tokens) . '</div>']) ?>
