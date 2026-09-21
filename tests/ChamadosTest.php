<?php

declare(strict_types=1);

use App\Controllers\ChamadoController;
use App\Controllers\ConfiguracaoController;
use App\Controllers\EmpresaController;
use App\Controllers\TarefaController;
use App\Core\DB;
use App\Repositories\Repositorios;
use App\Repositories\SeedRepository;
use App\Services\ActionExecutor;
use App\Services\Checklist;
use App\Services\Opcoes;

// Fase 13 — chamados por área, checklist e a tela única de Tarefas e chamados.

/** Cria as áreas padrão no banco de teste e devolve nome => id. */
function areasDeTeste(): array
{
    (new SeedRepository(DB::conexao()))->garantirNomes('areas', ['Tráfego Pago', 'Design', 'Social Media', 'Web', 'Redação']);
    Opcoes::limpar();
    return array_flip(Opcoes::para('areas'));
}

function novoChamado(array $dados = []): int
{
    $areas = areasDeTeste();
    $r = (new ActionExecutor())->criar('chamados', $dados + ['titulo' => 'Criar campanha de leads', 'area_id' => $areas['Tráfego Pago']]);
    verdadeiro($r->ok, $r->mensagem . json_encode($r->erros));
    return (int) $r->id;
}

// ---- Checklist (serviço) -----------------------------------------------------------------------

teste('checklist: normaliza itens, ignora linhas vazias, valida limites e converte de/para o texto do formulário', function () {
    $erro = null;
    igual(null, Checklist::normalizar(null, $erro));
    igual(null, Checklist::normalizar([], $erro));
    igual('[{"texto":"Briefing","feito":0},{"texto":"Arte","feito":1}]', Checklist::normalizar([['texto' => ' Briefing ', 'feito' => 0], ['texto' => 'Arte', 'feito' => '1'], ['texto' => '   ']], $erro));
    igual('[{"texto":"a","feito":0}]', Checklist::normalizar(['a'], $erro), 'aceita lista de textos');
    igual('[{"texto":"x","feito":1}]', Checklist::normalizar('[{"texto":"x","feito":true}]', $erro), 'aceita JSON');

    igual(false, Checklist::normalizar(array_fill(0, Checklist::MAX_ITENS + 1, 'i'), $erro));
    contem('no máximo', (string) $erro);
    igual(false, Checklist::normalizar([str_repeat('x', Checklist::MAX_TEXTO + 1)], $erro));
    contem('até', (string) $erro);
    igual(false, Checklist::normalizar(['a' => 'b'], $erro), 'objeto não é lista');
    igual(false, Checklist::normalizar('não é json', $erro));

    $itens = Checklist::deTexto("[x] Briefing aprovado\n[ ] Criar arte\nRevisar\n\n  [X]  Publicar  \n");
    igual([['texto' => 'Briefing aprovado', 'feito' => 1], ['texto' => 'Criar arte', 'feito' => 0], ['texto' => 'Revisar', 'feito' => 0], ['texto' => 'Publicar', 'feito' => 1]], $itens);
    $json = Checklist::normalizar($itens, $erro);
    igual("[x] Briefing aprovado\n[ ] Criar arte\n[ ] Revisar\n[x] Publicar", Checklist::paraTexto($json), 'ida e volta');
    igual(['feitos' => 2, 'total' => 4], Checklist::progresso($json));
    igual(['feitos' => 0, 'total' => 0], Checklist::progresso(null));
    igual([], Checklist::itens('lixo'));
});

// ---- Entidade: criar, validar, código, concluir ---------------------------------------------------

teste('chamado: cria com código CH-AAAA-NNNN, padrões, área obrigatória e checklist em JSON; audita', function () {
    bancoComSeed();
    $areas = areasDeTeste();
    igual(['Design', 'Redação', 'Social Media', 'Tráfego Pago', 'Web'], array_keys($areas), 'áreas em ordem alfabética');
    $x = new ActionExecutor();
    $emp = (int) $x->criar('empresas', ['nome_fantasia' => 'Padaria Sol'])->id;

    $id = novoChamado(['empresa_id' => $emp, 'checklist' => [['texto' => 'Briefing', 'feito' => 0], ['texto' => 'Criativos', 'feito' => 1]], 'vencimento' => '2026-10-05 15:00']);
    $c = Repositorios::chamados()->encontrar($id);
    igual('CH-' . date('Y') . '-0001', $c['codigo']);
    igual('aberto', $c['status']);
    igual('media', $c['prioridade']);
    igual('Tráfego Pago', $c['area_nome']);
    igual('Padaria Sol', $c['empresa_nome']);
    igual('2026-10-05 15:00:00', $c['vencimento']);
    igual(null, $c['concluido_em']);
    igual(['feitos' => 1, 'total' => 2], Checklist::progresso($c['checklist']));
    igual('CH-' . date('Y') . '-0002', Repositorios::chamados()->encontrar(novoChamado(['titulo' => 'Segundo']))['codigo']);

    $log = DB::conexao()->query("SELECT * FROM log_auditoria WHERE entidade = 'chamados' AND registro_id = {$id}")->fetchAll();
    igual(1, count($log));
    igual('criar', $log[0]['acao']);

    $sem = $x->criar('chamados', ['titulo' => 'Sem área']);
    verdadeiro(!$sem->ok);
    verdadeiro(isset($sem->erros['area_id']));
    verdadeiro(!$x->criar('chamados', ['titulo' => 'x', 'area_id' => $areas['Web'], 'status' => 'feito'])->ok, 'status inválido');
    verdadeiro(!$x->criar('chamados', ['titulo' => 'x', 'area_id' => $areas['Web'], 'prioridade' => 'máxima'])->ok, 'prioridade inválida');
    verdadeiro(!$x->criar('chamados', ['titulo' => 'x', 'area_id' => 9999])->ok, 'área inexistente');
    verdadeiro(!$x->criar('chamados', ['titulo' => 'x', 'area_id' => $areas['Web'], 'codigo' => 'CH-9'])->ok, 'código é do servidor');
    verdadeiro(!$x->criar('chamados', ['titulo' => 'x', 'area_id' => $areas['Web'], 'concluido_em' => '2026-01-01'])->ok, 'concluído_em é do servidor');
    $ruim = $x->criar('chamados', ['titulo' => 'x', 'area_id' => $areas['Web'], 'checklist' => 'isto não é uma lista']);
    verdadeiro(!$ruim->ok);
    verdadeiro(isset($ruim->erros['checklist']));
});

teste('chamado: concluir carimba a data, reabrir limpa, cancelar não carimba; Desfazer restaura', function () {
    bancoComSeed();
    $id = novoChamado();
    $x = new ActionExecutor();
    igual(null, Repositorios::chamados()->encontrar($id)['concluido_em']);

    verdadeiro($x->mudarStatusChamado($id, 'andamento')->ok);
    igual('andamento', Repositorios::chamados()->encontrar($id)['status']);
    verdadeiro($x->mudarStatusChamado($id, 'aguardando')->ok);
    igual(null, Repositorios::chamados()->encontrar($id)['concluido_em']);

    verdadeiro($x->mudarStatusChamado($id, 'concluido', 'Campanha no ar, 3 anúncios')->ok);
    $c = Repositorios::chamados()->encontrar($id);
    igual('concluido', $c['status']);
    igual('Campanha no ar, 3 anúncios', $c['resolucao']);
    verdadeiro($c['concluido_em'] !== null);
    verdadeiro(!chamado_aberto($c));

    verdadeiro($x->mudarStatusChamado($id, 'aberto')->ok);
    $c = Repositorios::chamados()->encontrar($id);
    igual(null, $c['concluido_em'], 'reabrir limpa a data');
    igual('Campanha no ar, 3 anúncios', $c['resolucao'], 'a resolução fica no histórico');

    $x->mudarStatusChamado($id, 'cancelado');
    igual(null, Repositorios::chamados()->encontrar($id)['concluido_em'], 'cancelar não é concluir');
    verdadeiro(!$x->mudarStatusChamado($id, 'inexistente')->ok);

    $x->mudarStatusChamado($id, 'concluido');
    $desfeito = $x->desfazer();
    verdadeiro($desfeito->ok, $desfeito->mensagem);
    $c = Repositorios::chamados()->encontrar($id);
    igual('cancelado', $c['status']);
    igual(null, $c['concluido_em'], 'desfazer devolve status e data juntos');
    $acoes = array_column(DB::conexao()->query("SELECT acao FROM log_auditoria WHERE entidade = 'chamados' ORDER BY id")->fetchAll(), 'acao');
    verdadeiro(in_array('mudar_status', $acoes, true));
});

teste('chamado: criar já concluído carimba a data; arquivar é soft delete', function () {
    bancoComSeed();
    $id = novoChamado(['status' => 'concluido']);
    verdadeiro(Repositorios::chamados()->encontrar($id)['concluido_em'] !== null);
    $r = (new ActionExecutor())->arquivar('chamados', $id);
    verdadeiro($r->ok);
    igual(null, Repositorios::chamados()->encontrar($id), 'arquivado some das buscas');
    igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM chamados')->fetchColumn(), 'a linha continua no banco');
});

// ---- Checklist (ações) --------------------------------------------------------------------------

teste('checklist: alternar, adicionar e remover itens passam pelo ActionExecutor, com auditoria e Desfazer', function () {
    bancoComSeed();
    $id = novoChamado(['checklist' => [['texto' => 'Briefing', 'feito' => 0], ['texto' => 'Arte', 'feito' => 0]]]);
    $x = new ActionExecutor();
    $itens = static fn (): array => Checklist::itens(Repositorios::chamados()->encontrar($id)['checklist']);

    verdadeiro($x->alternarItemChecklist($id, 0)->ok);
    igual(1, $itens()[0]['feito']);
    verdadeiro($x->alternarItemChecklist($id, 0)->ok);
    igual(0, $itens()[0]['feito'], 'alterna de volta');

    verdadeiro($x->adicionarItemChecklist($id, 'Publicar')->ok);
    igual(['Briefing', 'Arte', 'Publicar'], array_column($itens(), 'texto'));
    verdadeiro(!$x->adicionarItemChecklist($id, '   ')->ok || count($itens()) === 3, 'item vazio não entra');
    verdadeiro(!$x->adicionarItemChecklist($id, str_repeat('x', 300))->ok, 'item longo demais');

    verdadeiro($x->removerItemChecklist($id, 1)->ok);
    igual(['Briefing', 'Publicar'], array_column($itens(), 'texto'));
    verdadeiro(!$x->alternarItemChecklist($id, 9)->ok, 'índice inexistente');
    verdadeiro(!$x->removerItemChecklist($id, 9)->ok);
    verdadeiro(!$x->alternarItemChecklist(99999, 0)->ok, 'chamado inexistente');

    $acoes = array_column(DB::conexao()->query("SELECT acao FROM log_auditoria WHERE entidade = 'chamados' ORDER BY id")->fetchAll(), 'acao');
    igual(['criar', 'checklist_alternar', 'checklist_alternar', 'checklist_adicionar', 'checklist_remover'], $acoes);
    $x->desfazer();
    igual(['Briefing', 'Arte', 'Publicar'], array_column($itens(), 'texto'), 'desfazer a remoção');

    $ultimo = $x->adicionarItemChecklist($id, 'Ultimo');
    for ($i = 0; $i < 60; $i++) {
        $x->adicionarItemChecklist($id, "item {$i}");
    }
    igual(Checklist::MAX_ITENS, count($itens()), 'limite de 50 itens');
});

// ---- Áreas (configuração) -----------------------------------------------------------------------

teste('áreas: cadastro em Configurações, nome único entre as ativas; arquivar não apaga o nome nos chamados existentes', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $r = $x->criar('areas', ['nome' => 'Audiovisual']);
    verdadeiro($r->ok, json_encode($r->erros));
    verdadeiro(!$x->criar('areas', ['nome' => 'Audiovisual'])->ok, 'nome único');
    $id = novoChamado(['area_id' => (int) $r->id]);
    verdadeiro($x->arquivar('areas', (int) $r->id)->ok);
    Opcoes::limpar();
    igual('Audiovisual', Repositorios::chamados()->encontrar($id)['area_nome'], 'o chamado continua mostrando a área arquivada');

    $_GET = ['aba' => 'areas'];
    $tela = (new ConfiguracaoController())->index()->corpo;
    contem('Áreas (chamados)', $tela);
    contem('Tráfego Pago', $tela);
    $_GET = [];
});

// ---- Tela de Tarefas e chamados -----------------------------------------------------------------

teste('tela: tarefas e chamados aparecem juntos nos mesmos grupos, ordenados pelo prazo, com contagens somadas', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $hoje = hoje();
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $amanha = date('Y-m-d', strtotime('+1 day'));
    $x->criar('tarefas', ['titulo' => 'Ligar para o cliente', 'vencimento' => $hoje . ' 16:00']);
    $x->criar('tarefas', ['titulo' => 'Tarefa atrasada', 'vencimento' => $ontem]);
    novoChamado(['titulo' => 'Arte do post de hoje', 'vencimento' => $hoje . ' 10:00', 'area_id' => areasDeTeste()['Design']]);
    novoChamado(['titulo' => 'Chamado atrasado', 'vencimento' => $ontem]);
    novoChamado(['titulo' => 'Chamado de amanhã', 'vencimento' => $amanha]);
    novoChamado(['titulo' => 'Chamado sem prazo']);
    novoChamado(['titulo' => 'Chamado fechado', 'status' => 'concluido', 'vencimento' => $hoje]);
    $ctl = new TarefaController();

    $_GET = [];
    $hojeHtml = $ctl->index()->corpo;
    contem('Tarefas e chamados', $hojeHtml);
    contem('Arte do post de hoje', $hojeHtml);
    contem('Ligar para o cliente', $hojeHtml);
    verdadeiro(strpos($hojeHtml, 'Arte do post de hoje') < strpos($hojeHtml, 'Ligar para o cliente'), 'ordenado pelo prazo (10:00 antes de 16:00)');
    verdadeiro(!str_contains($hojeHtml, 'Chamado fechado'), 'concluído não entra em "hoje"');
    verdadeiro(!str_contains($hojeHtml, 'Chamado de amanhã'));
    contem('Hoje (2)', $hojeHtml);
    contem('Atrasadas (2)', $hojeHtml);
    contem('Próximos 7 dias (1)', $hojeHtml);
    contem('Todas (7)', $hojeHtml);

    $_GET = ['visao' => 'atrasadas'];
    $atrasadas = $ctl->index()->corpo;
    contem('Tarefa atrasada', $atrasadas);
    contem('Chamado atrasado', $atrasadas);

    $_GET = ['visao' => 'todas'];
    $todas = $ctl->index()->corpo;
    contem('Chamado sem prazo', $todas);
    verdadeiro(strpos($todas, 'Chamado sem prazo') < strpos($todas, 'Chamado fechado'), 'abertos antes dos fechados');
    $_GET = [];
});

teste('tela: filtro por tipo e por área (área implica só chamados) mantém as contagens coerentes e os links', function () {
    bancoComSeed();
    $areas = areasDeTeste();
    $x = new ActionExecutor();
    $hoje = hoje();
    $x->criar('tarefas', ['titulo' => 'Tarefa do dia', 'vencimento' => $hoje]);
    novoChamado(['titulo' => 'Design do dia', 'vencimento' => $hoje, 'area_id' => $areas['Design']]);
    novoChamado(['titulo' => 'Web do dia', 'vencimento' => $hoje, 'area_id' => $areas['Web']]);
    $ctl = new TarefaController();

    $_GET = ['tipo' => 'tarefas'];
    $so = $ctl->index()->corpo;
    contem('Tarefa do dia', $so);
    verdadeiro(!str_contains($so, 'Design do dia'));
    contem('Hoje (1)', $so);
    contem('tipo=tarefas', $so, 'a aba mantém o filtro');

    $_GET = ['tipo' => 'chamados'];
    $ch = $ctl->index()->corpo;
    verdadeiro(!str_contains($ch, 'Tarefa do dia'));
    contem('Hoje (2)', $ch);

    $_GET = ['area' => (string) $areas['Design'], 'tipo' => 'tarefas'];
    $area = $ctl->index()->corpo;
    contem('Design do dia', $area);
    verdadeiro(!str_contains($area, 'Web do dia'));
    verdadeiro(!str_contains($area, 'Tarefa do dia'), 'com área, tarefa não aparece (não tem área)');
    contem('Hoje (1)', $area);
    contem('area=' . $areas['Design'], $area);

    $_GET = ['area' => '9999', 'tipo' => 'lixo', 'visao' => 'xyz'];
    igual(200, $ctl->index()->status, 'valores inválidos são ignorados');
    $_GET = [];
});

teste('tela: concluir pela lista e pelo detalhe; checklist com progresso; formulário salva prazo, checklist em texto e resolução', function () {
    bancoComSeed();
    $areas = areasDeTeste();
    $x = new ActionExecutor();
    $emp = (int) $x->criar('empresas', ['nome_fantasia' => 'Padaria Sol'])->id;
    $id = novoChamado(['empresa_id' => $emp, 'checklist' => [['texto' => 'Briefing', 'feito' => 1], ['texto' => 'Arte', 'feito' => 0]], 'vencimento' => hoje(), 'descricao' => 'Peça para o Dia das Crianças']);
    $ctl = new ChamadoController();

    $det = $ctl->mostrar(['id' => (string) $id])->corpo;
    contem('CH-' . date('Y') . '-0001', $det);
    contem('Padaria Sol', $det);
    contem('Peça para o Dia das Crianças', $det);
    contem('1 de 2 feito(s) · 50%', $det);
    contem('/chamados/' . $id . '/checklist/1/alternar', $det);
    contem('Concluir chamado', $det);
    contem('Histórico', $det);
    igual(404, $ctl->mostrar(['id' => '99999'])->status);

    // Formulário de edição mostra o checklist como texto e o prazo em data/hora.
    $form = $ctl->editar(['id' => (string) $id])->corpo;
    contem('[x] Briefing', $form);
    contem('[ ] Arte', $form);
    contem('name="vencimento_data"', $form);

    $_POST = ['titulo' => 'Peça do Dia das Crianças', 'area_id' => (string) $areas['Design'], 'prioridade' => 'alta', 'status' => 'andamento',
        'vencimento_data' => '2026-10-10', 'vencimento_hora' => '18:30', 'checklist_texto' => "[x] Briefing\n[x] Arte\n[ ] Aprovação do cliente", 'resolucao' => ''];
    $r = $ctl->atualizar(['id' => (string) $id]);
    igual(302, $r->status);
    $c = Repositorios::chamados()->encontrar($id);
    igual('Design', $c['area_nome']);
    igual('alta', $c['prioridade']);
    igual('2026-10-10 18:30:00', $c['vencimento']);
    igual(['feitos' => 2, 'total' => 3], Checklist::progresso($c['checklist']));

    // Ações da página.
    $_POST = ['status' => 'concluido', 'resolucao' => 'Entregue e aprovado', 'voltar' => '/tarefas?visao=hoje'];
    $ctl->status(['id' => (string) $id]);
    igual('concluido', Repositorios::chamados()->encontrar($id)['status']);
    igual('Entregue e aprovado', Repositorios::chamados()->encontrar($id)['resolucao']);
    $_POST = ['texto' => 'Enviar relatório'];
    $ctl->adicionarItem(['id' => (string) $id]);
    igual(4, Checklist::progresso(Repositorios::chamados()->encontrar($id)['checklist'])['total']);
    $_POST = [];
    $ctl->alternarItem(['id' => (string) $id, 'indice' => '2']);
    igual(3, Checklist::progresso(Repositorios::chamados()->encontrar($id)['checklist'])['feitos']);
    $ctl->removerItem(['id' => (string) $id, 'indice' => '3']);
    igual(3, Checklist::progresso(Repositorios::chamados()->encontrar($id)['checklist'])['total']);
    $_POST = ['status' => 'inventado'];
    $ctl->status(['id' => (string) $id]);
    igual('concluido', Repositorios::chamados()->encontrar($id)['status'], 'status inválido não muda nada');
    $_POST = [];

    $nova = $ctl->novo()->corpo;
    contem('Checklist', $nova);
    $_POST = ['titulo' => 'Sem área'];
    igual(422, $ctl->criar()->status);
    $_POST = [];
});

teste('lista de chamados: filtros por área, status e situação; busca; ordenação por prioridade', function () {
    bancoComSeed();
    $areas = areasDeTeste();
    novoChamado(['titulo' => 'Post da semana', 'area_id' => $areas['Social Media'], 'prioridade' => 'baixa']);
    novoChamado(['titulo' => 'Landing page', 'area_id' => $areas['Web'], 'prioridade' => 'urgente']);
    novoChamado(['titulo' => 'Artigo do blog', 'area_id' => $areas['Redação'], 'status' => 'concluido']);
    $repo = Repositorios::chamados();

    igual(3, $repo->listar([])['total']);
    igual(['Landing page'], array_column($repo->listar(['filtros' => ['area_id' => (string) $areas['Web']]])['linhas'], 'titulo'));
    igual(2, $repo->listar(['filtros' => ['situacao' => 'abertos']])['total']);
    igual(['Artigo do blog'], array_column($repo->listar(['filtros' => ['situacao' => 'fechados']])['linhas'], 'titulo'));
    igual(['Artigo do blog'], array_column($repo->listar(['filtros' => ['status' => 'concluido']])['linhas'], 'titulo'));
    igual(['Landing page'], array_column($repo->listar(['busca' => 'landing'])['linhas'], 'titulo'));
    igual(['Landing page', 'Artigo do blog', 'Post da semana'], array_column($repo->listar(['ordem' => 'prioridade', 'dir' => 'desc'])['linhas'], 'titulo'), 'urgente > média > baixa');

    $_GET = [];
    $lista = (new ChamadoController())->index()->corpo;
    contem('Landing page', $lista);
    contem('CH-' . date('Y') . '-0001', $lista);
    contem('Social Media', $lista);
});

teste('empresa: aba Chamados lista os chamados da empresa e abre um novo já vinculado; anexos aceitam chamado', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = (int) $x->criar('empresas', ['nome_fantasia' => 'Padaria Sol'])->id;
    $outra = (int) $x->criar('empresas', ['nome_fantasia' => 'Outra'])->id;
    novoChamado(['titulo' => 'Chamado da Padaria', 'empresa_id' => $emp]);
    novoChamado(['titulo' => 'Chamado de outra', 'empresa_id' => $outra]);

    $detalhe = (new EmpresaController())->mostrar(['id' => (string) $emp])->corpo;
    contem('Chamados', $detalhe);
    contem('Chamado da Padaria', $detalhe);
    verdadeiro(!str_contains($detalhe, 'Chamado de outra'));
    contem('chamados/nova?empresa_id=' . $emp, $detalhe);

    $_GET = ['empresa_id' => (string) $emp];
    contem('Padaria Sol', (new ChamadoController())->novo()->corpo, 'novo chamado já vem com a empresa');
    $_GET = [];
    igual('Chamado', \App\Services\Schema::opcoes('entidade_anexo')['chamados']);
});

teste('início: o card de hoje junta tarefas e chamados atrasados e do dia, ordenados pelo prazo', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $x->criar('tarefas', ['titulo' => 'Tarefa de hoje', 'vencimento' => hoje() . ' 15:00']);
    novoChamado(['titulo' => 'Chamado de hoje', 'vencimento' => hoje() . ' 09:00']);
    novoChamado(['titulo' => 'Chamado atrasado', 'vencimento' => date('Y-m-d', strtotime('-2 days'))]);
    novoChamado(['titulo' => 'Chamado de amanhã', 'vencimento' => date('Y-m-d', strtotime('+1 day'))]);

    $corpo = (new \App\Controllers\PaginaController())->inicio()->corpo;
    contem('Tarefas e chamados de hoje', $corpo);
    contem('1 atrasada(s) · 2 para hoje', $corpo);
    contem('Chamado atrasado', $corpo);
    verdadeiro(strpos($corpo, 'Chamado de hoje') < strpos($corpo, 'Tarefa de hoje'), 'chamado das 9h antes da tarefa das 15h');
    verdadeiro(!str_contains($corpo, 'Chamado de amanhã'));
});
