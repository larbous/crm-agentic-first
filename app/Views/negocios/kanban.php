<?php
/**
 * Kanban de negócios por etapa (arrastar e soltar; ganho/perdido abrem modal).
 * @var array<int|string,string> $pipelines
 * @var array|null $pipeline
 * @var list<array> $etapas
 * @var array<int,list<array>> $porEtapa
 * @var array $motivosPerda
 */
use App\Services\Schema;

$limiteFechados = 50;
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Negócios</h1>
        <p class="text-muted-foreground">Arraste os cartões entre as etapas.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if (count($pipelines) > 1 && $pipeline): ?>
            <form method="get" action="<?= e(url('/negocios/kanban')) ?>">
                <label class="sr-only" for="kanban-pipeline">Pipeline</label>
                <?= select('pipeline', $pipelines, $pipeline['id'], ['id' => 'kanban-pipeline', 'attrs' => ['class' => 'select w-auto', 'onchange' => 'this.form.requestSubmit()']]) ?>
            </form>
        <?php endif; ?>
        <div class="button-group">
            <?= botao('Lista', ['variante' => 'outline', 'icone' => 'list-checks', 'href' => url('/negocios')]) ?>
            <?= botao('Kanban', ['variante' => 'primary', 'icone' => 'layout-dashboard', 'attrs' => ['aria-current' => 'page']]) ?>
        </div>
        <?= botao('Novo negócio', ['href' => url('/negocios/nova'), 'icone' => 'plus']) ?>
    </div>
</div>

<?php if ($etapas === []): ?>
    <?= card(['corpo_html' => vazio('Nenhuma etapa configurada', 'Crie as etapas do pipeline em Configurações.', ['icone' => 'settings', 'acao_html' => botao('Abrir configurações', ['href' => url('/configuracoes'), 'variante' => 'outline'])])]) ?>
<?php else: ?>
<div class="kanban" data-kanban>
    <?php foreach ($etapas as $e):
        $cards = $porEtapa[(int) $e['id']] ?? [];
        $ehGanho = $e['tipo'] === 'ganho';
        $total = array_sum(array_map(static fn (array $n) => (int) ($ehGanho ? ($n['valor_fechado'] ?? 0) : ($n['valor_estimado'] ?? 0)), $cards));
        $excedente = $e['tipo'] === 'aberta' ? 0 : max(0, count($cards) - $limiteFechados);
        $cards = $e['tipo'] === 'aberta' ? $cards : array_slice($cards, 0, $limiteFechados);
    ?>
        <section class="kanban-coluna" data-etapa-id="<?= (int) $e['id'] ?>" data-tipo="<?= e($e['tipo']) ?>" data-nome="<?= e($e['nome']) ?>"
                 style="--etapa: <?= e(preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $e['cor']) ? $e['cor'] : '#64748b') ?>" aria-label="<?= e($e['nome']) ?>">
            <header class="kanban-topo">
                <div class="flex items-center justify-between gap-2">
                    <?= pill_etapa($e['nome'], $e['cor']) ?>
                    <span class="text-muted-foreground text-xs"><?= count($cards) + $excedente ?></span>
                </div>
                <div class="text-muted-foreground mt-1 text-xs"><?= e(moeda($total)) ?><?= $e['tipo'] === 'aberta' ? ' · ' . (int) $e['probabilidade_padrao'] . '%' : '' ?></div>
            </header>
            <div class="kanban-lista" data-dropzone>
                <?php foreach ($cards as $n): ?>
                    <article class="kanban-card" draggable="true" data-negocio-id="<?= (int) $n['id'] ?>" data-valor-estimado="<?= (int) $n['valor_estimado'] ?>" data-titulo="<?= e($n['titulo']) ?>">
                        <a class="kanban-card-titulo" href="<?= e(url('/negocios/' . (int) $n['id'])) ?>" draggable="false"><?= e($n['titulo']) ?></a>
                        <?php if ($n['empresa_nome']): ?><div class="text-muted-foreground truncate text-xs"><?= e($n['empresa_nome']) ?></div><?php endif; ?>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-1 text-xs">
                            <span class="font-medium"><?= e(moeda((int) ($ehGanho ? $n['valor_fechado'] : $n['valor_estimado']))) ?></span>
                            <?php if ($n['temperatura']): ?><span class="text-temp-<?= e($n['temperatura']) ?>"><?= e(Schema::opcoes('temperatura')[$n['temperatura']]) ?></span><?php endif; ?>
                        </div>
                        <?php if ($e['tipo'] === 'aberta' && dias_entre($n['entrou_etapa_em'], hoje()) !== null): ?>
                            <?php $dias = dias_entre($n['entrou_etapa_em'], hoje()); ?>
                            <div class="text-muted-foreground mt-1 text-xs<?= $dias > 14 ? ' text-warning font-medium' : '' ?>"><?= (int) $dias ?> dia(s) na etapa<?= $n['status'] === 'pausado' ? ' · pausado' : '' ?></div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if ($excedente > 0): ?><p class="text-muted-foreground p-2 text-center text-xs">+ <?= $excedente ?> mais antigos (veja na lista)</p><?php endif; ?>
                <?php if ($cards === []): ?><p class="kanban-vazio text-muted-foreground p-3 text-center text-xs">Solte um cartão aqui</p><?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?= modal_etapa($motivosPerda) ?>
<?php endif; ?>
