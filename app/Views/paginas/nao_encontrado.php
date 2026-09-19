<div class="page-cabecalho"><h1 class="page-titulo">Não encontrado</h1></div>
<?= card(['corpo_html' => vazio('Registro não encontrado', 'Ele pode ter sido arquivado ou o endereço está incorreto.', [
    'icone' => 'search', 'acao_html' => botao('Ir para o início', ['href' => url('/'), 'variante' => 'outline']),
])]) ?>
