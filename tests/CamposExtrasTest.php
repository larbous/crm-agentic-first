<?php

declare(strict_types=1);

use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\AI\AgenteDefinicao;
use App\Services\AI\ContextBuilder;
use App\Services\CamposExtras;
use App\Services\Variaveis;

/** Cria um campo extra pelo ActionExecutor e devolve o Resultado. */
function campoExtra(string $entidade, string $chave, string $tipo = 'texto', array $mais = []): \App\Services\Resultado
{
    return (new ActionExecutor())->criar('campos_extras_def', ['entidade' => $entidade, 'chave' => $chave, 'rotulo' => ucfirst($chave), 'tipo' => $tipo] + $mais);
}

/** Banco com campos extras típicos em empresas. */
function bancoComExtras(): void
{
    bancoComSeed();
    verdadeiro(campoExtra('empresas', 'nicho', 'select', ['opcoes' => "Alimentação\nModa\nSaúde"])->ok);
    verdadeiro(campoExtra('empresas', 'funcionarios', 'numero')->ok);
    verdadeiro(campoExtra('empresas', 'aniversario', 'data')->ok);
    verdadeiro(campoExtra('empresas', 'usa_crm', 'checkbox')->ok);
    verdadeiro(campoExtra('empresas', 'catalogo', 'url')->ok);
    verdadeiro(campoExtra('empresas', 'observacao', 'textarea')->ok);
}

teste('campos extras: definição valida chave, duplicidade e opções', function () {
    bancoComSeed();
    foreach (['Nicho', '1nicho', 'com espaço', 'acentuação', ''] as $ruim) {
        $r = campoExtra('empresas', $ruim);
        verdadeiro(!$r->ok && isset($r->erros['chave']), "chave inválida: '{$ruim}'");
    }
    $r = campoExtra('empresas', 'nicho', 'select', ['opcoes' => "A\n\n  B  \nA"]);
    verdadeiro($r->ok, $r->mensagem);
    $def = Repositorios::camposExtras()->daEntidade('empresas')[0];
    igual(['A', 'B'], $def['opcoes'], 'opções: uma por linha, sem vazias nem repetidas');
    igual(10, (int) $def['ordem'], 'ordem automática');

    verdadeiro(isset(campoExtra('empresas', 'nicho')->erros['chave']), 'chave duplicada na mesma entidade');
    verdadeiro(campoExtra('contatos', 'nicho')->ok, 'a mesma chave vale em outra entidade');
    verdadeiro(isset(campoExtra('empresas', 'x', 'select')->erros['opcoes']), 'lista sem opções');
    verdadeiro(!campoExtra('contratos', 'x')->ok, 'contratos não têm campos extras (CHECK da tabela)');
});

teste('campos extras: chave e entidade não mudam; tipo diferente de lista descarta opções', function () {
    bancoComSeed();
    $id = (int) campoExtra('empresas', 'nicho', 'select', ['opcoes' => "A\nB"])->id;
    $x = new ActionExecutor();
    verdadeiro(isset($x->atualizar('campos_extras_def', $id, ['chave' => 'outro'])->erros['chave']));
    verdadeiro(isset($x->atualizar('campos_extras_def', $id, ['entidade' => 'contatos'])->erros['entidade']));
    verdadeiro($x->atualizar('campos_extras_def', $id, ['rotulo' => 'Nicho de mercado', 'opcoes' => "A\nB\nC"])->ok);
    igual(['A', 'B', 'C'], Repositorios::camposExtras()->encontrar($id)['opcoes'] !== null ? json_decode(Repositorios::camposExtras()->encontrar($id)['opcoes'], true) : []);
    verdadeiro($x->atualizar('campos_extras_def', $id, ['tipo' => 'texto'])->ok);
    igual(null, Repositorios::camposExtras()->encontrar($id)['opcoes']);
});

teste('campos extras: grava, converte tipos e mescla sem apagar o que não veio', function () {
    bancoComExtras();
    $x = new ActionExecutor();
    $r = $x->criar('empresas', ['nome_fantasia' => 'Padaria', 'campos_extras' => [
        'nicho' => 'Moda', 'funcionarios' => '1.250', 'aniversario' => '05/03/2020', 'usa_crm' => '1', 'catalogo' => 'exemplo.com/catalogo', 'observacao' => "linha 1\nlinha 2",
    ]]);
    verdadeiro($r->ok, json_encode($r->erros));
    $id = (int) $r->id;
    $v = CamposExtras::valores(Repositorios::empresas()->encontrar($id)['campos_extras']);
    igual('Moda', $v['nicho']);
    igual(1250, $v['funcionarios'], 'número pt-BR vira número');
    igual('2020-03-05', $v['aniversario']);
    igual(1, $v['usa_crm']);
    igual('https://exemplo.com/catalogo', $v['catalogo'], 'URL sem esquema ganha https://');
    igual(['aniversario', 'catalogo', 'funcionarios', 'nicho', 'observacao', 'usa_crm'], array_keys($v), 'chaves em ordem estável');

    // Atualização parcial mescla; vazio remove; checkbox desmarcado remove.
    verdadeiro($x->atualizar('empresas', $id, ['campos_extras' => ['nicho' => 'Saúde', 'observacao' => '', 'usa_crm' => '0']])->ok);
    $v = CamposExtras::valores(Repositorios::empresas()->encontrar($id)['campos_extras']);
    igual('Saúde', $v['nicho']);
    igual(1250, $v['funcionarios'], 'o que não veio permanece');
    verdadeiro(!isset($v['observacao']) && !isset($v['usa_crm']));

    // Sem mudança: nada a alterar (não grava auditoria à toa).
    igual('Nada a alterar.', $x->atualizar('empresas', $id, ['campos_extras' => ['nicho' => 'Saúde']])->mensagem);
});

teste('campos extras: valores inválidos e chaves desconhecidas são recusados', function () {
    bancoComExtras();
    $x = new ActionExecutor();
    $ruim = [
        'nicho' => 'Automóveis', 'funcionarios' => 'muitos', 'aniversario' => '31/02/2020', 'catalogo' => 'ftp://x.com', 'usa_crm' => 'talvez',
    ];
    $r = $x->criar('empresas', ['nome_fantasia' => 'X', 'campos_extras' => $ruim]);
    verdadeiro(!$r->ok);
    foreach (array_keys($ruim) as $chave) {
        verdadeiro(isset($r->erros['campos_extras.' . $chave]), "erro esperado em {$chave}");
    }
    verdadeiro(isset($x->criar('empresas', ['nome_fantasia' => 'X', 'campos_extras' => ['nao_existe' => 'a']])->erros['campos_extras.nao_existe']));
    verdadeiro(isset($x->criar('empresas', ['nome_fantasia' => 'X', 'campos_extras' => 'texto solto'])->erros['campos_extras']));
    igual(0, count(Repositorios::empresas()->listar()['linhas']), 'nada foi gravado');

    // Campo inativo ou arquivado deixa de valer.
    $def = Repositorios::camposExtras()->daEntidade('empresas')[0];
    $x->atualizar('campos_extras_def', (int) $def['id'], ['ativo' => 0]);
    verdadeiro(!$x->criar('empresas', ['nome_fantasia' => 'X', 'campos_extras' => ['nicho' => 'Moda']])->ok);
});

teste('campos extras: obrigatório só é exigido de quem edita pela tela (humano)', function () {
    bancoComSeed();
    verdadeiro(campoExtra('empresas', 'nicho', 'texto', ['obrigatorio' => 1])->ok);
    verdadeiro(campoExtra('empresas', 'aceita', 'checkbox', ['obrigatorio' => 1])->ok);
    $x = new ActionExecutor();

    $r = $x->criar('empresas', ['nome_fantasia' => 'A'], 'humano');
    verdadeiro(!$r->ok && isset($r->erros['campos_extras.nicho']) && isset($r->erros['campos_extras.aceita']), 'humano sem os campos');
    verdadeiro($x->criar('empresas', ['nome_fantasia' => 'A', 'campos_extras' => ['nicho' => 'x', 'aceita' => '1']], 'humano')->ok);
    verdadeiro($x->criar('empresas', ['nome_fantasia' => 'B'], 'ia')->ok, 'chat/IA não trava');
    verdadeiro($x->criar('empresas', ['nome_fantasia' => 'C'], 'agente:pesquisador')->ok, 'agente não trava');
    verdadeiro($x->criar('empresas', ['nome_fantasia' => 'D'], 'sistema')->ok);

    // Editar sem tocar nos extras (ex.: converter em cliente) não exige nada; limpar um obrigatório pela tela exige.
    $id = (int) Repositorios::empresas()->listar(['busca' => 'B'])['linhas'][0]['id'];
    verdadeiro($x->converterCliente($id, 'humano')->ok);
    $r = $x->atualizar('empresas', $id, ['campos_extras' => ['nicho' => '', 'aceita' => '0']], 'humano');
    verdadeiro(!$r->ok && isset($r->erros['campos_extras.nicho']));
});

teste('campos extras: chat e agentes não gravam campos_extras (valor não escalar)', function () {
    bancoComExtras();
    // A IA só devolve escalares; a whitelist do roteador e a dos agentes não aceitam objeto em "dados".
    $prompt = \App\Services\AI\CommandRouter::prompt();
    verdadeiro(!str_contains($prompt, 'campos_extras'), 'o prompt do roteador não anuncia campos_extras');
    verdadeiro(!isset(AgenteDefinicao::camposGravaveisConhecidos()['campos_extras']));
});

teste('campos extras: desfazer restaura o JSON anterior', function () {
    bancoComExtras();
    $x = new ActionExecutor();
    $id = (int) $x->criar('empresas', ['nome_fantasia' => 'Padaria', 'campos_extras' => ['nicho' => 'Moda']])->id;
    $r = $x->atualizar('empresas', $id, ['campos_extras' => ['nicho' => 'Saúde', 'funcionarios' => '10']]);
    verdadeiro($r->ok);
    verdadeiro($x->desfazer($r->logId)->ok);
    igual(['nicho' => 'Moda'], CamposExtras::valores(Repositorios::empresas()->encontrar($id)['campos_extras']));
});

teste('campos extras: filtro de lista por lista de opções e por sim/não', function () {
    bancoComExtras();
    $x = new ActionExecutor();
    $x->criar('empresas', ['nome_fantasia' => 'A', 'campos_extras' => ['nicho' => 'Moda', 'usa_crm' => '1']]);
    $x->criar('empresas', ['nome_fantasia' => 'B', 'campos_extras' => ['nicho' => 'Saúde']]);
    $x->criar('empresas', ['nome_fantasia' => 'C']);
    $nomes = static fn (array $filtros): array => array_column(Repositorios::empresas()->listar(['filtros' => $filtros, 'ordem' => 'nome_fantasia'])['linhas'], 'nome_fantasia');

    igual(['A'], $nomes(['x_nicho' => 'Moda']));
    igual(['B'], $nomes(['x_nicho' => 'Saúde']));
    igual(['A'], $nomes(['x_usa_crm' => 'sim']));
    igual(['B', 'C'], $nomes(['x_usa_crm' => 'nao']));
    igual(['A', 'B', 'C'], $nomes(['x_desconhecido' => 'zzz']), 'filtro de chave inexistente é ignorado');
    igual(['A', 'B', 'C'], $nomes(['x_funcionarios' => '10']), 'só lista e sim/não filtram');
    igual(['x_nicho', 'x_usa_crm'], array_keys(CamposExtras::filtros('empresas')));
    igual(['x_nicho', 'x_funcionarios', 'x_aniversario', 'x_usa_crm', 'x_catalogo', 'x_observacao'], array_keys(CamposExtras::colunas('empresas')));
});

teste('campos extras: variáveis de modelo {empresa.extra.chave}', function () {
    bancoComExtras();
    $id = (int) (new ActionExecutor())->criar('empresas', ['nome_fantasia' => 'Padaria', 'campos_extras' => [
        'nicho' => 'Alimentação', 'funcionarios' => '12', 'aniversario' => '2020-03-05', 'usa_crm' => '1', 'observacao' => '<b>x</b>',
    ]])->id;
    $ctx = Variaveis::contexto(['empresa_id' => $id]);
    $nao = [];
    $texto = Variaveis::renderizar('{empresa.nome}: {empresa.extra.nicho} / {empresa.extra.funcionarios} / {empresa.extra.aniversario} / {empresa.extra.usa_crm}', $ctx, false, $nao);
    igual('Padaria: Alimentação / 12 / 05/03/2020 / Sim', $texto);
    igual([], $nao);
    igual('&lt;b&gt;x&lt;/b&gt;', Variaveis::renderizar('{empresa.extra.observacao}', $ctx, true), 'modo HTML escapa');

    $nao = [];
    igual('', Variaveis::renderizar('{empresa.extra.catalogo}', $ctx, false, $nao), 'campo definido e vazio → texto vazio');
    Variaveis::renderizar('{empresa.extra.inexistente}', $ctx, false, $nao);
    igual(['{empresa.extra.inexistente}'], $nao, 'chave sem definição é "não resolvida"');
    verdadeiro(isset(Variaveis::catalogo()['Empresa']['{empresa.extra.nicho}']), 'aparece no catálogo da tela de modelos');
});

teste('campos extras: contexto de agentes (extra.chave)', function () {
    bancoComExtras();
    $v = AgenteDefinicao::validar(defAgente(['contexto' => ['nome_fantasia', 'extra.nicho', 'extra.usa_crm']]));
    verdadeiro($v['ok'], implode('; ', $v['erros']));
    $v = AgenteDefinicao::validar(defAgente(['contexto' => ['extra.nao_existe']]));
    verdadeiro(!$v['ok'], 'chave inexistente é recusada ao salvar o agente');
    $v = AgenteDefinicao::validar(defAgente(['entrada' => 'propostas', 'contexto' => ['extra.nicho']]));
    verdadeiro(!$v['ok'], 'entidade sem campos extras');

    $id = (int) (new ActionExecutor())->criar('empresas', ['nome_fantasia' => 'Padaria', 'campos_extras' => ['nicho' => 'Moda', 'usa_crm' => '1', 'funcionarios' => '5']])->id;
    $json = (new ContextBuilder())->paraAgente(defAgente(['contexto' => ['nome_fantasia', 'extra.nicho', 'extra.usa_crm', 'extra.aniversario']]), 'empresas', $id);
    $registro = json_decode($json, true)['registro'];
    igual(['nome_fantasia' => 'Padaria', 'Nicho' => 'Moda', 'Usa_crm' => 'Sim'], $registro, 'rótulo como chave; vazios e não pedidos ficam de fora');
});

teste('campos extras: formulário e detalhe são gerados a partir das definições', function () {
    bancoComExtras();
    $x = new ActionExecutor();
    $html = campos_extras_celulas('empresas', null);
    foreach (['campos_extras[nicho]', 'campos_extras[funcionarios]', 'campos_extras[aniversario]', 'campos_extras[usa_crm]', 'campos_extras[catalogo]', 'campos_extras[observacao]'] as $nome) {
        contem('name="' . $nome . '"', $html);
    }
    contem('Alimentação', $html, 'opções do select');
    contem('type="date"', $html);

    $empresa = Repositorios::empresas()->encontrar((int) $x->criar('empresas', ['nome_fantasia' => 'Padaria', 'campos_extras' => [
        'nicho' => 'Moda', 'funcionarios' => '1250', 'catalogo' => 'https://exemplo.com/?a=1&b=2', 'observacao' => "a\nb",
    ]])->id);
    $preenchido = campos_extras_celulas('empresas', $empresa['campos_extras']);
    contem('1.250', $preenchido, 'número volta formatado para o formulário');
    $erro = campos_extras_celulas('empresas', ['funcionarios' => 'abc'], ['campos_extras.funcionarios' => 'O campo Funcionarios deve ser um número.']);
    contem('value="abc"', $erro, 'reexibe o que foi digitado');
    contem('deve ser um número', $erro);

    $card = card_campos_extras('empresas', $empresa);
    contem('Campos extras', $card);
    contem('href="https://exemplo.com/?a=1&amp;b=2"', $card, 'URL vira link escapado');
    contem('rel="noopener noreferrer"', $card);
    igual('', card_campos_extras('contatos', ['campos_extras' => null]), 'sem definições não mostra cartão');
    igual('', card_campos_extras('propostas', []), 'entidade sem suporte');
});

teste('dashboard: funil por etapa, negócios parados e contratos vencendo', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $pdo = \App\Core\DB::conexao();
    $novo = etapaId('Novo lead');
    $prop = etapaId('Proposta');
    $mk = static fn (string $t, int $etapa, string $valor, string $prob) => (int) $x->criar('negocios', ['titulo' => $t, 'etapa_id' => $etapa, 'valor_estimado' => $valor, 'probabilidade' => $prob])->id;
    $a = $mk('A', $novo, '1.000,00', '10');
    $b = $mk('B', $novo, '500,00', '20');
    $c = $mk('C', $prop, '2.000,00', '60');
    $g = $mk('Arquivado', $prop, '9.999,00', '60');
    $x->arquivar('negocios', $g);

    $funil = Repositorios::negocios()->resumoPorEtapa((int) Repositorios::pipelines()->padrao()['id']);
    igual(['Novo lead', 'Qualificado', 'Proposta'], array_column($funil, 'nome'), 'só etapas abertas, na ordem, mesmo vazias');
    igual([2, 0, 1], array_column($funil, 'qtd'));
    igual([150000, 0, 200000], array_column($funil, 'valor'), 'centavos; arquivado não conta');
    igual([20000, 0, 120000], array_column($funil, 'ponderado'), '1000×10% + 500×20% = 200; 2000×60% = 1200');

    // Parado = mais de 14 dias na etapa
    $pdo->prepare('UPDATE negocios SET entrou_etapa_em = :d WHERE id = :i')->execute(['d' => date('Y-m-d H:i:s', strtotime('-20 days')), 'i' => $a]);
    $pdo->prepare('UPDATE negocios SET entrou_etapa_em = :d WHERE id = :i')->execute(['d' => date('Y-m-d H:i:s', strtotime('-14 days +1 hour')), 'i' => $b]);
    $pdo->prepare('UPDATE negocios SET entrou_etapa_em = :d WHERE id = :i')->execute(['d' => date('Y-m-d H:i:s', strtotime('-30 days')), 'i' => $g]);
    $parados = Repositorios::negocios()->parados(14);
    igual(1, $parados['total']);
    igual($a, (int) $parados['linhas'][0]['id']);

    // Ganho não é "parado"
    $x->moverEtapa($a, ['etapa_id' => etapaId('Ganho'), 'valor_fechado' => '1.000,00']);
    igual(0, Repositorios::negocios()->parados(14)['total']);

    // Contratos: assinados/ativos que terminam em até 30 dias
    $ct = static function (string $titulo, string $status, ?string $fim) use ($x, $pdo): int {
        $id = (int) $x->criar('contratos', ['titulo' => $titulo])->id;
        $pdo->prepare('UPDATE contratos SET status = :s, data_fim = :f WHERE id = :i')->execute(['s' => $status, 'f' => $fim, 'i' => $id]);
        return $id;
    };
    $perto = $ct('Perto', 'ativo', date('Y-m-d', strtotime('+10 days')));
    $hoje = $ct('Hoje', 'assinado', hoje());
    $ct('Longe', 'ativo', date('Y-m-d', strtotime('+90 days')));
    $ct('Passado', 'ativo', date('Y-m-d', strtotime('-1 day')));
    $ct('Rascunho', 'rascunho', date('Y-m-d', strtotime('+5 days')));
    $ct('Sem fim', 'ativo', null);
    $v = Repositorios::contratos()->vencendo(30);
    igual(2, $v['total']);
    igual([$hoje, $perto], array_map(static fn (array $l): int => (int) $l['id'], $v['linhas']), 'os que acabam antes primeiro');
});
