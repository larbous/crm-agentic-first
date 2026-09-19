<?php
/**
 * Detalhe da empresa.
 * @var array $registro
 * @var list<array> $contatos
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
$voltar = '/empresas/' . $id;
$op = static fn (string $grupo, ?string $v) => $v !== null ? (Schema::opcoes($grupo)[$v] ?? $v) : '';
$rede = static fn (?string $v) => $v ? ['html' => e($v)] : '';
$site = $r['site'] ? ['html' => '<a class="underline underline-offset-4" target="_blank" rel="noopener" href="' . e(preg_match('#^https?://#i', $r['site']) ? $r['site'] : 'https://' . $r['site']) . '">' . e($r['site']) . '</a>'] : '';
$endereco = trim(implode(', ', array_filter([$r['logradouro'], $r['numero'], $r['complemento'], $r['bairro']])));
$cidadeUf = trim(implode('/', array_filter([$r['cidade'], $r['uf']])));

$colContatos = [];
foreach ($contatos as $c) {
    $colContatos[] = [
        'nome'   => ['html' => link_para('/contatos/' . (int) $c['id'], $c['nome_completo'], 'font-medium underline-offset-4 hover:underline')],
        'cargo'  => (string) $c['cargo'],
        'email'  => (string) $c['email'],
        'fone'   => (string) ($c['whatsapp'] ?: $c['telefone']),
        'status' => ['html' => badge_status('status_contato', $c['status'])],
    ];
}
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
    ['rotulo' => 'Timeline', 'html' => atividade_form(['empresa_id' => $id], $voltar) . timeline($atividades, ['voltar' => $voltar, 'contexto' => true])],
    ['rotulo' => 'Contatos', 'contagem' => count($contatos), 'html' =>
        '<div class="mb-2 flex justify-end">' . botao('Novo contato', ['href' => url('/contatos/nova?empresa_id=' . $id . '&voltar=' . rawurlencode($voltar)), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus']) . '</div>'
        . ($colContatos ? tabela(['nome' => 'Nome', 'cargo' => 'Cargo', 'email' => 'E-mail', 'fone' => 'Telefone', 'status' => 'Status'], $colContatos) : vazio('Sem contatos', 'Nenhum contato vinculado a esta empresa.', ['icone' => 'users']))],
    ['rotulo' => 'Negócios', 'contagem' => count($negocios), 'html' =>
        '<div class="mb-2 flex justify-end">' . botao('Novo negócio', ['href' => url('/negocios/nova?empresa_id=' . $id . '&voltar=' . rawurlencode($voltar)), 'variante' => 'outline', 'tamanho' => 'sm', 'icone' => 'plus']) . '</div>'
        . ($colNegocios ? tabela(['codigo' => 'Código', 'titulo' => 'Negócio', 'etapa' => 'Etapa', 'valor' => 'Valor', 'status' => 'Status'], $colNegocios) : vazio('Sem negócios', 'Nenhum negócio para esta empresa.', ['icone' => 'handshake']))],
    ['rotulo' => 'Tarefas', 'contagem' => count(array_filter($tarefas, 'tarefa_aberta')), 'html' => tarefas_mini($tarefas, 'empresa_id', $id, $voltar)],
    ['rotulo' => 'Anexos', 'contagem' => count($anexos), 'html' => anexos_painel($anexos, 'empresas', $id, $voltar)],
];
?>
<div class="page-cabecalho">
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="page-titulo"><?= e($r['nome_fantasia']) ?></h1>
            <?= badge_status('status_empresa', $r['status']) ?>
            <?php if ($r['classificacao']): ?><?= badge('Classe ' . $r['classificacao'], 'outline') ?><?php endif; ?>
        </div>
        <?php if ($r['razao_social'] && $r['razao_social'] !== $r['nome_fantasia']): ?><p class="text-muted-foreground"><?= e($r['razao_social']) ?></p><?php endif; ?>
        <div class="mt-2"><?= tags_painel($tags, $tagsOpcoes, 'empresas', $id, $voltar) ?></div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($r['status'] !== 'cliente'): ?>
            <form method="post" action="<?= e(url('/empresas/' . $id . '/converter')) ?>" data-confirmar="Converter esta empresa em cliente?">
                <?= csrf_field() ?><input type="hidden" name="voltar" value="<?= e($voltar) ?>">
                <?= botao('Converter em cliente', ['tipo' => 'submit', 'variante' => 'secondary', 'icone' => 'circle-check']) ?>
            </form>
        <?php endif; ?>
        <?= botao('Editar', ['href' => url('/empresas/' . $id . '/editar'), 'variante' => 'outline', 'icone' => 'pencil']) ?>
        <?= botao_arquivar(url('/empresas/' . $id . '/arquivar'), 'Arquivar esta empresa? Você poderá desfazer pela Auditoria.') ?>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-3">
    <div class="grid content-start gap-4">
        <?= card(['tamanho' => 'sm', 'titulo' => 'Dados', 'corpo_html' => ficha([
            'CNPJ' => cnpj_formatado($r['cnpj']), 'Porte' => $op('porte', $r['porte']), 'Segmento' => (string) $r['segmento'],
            'Origem' => (string) $r['origem_nome'], 'Indicado por' => (string) $r['indicado_por'],
            'Ticket potencial' => $r['ticket_potencial'] !== null ? moeda((int) $r['ticket_potencial']) : '',
            'LTV (negócios ganhos)' => moeda((int) $r['ltv']), 'Cliente desde' => data_br($r['cliente_desde']),
            'Fundação' => data_br($r['data_fundacao']), 'Faturamento' => (string) $r['faixa_faturamento'], 'Funcionários' => (string) $r['faixa_funcionarios'],
        ])]) ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Contato e endereço', 'corpo_html' => ficha([
            'E-mail' => (string) $r['email_geral'], 'Telefone' => (string) $r['telefone'], 'WhatsApp' => (string) $r['whatsapp'], 'Site' => $site,
            'Instagram' => $rede($r['instagram']), 'Facebook' => $rede($r['facebook']), 'LinkedIn' => $rede($r['linkedin']),
            'TikTok' => $rede($r['tiktok']), 'YouTube' => $rede($r['youtube']),
            'Endereço' => $endereco, 'CEP' => cep_formatado($r['cep']), 'Cidade' => $cidadeUf,
        ])]) ?>
        <?php $digital = ficha([
            'Domínio' => (string) $r['dominio'], 'Vencimento do domínio' => data_br($r['vencimento_dominio']), 'Registrador' => (string) $r['registrador_dominio'],
            'Hospedagem' => (string) $r['hospedagem_atual'], 'Plataforma do site' => (string) $r['plataforma_site'],
            'E-commerce' => $r['tem_ecommerce'] ? ($r['plataforma_ecommerce'] ?: 'Sim') : '', 'ERP' => (string) $r['erp'],
            'E-mail marketing' => (string) $r['ferramenta_email_marketing'], 'Tráfego pago' => $r['usa_trafego_pago'] ? 'Sim' : '',
            'Observações' => (string) $r['observacoes_digitais'],
        ]); ?>
        <?= card(['tamanho' => 'sm', 'titulo' => 'Presença digital', 'corpo_html' => $digital]) ?>
        <?php if ($r['notas']): ?>
            <?= card(['tamanho' => 'sm', 'titulo' => 'Notas', 'corpo_html' => '<p class="text-sm whitespace-pre-line">' . e($r['notas']) . '</p>']) ?>
        <?php endif; ?>
    </div>
    <div class="lg:col-span-2">
        <?= card(['corpo_html' => abas('abas-empresa', $abas, 0, ['linha' => true])]) ?>
    </div>
</div>
