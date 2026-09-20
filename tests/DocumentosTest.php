<?php

declare(strict_types=1);

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Html;
use App\Services\Variaveis;

const CPF_VALIDO = '529.982.247-25';

function itensExemplo(): array
{
    return [
        ['descricao' => 'Site institucional', 'quantidade' => '1', 'unidade' => 'projeto', 'valor_unitario' => '6.500,00', 'recorrente' => 0],
        ['descricao' => 'Hospedagem e manutenção', 'quantidade' => '12', 'unidade' => 'mês', 'valor_unitario' => '120,00', 'recorrente' => 1],
    ];
}

/** Negócio com empresa e contato, pronto para receber proposta. */
function negocioComContato(ActionExecutor $x): array
{
    $emp = novaEmpresa($x, 'Padaria Central', ['razao_social' => 'Padaria Central Ltda', 'cnpj' => '11.222.333/0001-81', 'cidade' => 'São Paulo', 'uf' => 'SP']);
    $cid = (int) $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $emp])->id;
    $neg = novoNegocio($x, ['empresa_id' => $emp, 'contato_principal_id' => $cid]);
    return [$emp, $cid, $neg];
}

function novaProposta(ActionExecutor $x, int $negocio, array $extra = []): int
{
    $r = $x->criar('propostas', $extra + ['titulo' => 'Proposta — Site', 'negocio_id' => $negocio, 'itens' => itensExemplo()]);
    verdadeiro($r->ok, 'criar proposta: ' . json_encode($r->erros));
    return (int) $r->id;
}

/** Proposta enviada e aceita, pronta para virar contrato. */
function propostaAceita(ActionExecutor $x, int $negocio): int
{
    $id = novaProposta($x, $negocio);
    verdadeiro($x->enviarProposta($id)->ok);
    $r = $x->responderProposta($id, 'aceita', ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO, 'ip' => '203.0.113.7']);
    verdadeiro($r->ok, json_encode($r->erros));
    return $id;
}

// ---------------------------------------------------------------------------------------------
// Utilidades: extenso e sanitização
// ---------------------------------------------------------------------------------------------

teste('valor_por_extenso() e data_por_extenso() escrevem em português', function () {
    igual('zero reais', valor_por_extenso(0));
    igual('um real', valor_por_extenso(100));
    igual('um centavo', valor_por_extenso(1));
    igual('mil e quinhentos reais e vinte centavos', valor_por_extenso(150020));
    igual('oitocentos mil reais', valor_por_extenso(80000000));
    igual('um milhão de reais', valor_por_extenso(100000000));
    igual('cento e um reais', valor_por_extenso(10100));
    igual('dois mil e quinhentos reais', valor_por_extenso(250000));
    igual('mil duzentos e trinta e quatro reais e cinquenta e seis centavos', valor_por_extenso(123456));
    igual('', valor_por_extenso(null));
    igual('20 de setembro de 2026', data_por_extenso('2026-09-20'));
    igual('', data_por_extenso('2026-02-31'));
    igual('7,50%', percentual_br(750));
});

teste('Html::sanitizar remove scripts, eventos, iframes e links perigosos e mantém a estrutura', function () {
    $sujo = '<h2 onclick="x()">Título</h2><p style="color:red">Olá <strong>mundo</strong><script>alert(1)</script></p>'
        . '<a href="javascript:alert(1)">ruim</a> <a href="https://exemplo.com" onmouseover="x()">bom</a>'
        . '<iframe src="https://evil"></iframe><img src=x onerror=alert(1)><table><tr><td colspan="2" onclick="y()">c</td></tr></table>'
        . '<style>body{display:none}</style><ul><li>item</li></ul><!-- comentário --><form action="/x"><input name="a"></form>';
    $limpo = Html::sanitizar($sujo);
    foreach (['<script', 'onclick', 'onerror', 'onmouseover', 'javascript:', '<iframe', '<style', '<form', '<input', '<img', 'style=', 'comentário'] as $proibido) {
        verdadeiro(!str_contains(strtolower($limpo), strtolower($proibido)), "sobrou {$proibido}: {$limpo}");
    }
    foreach (['<h2>Título</h2>', '<strong>mundo</strong>', 'href="https://exemplo.com"', 'rel="noopener noreferrer"', 'colspan="2"', '<li>item</li>'] as $esperado) {
        contem($esperado, $limpo);
    }
    igual('', Html::sanitizar('   '));
    igual('<p>a &lt;b&gt;</p>', Html::textoParaHtml('a <b>'));
    igual("<p>um<br>\ndois</p><p>três</p>", Html::textoParaHtml("um\ndois\n\ntrês"));
});

// ---------------------------------------------------------------------------------------------
// Motor de variáveis
// ---------------------------------------------------------------------------------------------

teste('Variaveis: formata moeda, data e opções em pt-BR, escapa HTML e devolve as não resolvidas', function () {
    bancoComSeed();
    $ctx = Variaveis::exemplo();
    $ctx['empresa']['nome_fantasia'] = 'Padaria <b>&</b> Cia';
    $sobra = [];
    $html = Variaveis::renderizar('{empresa.nome_fantasia} | {contrato.valor_total} | {contrato.valor_total_extenso} | {contrato.data_inicio} | {contrato.recorrencia} | {inexistente.campo} | {empresa.campo_falso}', $ctx, true, $sobra);
    contem('Padaria &lt;b&gt;&amp;&lt;/b&gt; Cia', $html);
    contem('R$ 7.940,00', $html);
    contem('sete mil novecentos e quarenta reais', $html);
    contem(data_br(hoje()), $html);
    contem('Mensal', $html);
    igual(['{inexistente.campo}', '{empresa.campo_falso}'], $sobra);
    contem('{inexistente.campo}', $html, 'variável desconhecida fica visível');

    $texto = Variaveis::renderizar('{empresa.nome_fantasia}', $ctx, false);
    igual('Padaria <b>&</b> Cia', $texto, 'modo texto não escapa');
});

teste('Variaveis: hoje, agência (configurações), cálculos, itens e registros ausentes', function () {
    bancoComSeed();
    $cfg = new ConfiguracaoRepository();
    $cfg->definir('empresa.nome', 'Lárbous Ltda');
    $cfg->definir('empresa.cnpj', '12.345.678/0001-95');
    $ctx = Variaveis::exemplo();
    $sobra = [];
    igual(data_br(hoje()), Variaveis::renderizar('{hoje}', [], true));
    igual(data_por_extenso(hoje()), Variaveis::renderizar('{hoje.extenso}', [], true));
    igual('Lárbous Ltda — 12.345.678/0001-95', Variaveis::renderizar('{larbous.nome} — {larbous.cnpj}', [], true, $sobra));
    igual('', Variaveis::renderizar('{larbous.email}', [], true), 'agência sem valor vira vazio');
    igual('Rua das Flores, 123, Centro, São Paulo/SP, CEP 01001-000', Variaveis::renderizar('{empresa.endereco_completo}', $ctx, true));
    igual('Ana Souza', Variaveis::renderizar('{contato.nome_completo}', $ctx, true));
    igual('PROP-2026-0001 v1', Variaveis::renderizar('{proposta.numero_versao}', $ctx, true));
    igual('Padaria Exemplo Ltda', Variaveis::renderizar('{empresa.razao}', $ctx, true));
    igual('', Variaveis::renderizar('{empresa.nome}', ['contato' => []], true), 'registro ausente vira vazio');

    $tabela = Variaveis::renderizar('{proposta.itens}', $ctx, true);
    contem('<table>', $tabela);
    contem('Site institucional', $tabela);
    contem('R$ 6.500,00', $tabela);
    contem('- Site institucional', Variaveis::renderizar('{proposta.itens}', $ctx, false));

    $catalogo = Variaveis::catalogo();
    verdadeiro(isset($catalogo['Empresa']['{empresa.nome_fantasia}'], $catalogo['Data']['{hoje}'], $catalogo['Agência (Lárbous)']['{larbous.cnpj}']));
    verdadeiro(!isset($catalogo['Proposta']['{proposta.token_publico}']), 'token não é variável');
    verdadeiro(isset($catalogo['Contrato']['{contrato.vigencia}']));
});

// ---------------------------------------------------------------------------------------------
// Propostas
// ---------------------------------------------------------------------------------------------

teste('Proposta: cria com itens, calcula totais, número, token e vínculos herdados do negócio', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    [$emp, $cid, $neg] = negocioComContato($x);

    $id = novaProposta($x, $neg);
    $p = Repositorios::propostas()->encontrar($id);
    igual('PROP-' . date('Y') . '-0001', $p['numero']);
    igual(1, (int) $p['versao']);
    igual('rascunho', $p['status']);
    igual($emp, (int) $p['empresa_id']);
    igual($cid, (int) $p['contato_id']);
    igual(794000, (int) $p['subtotal']);
    igual(794000, (int) $p['total']);
    igual(144000, (int) $p['total_recorrente']);
    igual(40, strlen($p['token_publico']));
    verdadeiro(ctype_xdigit($p['token_publico']));
    igual(hoje(), $p['data_emissao']);
    igual(date('Y-m-d', strtotime('+15 days')), $p['validade'], 'validade padrão de 15 dias');

    $itens = (new ItemPropostaRepository())->porProposta($id);
    igual(2, count($itens));
    igual(650000, (int) $itens[0]['total']);
    igual(144000, (int) $itens[1]['total']);
    igual(12.0, (float) $itens[1]['quantidade']);
    igual(0, (int) $itens[0]['ordem']);
    igual(1, (int) $itens[1]['recorrente']);

    $log = $pdo->query("SELECT depois FROM log_auditoria WHERE entidade = 'propostas' AND acao = 'criar'")->fetchColumn();
    igual(2, count(json_decode($log, true)['_itens']), 'itens na auditoria');

    $id2 = novaProposta($x, $neg);
    igual('PROP-' . date('Y') . '-0002', Repositorios::propostas()->encontrar($id2)['numero']);
    verdadeiro(Repositorios::propostas()->encontrar($id2)['token_publico'] !== $p['token_publico']);
});

teste('Proposta: desconto em percentual e em valor, quantidade fracionada e validações', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);

    $pct = novaProposta($x, $neg, ['desconto_tipo' => 'percentual', 'desconto_valor' => '10']);
    igual(714600, (int) Repositorios::propostas()->encontrar($pct)['total'], '10% de 7.940,00');

    $pct2 = novaProposta($x, $neg, ['desconto_tipo' => 'percentual', 'desconto_valor' => '7,5']);
    igual(794000 - 59550, (int) Repositorios::propostas()->encontrar($pct2)['total'], '7,5%');

    $val = novaProposta($x, $neg, ['desconto_tipo' => 'valor', 'desconto_valor' => '500,00']);
    igual(744000, (int) Repositorios::propostas()->encontrar($val)['total']);

    $r = $x->criar('propostas', ['titulo' => 'X', 'itens' => itensExemplo(), 'desconto_tipo' => 'valor', 'desconto_valor' => '9.000,00']);
    verdadeiro(!$r->ok);
    contem('maior que o subtotal', $r->erros['desconto_valor']);
    $r = $x->criar('propostas', ['titulo' => 'X', 'itens' => itensExemplo(), 'desconto_tipo' => 'percentual', 'desconto_valor' => '150']);
    verdadeiro(!$r->ok);
    contem('100%', $r->erros['desconto_valor']);

    $r = $x->criar('propostas', ['titulo' => 'Horas', 'itens' => [['descricao' => 'Consultoria', 'quantidade' => '2,5', 'valor_unitario' => '200,00', 'unidade' => 'hora']]]);
    verdadeiro($r->ok);
    igual(50000, (int) Repositorios::propostas()->encontrar((int) $r->id)['total'], '2,5 × 200,00');

    foreach ([
        [['descricao' => '', 'valor_unitario' => '10,00', 'quantidade' => '1'], 'obrigatório'],
        [['descricao' => 'A', 'quantidade' => '0', 'valor_unitario' => '10,00'], 'maior que zero'],
        [['descricao' => 'A', 'quantidade' => '1', 'valor_unitario' => '10,00', 'desconto' => '50,00'], 'desconto não pode'],
        [['descricao' => 'A', 'quantidade' => 'abc', 'valor_unitario' => '10,00'], 'número válido'],
        [['descricao' => 'A', 'quantidade' => '1', 'valor_unitario' => 'xx'], 'reais válido'],
        [['descricao' => 'A', 'quantidade' => '1', 'valor_unitario' => '1,00', 'servico_id' => 999], 'inexistente'],
    ] as [$item, $trecho]) {
        $r = $x->criar('propostas', ['titulo' => 'X', 'itens' => [$item]]);
        verdadeiro(!$r->ok, 'deveria falhar: ' . json_encode($item));
        contem($trecho, $r->erros['itens'] ?? json_encode($r->erros));
    }

    $r = $x->criar('propostas', ['titulo' => 'Vazia', 'itens' => []]);
    verdadeiro($r->ok, 'rascunho sem itens é permitido');
    igual(0, (int) Repositorios::propostas()->encontrar((int) $r->id)['total']);
    $r = $x->criar('propostas', ['titulo' => 'Linhas vazias', 'itens' => [['descricao' => '', 'valor_unitario' => ''], []]]);
    verdadeiro($r->ok, 'linhas totalmente vazias são ignoradas');
    verdadeiro(!$x->criar('propostas', ['titulo' => 'X', 'status' => 'aceita', 'total' => 1])->ok, 'status e total são controlados pelo servidor');
    verdadeiro(!$x->criar('propostas', ['titulo' => 'X', 'token_publico' => 'abc'])->ok, 'token não é aceito na entrada');
});

teste('Proposta: modelo preenche a apresentação com as variáveis e exige modelo do tipo proposta', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);
    (new ConfiguracaoRepository())->definir('empresa.nome', 'Lárbous');
    $modelo = (int) $x->criar('modelos_documento', ['tipo' => 'proposta', 'nome' => 'M1', 'conteudo' => 'Olá {contato.primeiro_nome}, proposta para {empresa.nome} ({negocio.titulo}) por {larbous.nome}. Total {proposta.total}.'])->id;
    $contratoModelo = (int) $x->criar('modelos_documento', ['tipo' => 'contrato', 'nome' => 'C1', 'conteudo' => '<p>x</p>'])->id;

    $id = novaProposta($x, $neg, ['modelo_id' => $modelo]);
    igual('Olá Ana, proposta para Padaria Central (Site novo) por Lárbous. Total R$ 7.940,00.', Repositorios::propostas()->encontrar($id)['apresentacao']);

    $manual = novaProposta($x, $neg, ['modelo_id' => $modelo, 'apresentacao' => 'Texto próprio']);
    igual('Texto próprio', Repositorios::propostas()->encontrar($manual)['apresentacao'], 'não sobrescreve o texto informado');

    $r = $x->criar('propostas', ['titulo' => 'X', 'modelo_id' => $contratoModelo]);
    verdadeiro(!$r->ok);
    contem('tipo proposta', $r->erros['modelo_id']);
});

teste('Proposta: editar itens recalcula totais, audita os itens e o desfazer restaura tudo', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);
    $id = novaProposta($x, $neg);

    $r = $x->atualizar('propostas', $id, [
        'titulo' => 'Novo título',
        'itens' => [['descricao' => 'Só o site', 'quantidade' => '1', 'valor_unitario' => '5.000,00']],
    ]);
    verdadeiro($r->ok, json_encode($r->erros));
    $p = Repositorios::propostas()->encontrar($id);
    igual(500000, (int) $p['total']);
    igual(0, (int) $p['total_recorrente']);
    igual('Novo título', $p['titulo']);
    igual(1, count((new ItemPropostaRepository())->porProposta($id)));

    $log = $pdo->query("SELECT antes, depois FROM log_auditoria WHERE entidade = 'propostas' AND acao = 'atualizar'")->fetch();
    igual(2, count(json_decode($log['antes'], true)['_itens']));
    igual(1, count(json_decode($log['depois'], true)['_itens']));

    igual('Nada a alterar.', $x->atualizar('propostas', $id, ['titulo' => 'Novo título'])->mensagem, 'sem mudança de itens nem campos');

    // Mudar só o desconto recalcula o total sem tocar nos itens
    verdadeiro($x->atualizar('propostas', $id, ['desconto_tipo' => 'percentual', 'desconto_valor' => '20'])->ok);
    igual(400000, (int) Repositorios::propostas()->encontrar($id)['total']);
    igual(1, count((new ItemPropostaRepository())->porProposta($id)));

    verdadeiro($x->desfazer()->ok, 'desfaz o desconto');
    igual(500000, (int) Repositorios::propostas()->encontrar($id)['total']);
    $r = $x->desfazer();
    verdadeiro($r->ok, $r->mensagem);
    $p = Repositorios::propostas()->encontrar($id);
    igual(794000, (int) $p['total'], 'total anterior');
    igual('Proposta — Site', $p['titulo']);
    igual(2, count((new ItemPropostaRepository())->porProposta($id)), 'itens anteriores voltaram');
});

teste('Proposta: enviar exige itens e validade futura, registra atividade e evento; edição trava depois de enviada', function () {
    bancoComSeed();
    $eventos = [];
    $x = new ActionExecutor();
    [$emp, , $neg] = negocioComContato($x);
    capturarEventos($eventos);

    $vazia = (int) $x->criar('propostas', ['titulo' => 'Vazia', 'negocio_id' => $neg])->id;
    contem('ao menos um item', $x->enviarProposta($vazia)->mensagem);

    $vencida = novaProposta($x, $neg, ['data_emissao' => '2026-01-01', 'validade' => '2026-01-10']);
    contem('validade', $x->enviarProposta($vencida)->mensagem);

    $id = novaProposta($x, $neg);
    $r = $x->enviarProposta($id);
    verdadeiro($r->ok, $r->mensagem);
    $p = Repositorios::propostas()->encontrar($id);
    igual('enviada', $p['status']);
    verdadeiro($p['enviada_em'] !== null);
    verdadeiro(in_array('proposta.enviada', $eventos, true));
    $atv = DB_atividade('proposta', $neg);
    contem('enviada', $atv['assunto']);
    igual($emp, (int) $atv['empresa_id']);
    igual('sistema', $atv['criado_por']);

    verdadeiro(!$x->enviarProposta($id)->ok, 'não reenvia');
    $r = $x->atualizar('propostas', $id, ['titulo' => 'Mudei']);
    verdadeiro(!$r->ok);
    contem('nova versão', $r->erros['_']);
    verdadeiro(!$x->atualizar('propostas', $id, ['itens' => [['descricao' => 'x', 'valor_unitario' => '1,00']]])->ok);
});

/** Última atividade do tipo para o negócio. */
function DB_atividade(string $tipo, int $negocioId): array
{
    $st = \App\Core\DB::conexao()->prepare('SELECT * FROM atividades WHERE tipo = :t AND negocio_id = :n ORDER BY id DESC LIMIT 1');
    $st->execute(['t' => $tipo, 'n' => $negocioId]);
    return $st->fetch() ?: [];
}

teste('Proposta: link público — visualização única, aceite com CPF/CNPJ válido, recusa e regras de validade', function () {
    bancoComSeed();
    $eventos = [];
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);
    $id = novaProposta($x, $neg);
    verdadeiro(!$x->responderProposta($id, 'aceita', ['nome' => 'Ana', 'documento' => CPF_VALIDO])->ok, 'rascunho não pode ser respondido');
    $x->enviarProposta($id);
    capturarEventos($eventos);

    $r = $x->registrarVisualizacaoProposta($id);
    verdadeiro($r->ok);
    igual('visualizada', Repositorios::propostas()->encontrar($id)['status']);
    $x->registrarVisualizacaoProposta($id);
    igual(['proposta.visualizada'], $eventos, 'só a primeira abertura conta');
    igual(1, (int) \App\Core\DB::conexao()->query("SELECT COUNT(*) FROM log_auditoria WHERE acao = 'visualizar_proposta'")->fetchColumn());

    $r = $x->responderProposta($id, 'aceita', ['nome' => 'A', 'documento' => '123']);
    verdadeiro(!$r->ok);
    verdadeiro(isset($r->erros['nome'], $r->erros['documento']));
    igual('visualizada', Repositorios::propostas()->encontrar($id)['status'], 'nada mudou');
    verdadeiro(!$x->responderProposta($id, 'aceita', ['nome' => 'Ana Souza', 'documento' => '111.111.111-11'])->ok, 'CPF com dígitos repetidos');
    verdadeiro(!$x->responderProposta($id, 'talvez', [])->ok);

    $r = $x->responderProposta($id, 'aceita', ['nome' => '  Ana Souza ', 'documento' => CPF_VALIDO, 'ip' => '203.0.113.7']);
    verdadeiro($r->ok, json_encode($r->erros));
    $p = Repositorios::propostas()->encontrar($id);
    igual('aceita', $p['status']);
    igual('Ana Souza', $p['aceite_nome']);
    igual('52998224725', $p['aceite_documento']);
    igual('203.0.113.7', $p['aceite_ip']);
    verdadeiro($p['respondida_em'] !== null);
    igual(['proposta.visualizada', 'proposta.aceita'], $eventos);
    contem('aceita', DB_atividade('proposta', $neg)['assunto']);
    igual('sistema', \App\Core\DB::conexao()->query("SELECT origem FROM log_auditoria WHERE acao = 'aceitar_proposta'")->fetchColumn());
    verdadeiro(!$x->responderProposta($id, 'recusada', [])->ok, 'já respondida');

    // recusa
    $id2 = novaProposta($x, $neg);
    $x->enviarProposta($id2);
    verdadeiro(!$x->responderProposta($id2, 'recusada', ['motivo' => str_repeat('x', 501)])->ok);
    verdadeiro($x->responderProposta($id2, 'recusada', ['motivo' => 'Achamos caro'])->ok);
    $p2 = Repositorios::propostas()->encontrar($id2);
    igual('recusada', $p2['status']);
    igual('Achamos caro', $p2['motivo_recusa']);
    igual(null, $p2['aceite_nome']);

    // CNPJ também vale
    $id3 = novaProposta($x, $neg);
    $x->enviarProposta($id3);
    verdadeiro($x->responderProposta($id3, 'aceita', ['nome' => 'Padaria Central Ltda', 'documento' => '11.222.333/0001-81'])->ok);
});

teste('Proposta: expirada não pode ser respondida, e versão antiga é bloqueada quando há uma nova', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);

    $id = novaProposta($x, $neg);
    $x->enviarProposta($id);
    \App\Core\DB::conexao()->exec("UPDATE propostas SET validade = '2026-01-01' WHERE id = {$id}");
    verdadeiro(proposta_expirada(Repositorios::propostas()->encontrar($id)));
    $r = $x->responderProposta($id, 'aceita', ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO]);
    verdadeiro(!$r->ok);
    contem('expirou', $r->mensagem);

    $b = novaProposta($x, $neg);
    $x->enviarProposta($b);
    $v2 = $x->novaVersaoProposta($b);
    verdadeiro($v2->ok, $v2->mensagem);
    $r = $x->responderProposta($b, 'aceita', ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO]);
    verdadeiro(!$r->ok);
    contem('versão mais recente', $r->mensagem);
});

teste('Proposta: nova versão copia dados e itens, gera novo link e a lista mostra só a mais recente', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);
    $id = novaProposta($x, $neg, ['apresentacao' => 'Olá', 'forma_pagamento' => 'PIX', 'desconto_tipo' => 'percentual', 'desconto_valor' => '10']);

    contem('rascunho', $x->novaVersaoProposta($id)->mensagem);
    $x->enviarProposta($id);
    $r = $x->novaVersaoProposta($id);
    verdadeiro($r->ok, $r->mensagem);
    $v2 = Repositorios::propostas()->encontrar((int) $r->id);
    $v1 = Repositorios::propostas()->encontrar($id);
    igual($v1['numero'], $v2['numero']);
    igual(2, (int) $v2['versao']);
    igual('rascunho', $v2['status']);
    igual('Olá', $v2['apresentacao']);
    igual('PIX', $v2['forma_pagamento']);
    igual((int) $v1['total'], (int) $v2['total']);
    verdadeiro($v2['token_publico'] !== $v1['token_publico']);
    igual(null, $v2['enviada_em']);
    igual(2, count((new ItemPropostaRepository())->porProposta((int) $r->id)));
    igual('enviada', Repositorios::propostas()->encontrar($id)['status'], 'a anterior fica como histórico');
    contem('mais recente', $x->novaVersaoProposta($id)->mensagem);

    $lista = Repositorios::propostas()->listar();
    igual(1, $lista['total'], 'lista só a versão mais recente');
    igual((int) $r->id, (int) $lista['linhas'][0]['id']);
    igual([2, 1], array_map('intval', array_column(Repositorios::propostas()->versoes($v1['numero']), 'versao')));
    igual(1, count(Repositorios::propostas()->ultimasPor('negocio_id', $neg)));
    igual('nova_versao', $pdo->query("SELECT acao FROM log_auditoria WHERE entidade = 'propostas' ORDER BY id DESC LIMIT 1")->fetchColumn());
});

// ---------------------------------------------------------------------------------------------
// Contratos
// ---------------------------------------------------------------------------------------------

teste('Contrato: nasce da proposta aceita, herda vínculos e valores e gera o texto a partir do modelo', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [$emp, $cid, $neg] = negocioComContato($x);
    $cfg = new ConfiguracaoRepository();
    $cfg->definir('empresa.nome', 'Lárbous');
    $cfg->definir('empresa.cidade', 'Recife');
    $modelo = (int) $x->criar('modelos_documento', ['tipo' => 'contrato', 'nome' => 'Contrato', 'conteudo' =>
        '<h1>Contrato {contrato.numero}</h1><p>{empresa.razao} ({empresa.cnpj}) contrata {larbous.nome} por {contrato.valor_total} ({contrato.valor_total_extenso}). Foro: {larbous.cidade}.</p>{proposta.itens}<script>alert(1)</script><p onclick="x()">{contato.nome_completo}</p>'])->id;
    $tipo = (int) $x->criar('contrato_tipos', ['nome' => 'Desenvolvimento'])->id;

    $aceita = propostaAceita($x, $neg);
    $rascunho = novaProposta($x, $neg);
    $r = $x->criar('contratos', ['titulo' => 'X', 'proposta_id' => $rascunho, 'recorrencia' => 'unica']);
    verdadeiro(!$r->ok, 'só proposta aceita');
    contem('aceitas', $r->erros['proposta_id']);

    $r = $x->criar('contratos', ['titulo' => 'Contrato — Site', 'proposta_id' => $aceita, 'modelo_id' => $modelo, 'tipo_id' => $tipo, 'recorrencia' => 'mensal', 'data_inicio' => '2026-10-01', 'data_fim' => '2027-09-30']);
    verdadeiro($r->ok, json_encode($r->erros));
    $c = Repositorios::contratos()->encontrar((int) $r->id);
    igual('CT-' . date('Y') . '-0001', $c['numero']);
    igual('rascunho', $c['status']);
    igual($emp, (int) $c['empresa_id']);
    igual($cid, (int) $c['contato_id']);
    igual($neg, (int) $c['negocio_id']);
    igual(794000, (int) $c['valor_total']);
    igual(144000, (int) $c['valor_mensal']);
    igual(40, strlen($c['token_publico']));
    contem('Contrato CT-' . date('Y') . '-0001', $c['conteudo']);
    contem('Padaria Central Ltda (11.222.333/0001-81) contrata Lárbous por R$ 7.940,00 (sete mil novecentos e quarenta reais)', $c['conteudo']);
    contem('Foro: Recife', $c['conteudo']);
    contem('Site institucional', $c['conteudo']);
    contem('Ana Souza', $c['conteudo']);
    verdadeiro(!str_contains($c['conteudo'], '<script') && !str_contains($c['conteudo'], 'onclick'), 'conteúdo sanitizado: ' . $c['conteudo']);

    $r2 = $x->criar('contratos', ['titulo' => 'Sem modelo', 'recorrencia' => 'unica']);
    verdadeiro($r2->ok);
    igual('CT-' . date('Y') . '-0002', Repositorios::contratos()->encontrar((int) $r2->id)['numero']);
    igual(null, Repositorios::contratos()->encontrar((int) $r2->id)['conteudo']);
    $modeloProposta = (int) $x->criar('modelos_documento', ['tipo' => 'proposta', 'nome' => 'P', 'conteudo' => 'x'])->id;
    verdadeiro(!$x->criar('contratos', ['titulo' => 'X', 'modelo_id' => $modeloProposta, 'recorrencia' => 'unica'])->ok, 'modelo de outro tipo');
    contem('anterior', $x->criar('contratos', ['titulo' => 'X', 'recorrencia' => 'unica', 'data_inicio' => '2026-05-01', 'data_fim' => '2026-04-01'])->erros['data_fim']);
    verdadeiro(!$x->criar('contratos', ['titulo' => 'X', 'recorrencia' => 'unica', 'status' => 'ativo'])->ok, 'status não é editável');
});

teste('Contrato: conteúdo HTML informado à mão também é sanitizado ao salvar', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $id = (int) $x->criar('contratos', ['titulo' => 'C', 'recorrencia' => 'unica', 'conteudo' => '<p>ok</p><script>x()</script><a href="javascript:x()">l</a>'])->id;
    igual('<p>ok</p>l', str_replace(['<a>', '</a>'], '', Repositorios::contratos()->encontrar($id)['conteudo']));
    verdadeiro($x->atualizar('contratos', $id, ['conteudo' => '<p onmouseover="a()">novo</p>'])->ok);
    igual('<p>novo</p>', Repositorios::contratos()->encontrar($id)['conteudo']);
});

teste('Contrato: enviar exige conteúdo; assinar valida documento, muda status por vigência, dispara contrato.assinado', function () {
    bancoComSeed();
    $eventos = [];
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);

    $sem = (int) $x->criar('contratos', ['titulo' => 'Sem texto', 'recorrencia' => 'unica', 'negocio_id' => $neg])->id;
    contem('sem conteúdo', $x->enviarContrato($sem)->mensagem);

    $id = (int) $x->criar('contratos', ['titulo' => 'Com texto', 'recorrencia' => 'mensal', 'negocio_id' => $neg, 'conteudo' => '<p>Termos</p>', 'data_inicio' => hoje()])->id;
    verdadeiro(!$x->assinarContrato($id, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO])->ok, 'rascunho não assina');
    capturarEventos($eventos);
    verdadeiro($x->enviarContrato($id)->ok);
    $c = Repositorios::contratos()->encontrar($id);
    igual('enviado', $c['status']);
    verdadeiro($c['enviado_em'] !== null);
    verdadeiro(!$x->enviarContrato($id)->ok);
    verdadeiro(!$x->atualizar('contratos', $id, ['titulo' => 'Mudei'])->ok, 'enviado não é editável');

    igual(true, $x->registrarVisualizacaoContrato($id)->ok);
    $x->registrarVisualizacaoContrato($id);
    igual(1, (int) \App\Core\DB::conexao()->query("SELECT COUNT(*) FROM log_auditoria WHERE acao = 'visualizar_contrato'")->fetchColumn());

    $r = $x->assinarContrato($id, ['nome' => 'A', 'documento' => '000']);
    verdadeiro(!$r->ok);
    verdadeiro(isset($r->erros['nome'], $r->erros['documento']));

    $r = $x->assinarContrato($id, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO, 'ip' => '198.51.100.9']);
    verdadeiro($r->ok, json_encode($r->erros));
    $c = Repositorios::contratos()->encontrar($id);
    igual('ativo', $c['status'], 'vigência já começou');
    igual('Ana Souza', $c['assinatura_nome']);
    igual('52998224725', $c['assinatura_documento']);
    igual('198.51.100.9', $c['assinatura_ip']);
    verdadeiro($c['assinado_em'] !== null);
    igual(['contrato.assinado'], $eventos);
    contem('assinado', DB_atividade('contrato', $neg)['assunto']);
    verdadeiro(!$x->assinarContrato($id, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO])->ok, 'não assina duas vezes');

    // vigência futura fica "assinado"
    $futuro = (int) $x->criar('contratos', ['titulo' => 'Futuro', 'recorrencia' => 'unica', 'conteudo' => '<p>x</p>', 'data_inicio' => date('Y-m-d', strtotime('+30 days'))])->id;
    $x->enviarContrato($futuro);
    $x->assinarContrato($futuro, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO]);
    igual('assinado', Repositorios::contratos()->encontrar($futuro)['status']);
});

teste('Contrato: renovar cria rascunho ligado ao original (vigência seguinte) e marca o original como renovado', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    [$emp, , $neg] = negocioComContato($x);
    $id = (int) $x->criar('contratos', ['titulo' => 'Manutenção', 'recorrencia' => 'mensal', 'valor_mensal' => '300,00', 'valor_total' => '3.600,00', 'empresa_id' => $emp,
        'negocio_id' => $neg, 'conteudo' => '<p>Termos</p>', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'aviso_renovacao_dias' => 45])->id;
    contem('assinados', $x->renovarContrato($id)->mensagem, 'rascunho não renova');
    $x->enviarContrato($id);
    $x->assinarContrato($id, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO]);

    $r = $x->renovarContrato($id);
    verdadeiro($r->ok, $r->mensagem);
    $novo = Repositorios::contratos()->encontrar((int) $r->id);
    $orig = Repositorios::contratos()->encontrar($id);
    igual('renovado', $orig['status']);
    igual('rascunho', $novo['status']);
    igual($id, (int) $novo['contrato_origem_id']);
    igual('Manutenção (renovação)', $novo['titulo']);
    igual('2027-01-01', $novo['data_inicio']);
    igual('2027-12-31', $novo['data_fim'], 'mesma duração do original');
    igual(30000, (int) $novo['valor_mensal']);
    igual(360000, (int) $novo['valor_total']);
    igual(45, (int) $novo['aviso_renovacao_dias']);
    igual($emp, (int) $novo['empresa_id']);
    igual('<p>Termos</p>', $novo['conteudo']);
    igual(null, $novo['assinado_em']);
    verdadeiro($novo['token_publico'] !== $orig['token_publico']);
    igual('CT-' . date('Y') . '-0002', $novo['numero']);
    verdadeiro(!$x->renovarContrato($id)->ok);

    // renovar o renovado (rascunho → assina → renova) mantém "(renovação)" sem repetir
    $x->enviarContrato((int) $r->id);
    $x->assinarContrato((int) $r->id, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO]);
    $r3 = $x->renovarContrato((int) $r->id);
    igual('Manutenção (renovação)', Repositorios::contratos()->encontrar((int) $r3->id)['titulo']);
    igual('renovar_contrato', $pdo->query("SELECT acao FROM log_auditoria WHERE acao = 'renovar_contrato' LIMIT 1")->fetchColumn());
});

teste('Contrato: cancelar, filtros de vencimento, MRR da empresa e vínculo de tarefas', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    $mk = function (string $titulo, array $extra) use ($x, $emp): int {
        $id = (int) $x->criar('contratos', $extra + ['titulo' => $titulo, 'recorrencia' => 'mensal', 'empresa_id' => $emp, 'conteudo' => '<p>x</p>'])->id;
        $x->enviarContrato($id);
        $x->assinarContrato($id, ['nome' => 'Ana Souza', 'documento' => CPF_VALIDO]);
        return $id;
    };
    $a = $mk('Vence logo', ['valor_mensal' => '500,00', 'data_inicio' => '2026-01-01', 'data_fim' => date('Y-m-d', strtotime('+20 days'))]);
    $b = $mk('Longe', ['valor_mensal' => '250,50', 'data_inicio' => '2026-01-01', 'data_fim' => date('Y-m-d', strtotime('+300 days'))]);
    $c = $mk('Passou', ['valor_mensal' => '100,00', 'data_inicio' => '2025-01-01', 'data_fim' => date('Y-m-d', strtotime('-10 days'))]);

    $repo = Repositorios::contratos();
    igual(['Vence logo'], array_column($repo->listar(['filtros' => ['vencimento' => 'vencendo']])['linhas'], 'titulo'));
    igual(['Passou'], array_column($repo->listar(['filtros' => ['vencimento' => 'vencidos']])['linhas'], 'titulo'));
    igual(3, $repo->listar(['filtros' => ['vencimento' => 'qualquer']])['total'], 'filtro desconhecido é ignorado');
    igual(3, $repo->listar(['filtros' => ['status' => 'ativo']])['total']);
    verdadeiro(contrato_vencido_efetivo($repo->encontrar($c)));
    verdadeiro(!contrato_vencido_efetivo($repo->encontrar($b)));

    igual(85050, (int) Repositorios::empresas()->encontrar($emp)['mrr'], 'MRR soma valor_mensal dos vigentes (100+500+250,50)');
    verdadeiro($x->cancelarContrato($c)->ok);
    igual(75050, (int) Repositorios::empresas()->encontrar($emp)['mrr'], 'cancelado sai do MRR');
    verdadeiro(!$x->cancelarContrato($c)->ok, 'já cancelado');
    verdadeiro($x->desfazer()->ok);
    igual('ativo', $repo->encontrar($c)['status'], 'desfazer restaura o status');

    $t = $x->criar('tarefas', ['titulo' => 'Renovar com o cliente', 'contrato_id' => $a]);
    verdadeiro($t->ok);
    igual(1, count(Repositorios::tarefas()->porVinculo('contrato_id', $a)));
    igual('CT-' . date('Y') . '-0001', Repositorios::tarefas()->encontrar((int) $t->id)['contrato_numero']);
});

teste('Serviços, modelos e tipos de contrato: validação, nome único e ativos', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $r = $x->criar('servicos', ['nome' => 'Site institucional', 'categoria' => 'site', 'unidade' => 'projeto', 'preco_base' => '6.500,00', 'preco_minimo' => '5.000,00', 'recorrente' => 0, 'ativo' => 1]);
    verdadeiro($r->ok, json_encode($r->erros));
    $s = Repositorios::servicos()->encontrar((int) $r->id);
    igual(650000, (int) $s['preco_base']);
    igual(1, (int) $s['ativo']);
    verdadeiro(!$x->criar('servicos', ['nome' => 'X', 'categoria' => 'nao_existe', 'unidade' => 'projeto'])->ok);
    verdadeiro(!$x->criar('servicos', ['nome' => 'X', 'categoria' => 'site', 'unidade' => 'projeto', 'preco_base' => '-5'])->ok, 'preço negativo');

    $inativo = (int) $x->criar('servicos', ['nome' => 'Antigo', 'categoria' => 'outro', 'unidade' => 'hora', 'ativo' => 0])->id;
    igual([(int) $r->id], array_map(static fn (array $a) => (int) $a['id'], Repositorios::servicos()->ativos()), 'só os ativos entram no catálogo da proposta');

    // serviço vira item de proposta com vínculo
    $p = $x->criar('propostas', ['titulo' => 'Com serviço', 'itens' => [['servico_id' => (int) $r->id, 'descricao' => 'Site institucional', 'quantidade' => '1', 'valor_unitario' => '6.500,00']]]);
    verdadeiro($p->ok);
    igual((int) $r->id, (int) (new ItemPropostaRepository())->porProposta((int) $p->id)[0]['servico_id']);

    $m = $x->criar('modelos_documento', ['tipo' => 'whatsapp', 'nome' => 'Follow-up', 'conteudo' => 'Oi {contato.primeiro_nome}!']);
    verdadeiro($m->ok);
    verdadeiro($x->atualizar('modelos_documento', (int) $m->id, ['conteudo' => ''])->ok, 'conteúdo pode ser esvaziado sem violar NOT NULL');
    igual('', Repositorios::modelos()->encontrar((int) $m->id)['conteudo']);
    verdadeiro(!$x->criar('modelos_documento', ['tipo' => 'fax', 'nome' => 'X'])->ok);
    igual([(int) $m->id], array_map('intval', array_keys(Repositorios::modelos()->opcoesPorTipo('whatsapp'))));

    verdadeiro($x->criar('contrato_tipos', ['nome' => 'Desenvolvimento'])->ok);
    verdadeiro(!$x->criar('contrato_tipos', ['nome' => 'desenvolvimento'])->ok, 'tipo de contrato único');
    verdadeiro($inativo > 0);
});

teste('Anexos aceitam propostas e contratos como destino', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);
    $p = novaProposta($x, $neg);
    $r = $x->criar('anexos', ['entidade' => 'propostas', 'registro_id' => $p, 'nome_original' => 'briefing.pdf', 'caminho' => '2026/09/abc', 'mime' => 'application/pdf', 'tamanho' => 10]);
    verdadeiro($r->ok, json_encode($r->erros));
    igual(1, count(Repositorios::anexos()->porRegistro('propostas', $p)));
    verdadeiro(!$x->criar('anexos', ['entidade' => 'contratos', 'registro_id' => 999, 'nome_original' => 'a', 'caminho' => 'b', 'mime' => 'c', 'tamanho' => 1])->ok);
});
