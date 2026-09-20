<?php
/**
 * Editor de itens da proposta com totais em tempo real (propostas.js).
 * @var list<array> $itens valores do formulário (texto pt-BR)
 * @var list<array> $servicos catálogo ativo
 * @var string|null $erro
 */
use App\Services\Schema;

$linha = static function (array|null $i, string $idx): string {
    $i ??= [];
    $nome = static fn (string $campo): string => 'itens[' . $idx . '][' . $campo . ']';
    return '<tr data-item>'
        . '<td class="min-w-56"><input type="hidden" name="' . $nome('servico_id') . '" value="' . e($i['servico_id'] ?? '') . '">'
        . '<input class="input h-8" type="text" name="' . $nome('descricao') . '" value="' . e($i['descricao'] ?? '') . '" maxlength="300" aria-label="Descrição" placeholder="Descrição do item"></td>'
        . '<td class="w-24"><input class="input h-8 text-right" type="text" inputmode="decimal" data-calc="quantidade" name="' . $nome('quantidade') . '" value="' . e($i['quantidade'] ?? '1') . '" aria-label="Quantidade"></td>'
        . '<td class="w-24"><input class="input h-8" type="text" name="' . $nome('unidade') . '" value="' . e($i['unidade'] ?? '') . '" maxlength="30" aria-label="Unidade"></td>'
        . '<td class="w-32"><input class="input h-8 text-right" type="text" inputmode="decimal" data-dinheiro data-calc="unitario" name="' . $nome('valor_unitario') . '" value="' . e($i['valor_unitario'] ?? '') . '" aria-label="Valor unitário" placeholder="0,00"></td>'
        . '<td class="w-28"><input class="input h-8 text-right" type="text" inputmode="decimal" data-dinheiro data-calc="desconto" name="' . $nome('desconto') . '" value="' . e($i['desconto'] ?? '') . '" aria-label="Desconto do item" placeholder="0,00"></td>'
        . '<td class="w-16 text-center"><input type="hidden" name="' . $nome('recorrente') . '" value="0"><input class="input" type="checkbox" name="' . $nome('recorrente') . '" value="1"' . (!empty($i['recorrente']) ? ' checked' : '') . ' aria-label="Recorrente"></td>'
        . '<td class="w-32 text-right tabular-nums" data-item-total>R$ 0,00</td>'
        . '<td class="w-10">' . botao('Remover item', ['tipo' => 'button', 'variante' => 'ghost', 'tamanho' => 'icon-sm', 'icone' => 'x', 'attrs' => ['data-remover-item' => true]]) . '</td>'
        . '</tr>';
};

$catalogo = array_map(static fn (array $s): array => [
    'id' => (int) $s['id'], 'nome' => $s['nome'], 'descricao' => $s['descricao'] ?: $s['nome'],
    'unidade' => Schema::opcoes('unidade_servico')[$s['unidade']] ?? $s['unidade'],
    'preco' => $s['preco_base'] !== null ? centavos_para_texto((int) $s['preco_base']) : '', 'recorrente' => (int) $s['recorrente'],
], $servicos);
$opcoes = array_column($servicos, 'nome', 'id');
?>
<div data-editor-itens>
    <?php if ($erro): ?><?= alerta('Revise os itens', (string) $erro, 'destructive') ?><div class="h-3"></div><?php endif; ?>

    <script type="application/json" data-servicos><?= json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <div class="mb-3 flex flex-wrap items-end gap-2">
        <?php if ($opcoes !== []): ?>
            <div class="min-w-56 flex-1">
                <label class="sr-only" for="item-servico">Serviço do catálogo</label>
                <?= select('servico_catalogo', $opcoes, null, ['id' => 'item-servico', 'placeholder' => 'Adicionar serviço do catálogo…', 'attrs' => ['class' => 'select w-full', 'data-servico-select' => true]]) ?>
            </div>
        <?php endif; ?>
        <?= botao('Item avulso', ['variante' => 'outline', 'icone' => 'plus', 'attrs' => ['data-adicionar-avulso' => true]]) ?>
    </div>
    <?php if ($opcoes === []): ?>
        <p class="text-muted-foreground mb-3 text-sm">O catálogo de serviços está vazio. <a class="underline underline-offset-4" href="<?= e(url('/servicos/nova')) ?>">Cadastre serviços</a> para preencher itens com preço e descrição automáticos, ou use itens avulsos.</p>
    <?php endif; ?>

    <div class="table-container rounded-lg border">
        <table class="table">
            <thead><tr>
                <th>Descrição</th><th class="text-right">Qtd.</th><th>Unid.</th><th class="text-right">Valor unit. (R$)</th>
                <th class="text-right">Desc. (R$)</th><th class="text-center">Rec.</th><th class="text-right">Total</th><th></th>
            </tr></thead>
            <tbody data-itens-corpo>
                <?php foreach (array_values($itens) as $n => $i): ?><?= $linha($i, (string) $n) ?><?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted-foreground p-6 text-center text-sm" data-itens-vazio<?= $itens !== [] ? ' hidden' : '' ?>>Nenhum item ainda. Adicione serviços do catálogo ou itens avulsos.</p>
    </div>
    <template data-item-modelo><?= $linha(null, '__i__') ?></template>

    <dl class="totais-proposta" aria-live="polite">
        <div><dt>Subtotal</dt><dd data-total="subtotal">R$ 0,00</dd></div>
        <div><dt>Desconto</dt><dd data-total="desconto">R$ 0,00</dd></div>
        <div class="total"><dt>Total</dt><dd data-total="total">R$ 0,00</dd></div>
        <div class="text-muted-foreground"><dt>Parte recorrente</dt><dd data-total="recorrente">R$ 0,00</dd></div>
    </dl>
    <p class="text-muted-foreground mt-2 text-xs">O desconto geral (valor ou percentual) fica na aba “Condições e desconto”. O total é recalculado pelo servidor ao salvar.</p>
</div>
