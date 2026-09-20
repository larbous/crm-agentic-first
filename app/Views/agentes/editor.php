<?php
/**
 * Editor de agente: JSON com validação, teste em simulação (só depois de salvo), versões e referência do formato.
 * @var array|null $agente linha de AgenteRepository (null = novo)
 * @var string $json definição em JSON (o que está no editor)
 * @var array<string,string> $erros erros do último salvamento
 * @var list<array> $versoes
 */
use App\Services\AI\AcaoAgente;
use App\Services\AI\AgenteDefinicao;

$id = $agente !== null ? (int) $agente['id'] : null;
$acao = $id !== null ? url("/agentes/{$id}") : url('/agentes');
$def = $agente['def'] ?? [];
$entradaAgente = (string) ($def['entrada'] ?? '');

$listaErros = '';
if ($erros !== []) {
    $listaErros = '<div class="alert" role="alert" data-variant="destructive">' . icone('circle-alert') . '<h2>A definição não foi salva</h2><section><ul class="list-inside list-disc">';
    foreach ($erros as $mensagem) {
        $listaErros .= '<li>' . e($mensagem) . '</li>';
    }
    $listaErros .= '</ul></section></div>';
}

$editor = '<form method="post" action="' . e($acao) . '" class="grid gap-3" data-agente-editor>' . csrf_field()
    . '<label class="sr-only" for="agente-json">Definição do agente (JSON)</label>'
    . '<textarea id="agente-json" name="definicao" class="textarea font-mono text-xs leading-relaxed" rows="30" spellcheck="false" data-agente-json'
    . ' data-validar-url="' . e(url('/api/agentes/validar')) . '">' . e($json) . '</textarea>'
    . '<div class="text-sm" data-agente-validacao aria-live="polite"></div>'
    . '<div class="flex flex-wrap items-center gap-2">'
    . botao('Salvar', ['tipo' => 'submit', 'icone' => 'check'])
    . botao('Validar', ['variante' => 'outline', 'icone' => 'shield-check', 'attrs' => ['data-agente-validar' => true]])
    . ($id !== null ? botao('Exportar', ['href' => url("/agentes/{$id}/exportar"), 'variante' => 'ghost', 'icone' => 'download']) : '')
    . botao('Voltar', ['href' => url('/agentes'), 'variante' => 'ghost'])
    . '</div></form>';

// ---- Testar (simulação)
$testar = '';
if ($id !== null) {
    $precisaRegistro = $entradaAgente !== 'nenhuma';
    $corpo = '<div class="grid gap-3" data-agente-teste data-executar-url="' . e(url("/api/agentes/{$id}/executar")) . '" data-registros-url="' . e(url("/api/agentes/{$id}/registros")) . '"'
        . ' data-precisa-registro="' . ($precisaRegistro ? '1' : '0') . '">';
    if ($precisaRegistro) {
        $corpo .= '<div class="relative"><label for="teste-busca">Registro</label>'
            . '<input id="teste-busca" type="search" class="input" placeholder="Buscar ' . e($entradaAgente) . '…" autocomplete="off" data-teste-busca>'
            . '<div class="busca-resultados" role="listbox" hidden data-teste-resultados></div>'
            . '<p class="text-muted-foreground mt-1 text-xs" data-teste-escolhido>Nenhum registro escolhido.</p></div>';
    }
    $corpo .= campo(['nome' => 'teste_entrada', 'id' => 'teste-entrada', 'rotulo' => 'Texto de apoio (opcional)', 'tipo' => 'textarea', 'linhas' => 3, 'attrs' => ['maxlength' => 2000, 'data-teste-entrada' => true]])
        . '<div>' . botao('Simular', ['icone' => 'flask-conical', 'attrs' => ['data-teste-enviar' => true]]) . '</div>'
        . '<p class="text-muted-foreground text-xs">A simulação chama a IA (consome tokens), mostra o que o agente faria e não grava nada. Salve a definição antes de testar alterações.</p>'
        . '<div data-teste-saida aria-live="polite"></div></div>';
    $testar = card(['titulo' => 'Testar', 'descricao' => 'Roda o agente em modo simulação sobre um registro real.', 'corpo_html' => $corpo]);
}

// ---- Versões
$versoesHtml = '';
if ($id !== null) {
    $itens = '';
    foreach ($versoes as $v) {
        $atual = (int) $v['versao'] === (int) $agente['versao'];
        $itens .= '<li class="flex items-center gap-2 border-b py-2 last:border-b-0"><div class="min-w-0 flex-1"><span class="font-medium">v' . (int) $v['versao'] . '</span>'
            . ($atual ? ' ' . badge('atual', 'success') : '') . '<div class="text-muted-foreground text-xs">' . e(datahora_br($v['criado_em'])) . ' · ' . e((string) ($v['definicao']['modelo'] ?? '')) . '</div></div>';
        if (!$atual) {
            $itens .= '<form method="post" action="' . e(url("/agentes/{$id}/versoes/" . (int) $v['versao'] . '/restaurar')) . '" data-confirmar="Restaurar a versão ' . (int) $v['versao'] . '? Ela vira uma nova versão.">'
                . csrf_field() . botao('Restaurar', ['tipo' => 'submit', 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'rotate-ccw']) . '</form>';
        }
        $itens .= '</li>';
    }
    $versoesHtml = card(['titulo' => 'Versões', 'descricao' => 'Cada salvamento com mudanças cria uma versão nova.', 'corpo_html' => '<ul>' . $itens . '</ul>']);
}

// ---- Referência
$ref = '<dl class="grid gap-2 text-sm">'
    . '<div><dt class="font-medium">entrada</dt><dd class="text-muted-foreground">' . e(implode(', ', AgenteDefinicao::ENTRADAS)) . '</dd></div>'
    . '<div><dt class="font-medium">contexto</dt><dd class="text-muted-foreground">Únicos campos do registro enviados à IA (nomes de coluna da entidade de entrada; campos extras como extra.chave).</dd></div>'
    . '<div><dt class="font-medium">contexto_relacionado</dt><dd class="text-muted-foreground">' . e(implode(', ', array_keys(AgenteDefinicao::RELACIONADOS))) . ' (quantidade máxima por chave).</dd></div>'
    . '<div><dt class="font-medium">acoes_permitidas</dt><dd class="text-muted-foreground">' . e(implode(', ', AcaoAgente::ACOES)) . '. O texto longo do agente vira nota no registro.</dd></div>'
    . '<div><dt class="font-medium">campos_gravaveis</dt><dd class="text-muted-foreground">Campos que atualizar/criar podem gravar, além de: ' . e(implode(', ', AcaoAgente::PSEUDOS)) . ' (a IA informa o nome, o servidor acha o registro).</dd></div>'
    . '<div><dt class="font-medium">aprovacao</dt><dd class="text-muted-foreground"><b>sempre</b>: nada é gravado sem sua aprovação, nem a nota de texto; <b>escritas</b>: as ações aguardam aprovação e o texto vira nota direto; <b>nunca</b>: tudo é gravado na hora.</dd></div>'
    . '<div><dt class="font-medium">gatilho</dt><dd class="text-muted-foreground">manual, evento (roda no worker quando o evento acontece) ou agendado (cron de 5 campos; exige entrada "nenhuma"). Um agente não é re-disparado por eventos causados por ele mesmo.</dd></div>'
    . '</dl>';
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo"><?= $agente !== null ? e($agente['nome']) : 'Novo agente' ?></h1>
        <p class="text-muted-foreground">
            <?php if ($agente !== null): ?>@<?= e($agente['slug']) ?> · versão <?= (int) $agente['versao'] ?> · <?= (int) $agente['ativo'] === 1 ? 'ativo' : 'inativo' ?>
            <?php else: ?>Escreva a definição em JSON (formato .agent.json). Ela é validada ao salvar.<?php endif; ?>
        </p>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-3 lg:col-span-2">
        <?= $listaErros ?>
        <?= card(['corpo_html' => $editor]) ?>
    </div>
    <div class="grid content-start gap-4">
        <?= $testar ?>
        <?= $versoesHtml ?>
        <?= card(['titulo' => 'Referência do formato', 'tamanho' => 'sm', 'corpo_html' => $ref]) ?>
    </div>
</div>
