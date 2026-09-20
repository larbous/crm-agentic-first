<?php
/**
 * Detalhe do contato.
 * @var array $registro
 * @var list<array> $negocios
 * @var list<array> $tarefas
 * @var list<array> $atividades
 * @var list<array> $anexos
 * @var list<array> $tags
 * @var array $tagsOpcoes
 */
use App\Services\Schema;

$r = $registro;
$id = (int) $r['id'];
$voltar = '/contatos/' . $id;
$op = static fn (string $grupo, ?string $v) => $v !== null ? (Schema::opcoes($grupo)[$v] ?? $v) : '';
$empresa = $r['empresa_id'] ? ['html' => link_para('/empresas/' . (int) $r['empresa_id'], (string) $r['empresa_nome'])] : '';

$colNegocios = [];
foreach ($negocios as $n) {
    $colNegocios[] = [
        'codigo' => (string) $n['codigo'],
        'titulo' => ['html' => link_para('/negocios/' . (int) $n['id'], $n['titulo'], 'font-medium underline-offset-4 hover:underline')],
        'etapa'  => ['html' => pill_etapa((string) $n['etapa_nome'], $n['etapa_cor'])],
        'valor'  => $n['valor_estimado'] !== null ? moeda((int) $n['valor_estimado']) : '',
        'status' => ['html' => badge_status('status_negocio', $n['status'])],
    ];
}
$abas = [
    ['rotulo' => 'Timeline', 'html' => atividade_form(['contato_id' => $id], $voltar) . timeline($atividades, ['voltar' => $voltar, 'contexto' => true])],
    ['rotulo' => 'Negócios', 'contagem' => count($negocios), 'html' => $colNegocios
        ? tabela(['codigo' => 'Código', 'titulo' => 'Negócio', 'etapa' => 'Etapa', 'valor' => 'Valor', 'status' => 'Status'], $colNegocios)
        : vazio('Sem negócios', 'Este contato não está ligado a nenhum negócio.', ['icone' => 'handshake'])],
    ['rotulo' => 'Tarefas', 'contagem' => count(array_filter($tarefas, 'tarefa_aberta')), 'html' => tarefas_mini($tarefas, 'contato_id', $id, $voltar)],
    ['rotulo' => 'Anexos', 'contagem' => count($anexos), 'html' => anexos_painel($anexos, 'contatos', $id, $voltar)],
];
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e($r['nome_completo']) ?></h1>
            <?= badge_status('status_contato', $r['status']) ?>
            <?php if ($r['papel_decisao']): ?><?= badge($op('papel_decisao', $r['papel_decisao']), 'outline') ?><?php endif; ?>
        </div>
        <p class="text-muted-foreground"><?= e(trim(($r['cargo'] ?? '') . ($r['cargo'] && $r['empresa_nome'] ? ' · ' : '') . ($r['empresa_nome'] ?? ''))) ?></p>
        <div class="mt-2"><?= tags_painel($tags, $tagsOpcoes, 'contatos', $id, $voltar) ?></div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= agentes_botoes('contatos', $id) ?>
        <?= botao('Novo negócio', ['href' => url('/negocios/nova?contato_principal_id=' . $id . ($r['empresa_id'] ? '&empresa_id=' . (int) $r['empresa_id'] : '') . '&voltar=' . rawurlencode($voltar)), 'variante' => 'secondary', 'icone' => 'handshake']) ?>
        <?= botao('Editar', ['href' => url('/contatos/' . $id . '/editar'), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?= botao_arquivar(url('/contatos/' . $id . '/arquivar'), 'Arquivar este contato? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-4">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Canais', 'corpo_html' => ficha([
            'Empresa' => $empresa, 'E-mail' => (string) $r['email'], 'E-mail secundário' => (string) $r['email_secundario'],
            'Telefone' => (string) $r['telefone'], 'WhatsApp' => (string) $r['whatsapp'], 'LinkedIn' => (string) $r['linkedin'], 'Instagram' => (string) $r['instagram'],
            'Canal preferido' => $op('canal', $r['canal_preferido']), 'Melhor horário' => (string) $r['melhor_horario'],
        ])]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Relacionamento', 'corpo_html' => ficha([
            'Último contato' => datahora_br($r['ultimo_contato_em']), 'Próximo contato' => data_br($r['proximo_contato_em']),
            'Nível (1–5)' => (string) $r['nivel_relacionamento'], 'Origem' => (string) $r['origem_nome'], 'Departamento' => (string) $r['departamento'],
            'Nível hierárquico' => (string) $r['nivel_hierarquico'], 'Interesses' => (string) $r['interesses'],
        ])]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Pessoal e LGPD', 'corpo_html' => ficha([
            'CPF' => $r['cpf'] ? substr($r['cpf'], 0, 3) . '.***.***-' . substr($r['cpf'], -2) : '', 'Nascimento' => data_br($r['data_nascimento']),
            'Marketing' => $r['opt_in_marketing'] ? 'Aceita' : 'Não aceita', 'Base legal' => $op('base_legal', $r['base_legal']),
            'Consentimento' => data_br($r['data_consentimento']), 'Origem do consentimento' => (string) $r['origem_consentimento'],
        ])]) ?>
        <?= card_campos_extras('contatos', $r) ?>
        <?php if ($r['notas']): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Notas', 'corpo_html' => '<p class="text-sm whitespace-pre-line">' . e($r['notas']) . '</p>']) ?>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2">
        <?= card(['corpo_html' => abas('abas-contato', $abas, 0, ['linha' => true])]) ?>
    </div>
</div>
