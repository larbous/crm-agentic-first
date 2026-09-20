<?php
/**
 * Lista de formulários de captação.
 * @var list<array> $formularios linhas de FormularioRepository::todos()
 */
$regras = ['tarefa' => 'Criar tarefa', 'mesclar' => 'Mesclar', 'criar' => 'Criar sempre'];

$linhas = [];
foreach ($formularios as $f) {
    $id = (int) $f['id'];
    $ativo = (int) $f['ativo'] === 1;
    $publico = url_publica('/f/' . $f['chave']);
    $linhas[] = [
        'formulario' => ['html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url("/formularios/{$id}/editar")) . '">' . e($f['nome']) . '</a>'
            . '<div class="text-muted-foreground text-xs">' . (int) $f['campos'] . ' campo(s)' . ((int) $f['criar_negocio'] === 1 ? ' · cria negócio' : '')
            . ($f['squad_disparado'] !== null ? ' · squad #' . e($f['squad_disparado']) : '') . '</div>'],
        'duplicado' => $regras[$f['regra_duplicado']] ?? $f['regra_duplicado'],
        'submissoes' => ['html' => (int) $f['submissoes'] > 0
            ? '<a class="underline-offset-4 hover:underline" href="' . e(url('/formularios/submissoes?formulario=' . $id)) . '">' . (int) $f['submissoes'] . '</a>' : '0'],
        'situacao' => ['html' => $ativo ? badge('Ativo', 'success') : badge('Inativo', 'secondary')],
        'acoes' => ['html' => '<div class="flex items-center justify-end gap-1">'
            . botao('Link público', ['variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'copy', 'attrs' => ['data-copiar' => $publico]])
            . botao('Abrir', ['href' => $publico, 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'external-link', 'attrs' => ['target' => '_blank', 'rel' => 'noopener']])
            . botao('Editar', ['href' => url("/formularios/{$id}/editar"), 'variante' => 'ghost', 'tamanho' => 'xs', 'icone' => 'pencil'])
            . '</div>'],
    ];
}
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo">Formulários</h1>
        <p class="text-muted-foreground">Formulários de captação para colocar no site da Lárbous ou de clientes. Cada envio vira empresa, contato (e negócio, se quiser) com UTMs, evita duplicados e pode disparar um squad.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Submissões', ['href' => url('/formularios/submissoes'), 'variante' => 'outline', 'icone' => 'inbox']) ?>
        <?= botao('Novo formulário', ['href' => url('/formularios/nova'), 'icone' => 'plus']) ?>
    </div>
</div>

<?= data_table(
    ['formulario' => ['rotulo' => 'Formulário'], 'duplicado' => ['rotulo' => 'Se já existir'], 'submissoes' => ['rotulo' => 'Envios'],
     'situacao' => ['rotulo' => 'Situação'], 'acoes' => ['rotulo' => '']],
    $linhas,
    ['id' => 'tabela-formularios', 'vazio_html' => vazio('Nenhum formulário', 'Crie o primeiro para captar contatos direto do site.', ['icone' => 'clipboard-list',
        'acao_html' => botao('Novo formulário', ['href' => url('/formularios/nova'), 'icone' => 'plus'])])],
) ?>
