<?php

declare(strict_types=1);

use App\Controllers\DocumentoDados;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Html;
use App\Services\Variaveis;

teste('Contrato: variáveis do texto são preenchidas na impressão, com campos novos de contrato e empresa', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x, 'Padaria Central', [
        'razao_social' => 'Padaria Central Ltda', 'cnpj' => '11.222.333/0001-81', 'logradouro' => 'Rua das Flores', 'numero' => '10', 'bairro' => 'Centro',
        'cidade' => 'Santos', 'uf' => 'SP', 'cep' => '11010-000', 'responsavel_nome' => 'Maria Dona', 'responsavel_cpf' => CPF_VALIDO, 'responsavel_rg' => '12.345.678-9',
    ]);
    $cid = (int) $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $emp])->id;
    $r = $x->criar('contratos', [
        'titulo' => 'Contrato teste', 'recorrencia' => 'mensal', 'empresa_id' => $emp, 'contato_id' => $cid, 'valor_total' => '1.250,00', 'dia_vencimento_pagamento' => 10,
        'valor_promocional' => '99,90', 'percentual_desconto' => '30', 'periodo_desconto' => '3 meses', 'data_inicio' => '2026-03-01',
        'representante_nome' => 'João Rep', 'representante_cpf' => CPF_VALIDO, 'representante_rg' => '99',
        'conteudo' => '<p align="center">{empresa.razao} ({empresa.cnpj}), {empresa.endereco_linha}, {empresa.cidade}/{empresa.uf}, {empresa.cep}; resp. {empresa.responsavel_nome}, CPF {empresa.responsavel_cpf}.</p>'
            . '<p>{contato.nome} {contato.sobrenome}: {contrato.valor_total} ({contrato.valor_total_extenso}), dia {contrato.dia_vencimento_pagamento}, desde {contrato.data_inicio}. '
            . 'Promo {contrato.valor_promocional} = {contrato.percentual_desconto} por {contrato.periodo_desconto}. Rep.: {contrato.representante_nome} {contrato.representante_cpf} RG {contrato.representante_rg}. {contrato.link} {nada.existe}</p>',
    ]);
    verdadeiro($r->ok, json_encode($r->erros));
    $c = Repositorios::contratos()->encontrar((int) $r->id);
    igual('99,90', substr(centavos_para_texto((int) $c['valor_promocional']), 0, 5));

    $corpo = DocumentoDados::contrato($c)['corpo'];
    contem('<p align="center">Padaria Central Ltda (11.222.333/0001-81), Rua das Flores, 10, Centro, Santos/SP, 11010-000; resp. Maria Dona, CPF 529.982.247-25.</p>', $corpo);
    contem('Ana Souza: ' . moeda(125000) . ' (', $corpo);
    contem('dia 10, desde 01/03/2026', $corpo);
    contem('Promo ' . moeda(9990) . ' = ' . percentual_br(3000) . ' por 3 meses', $corpo);
    contem('Rep.: João Rep 529.982.247-25 RG 99', $corpo);
    contem('/c/' . $c['token_publico'], $corpo);
    contem('{nada.existe}', $corpo, 'variável desconhecida fica visível');

    // O texto gravado continua com as variáveis (a edição não perde as tags).
    contem('{empresa.razao}', $c['conteudo']);

    // Ao assinar, o texto assinado fica congelado, sem variáveis.
    verdadeiro($x->enviarContrato((int) $r->id)->ok);
    verdadeiro($x->assinarContrato((int) $r->id, dadosAssinatura())->ok);
    $assinado = Repositorios::contratos()->encontrar((int) $r->id)['conteudo'];
    contem('Padaria Central Ltda (11.222.333/0001-81)', $assinado);
    verdadeiro(!str_contains($assinado, '{empresa.razao}'), 'variáveis resolvidas na assinatura');
    contem('{nada.existe}', $assinado);
});

teste('Html::sanitizar mantém alinhamento e tipo de lista do editor e remove o resto', function () {
    igual('<p align="center">a</p>', Html::sanitizar('<p align="center" style="color:red" class="x">a</p>'));
    igual('<h3>b</h3>', Html::sanitizar('<h3 align="evil">b</h3>'));
    igual('<ol type="a"><li>x</li></ol>', Html::sanitizar('<ol type="a" onclick="x()"><li>x</li></ol>'));
    igual('<ol><li>x</li></ol>', Html::sanitizar('<ol type="zzz"><li>x</li></ol>'));
});

teste('Assinatura digital: exige e-mail e desenho, grava prova (hash, navegador, imagem) e detecta alteração do texto', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);
    $id = (int) $x->criar('contratos', ['titulo' => 'C', 'recorrencia' => 'unica', 'negocio_id' => $neg, 'conteudo' => '<p>Contratada {larbous.nome}, cliente {empresa.nome}.</p>'])->id;
    verdadeiro($x->enviarContrato($id)->ok);

    $sem = $x->assinarContrato($id, dadosAssinatura(['assinatura' => '', 'email' => 'ruim']));
    verdadeiro(!$sem->ok);
    verdadeiro(isset($sem->erros['assinatura'], $sem->erros['email']), json_encode($sem->erros));
    verdadeiro(!$x->assinarContrato($id, dadosAssinatura(['assinatura' => 'data:image/png;base64,AAAA']))->ok, 'PNG inválido');
    verdadeiro(!$x->assinarContrato($id, dadosAssinatura(['assinatura' => 'data:image/svg+xml;base64,' . base64_encode('<svg onload="x()"/>')]))->ok, 'só PNG');

    $r = $x->assinarContrato($id, dadosAssinatura(['ip' => '203.0.113.5']));
    verdadeiro($r->ok, json_encode($r->erros));
    $c = Repositorios::contratos()->encontrar($id);
    igual('ana@exemplo.com.br', $c['assinatura_email']);
    igual('Teste/1.0', $c['assinatura_user_agent']);
    verdadeiro(str_starts_with($c['assinatura_imagem'], 'data:image/png;base64,'));
    igual(64, strlen($c['assinatura_hash']));
    verdadeiro(\App\Services\Assinatura::integro($c));
    verdadeiro(!str_contains($c['conteudo'], '{empresa.nome}'), 'texto congelado');

    // O desenho não vai para o log de auditoria (só um marcador).
    $log = (new \App\Repositories\AuditoriaRepository())->listar(['entidade' => 'contratos', 'registro_id' => (string) $id], 1, 20)['linhas'];
    foreach ($log as $linha) {
        verdadeiro(!str_contains((string) $linha['depois'], 'base64'), 'log sem imagem');
    }

    // Se o texto for adulterado depois, a verificação acusa; a página do contrato mostra o comprovante.
    verdadeiro(!\App\Services\Assinatura::integro(['conteudo' => $c['conteudo'] . 'x'] + $c));
    $html = \App\Core\View::partial('documentos/contrato', DocumentoDados::contrato($c));
    contem('Comprovante de assinatura eletrônica', $html);
    contem($c['assinatura_imagem'], $html);
    contem('idêntico ao que foi assinado', $html);
});

teste('Contrato: comentários do cliente avisam a equipe (atividade e tarefa) e a equipe responde na mesma conversa', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    [, , $neg] = negocioComContato($x);
    $id = (int) $x->criar('contratos', ['titulo' => 'C', 'recorrencia' => 'unica', 'negocio_id' => $neg, 'conteudo' => '<p>Texto</p>'])->id;
    verdadeiro(!$x->comentarContrato($id, 'cliente', 'Ana', 'Oi')->ok, 'rascunho não aceita comentários');
    verdadeiro($x->enviarContrato($id)->ok);

    verdadeiro(!$x->comentarContrato($id, 'cliente', '', 'x')->ok, 'nome e texto obrigatórios');
    $r = $x->comentarContrato($id, 'cliente', 'Ana Souza', 'Podemos mudar o prazo da cláusula 3?', '203.0.113.9', 'sistema');
    verdadeiro($r->ok, json_encode($r->erros));
    verdadeiro($x->comentarContrato($id, 'equipe', 'Luiz', 'Claro, ajustamos hoje.')->ok);

    $conversa = (new \App\Repositories\ContratoComentarioRepository())->porContrato($id);
    igual(['cliente', 'equipe'], array_column($conversa, 'autor'));
    $tarefas = Repositorios::tarefas()->porVinculo('contrato_id', $id);
    igual(1, count($tarefas), 'só o comentário do cliente cria tarefa');
    contem('Responder comentário do cliente', $tarefas[0]['titulo']);

    $x->cancelarContrato($id);
    verdadeiro(!$x->comentarContrato($id, 'cliente', 'Ana', 'Ainda aí?')->ok, 'cancelado não aceita comentários');
});
