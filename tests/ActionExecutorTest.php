<?php

declare(strict_types=1);

use App\Core\DB;
use App\Repositories\AuditoriaRepository;
use App\Repositories\Repositorios;
use App\Repositories\SeedRepository;
use App\Services\ActionExecutor;
use App\Services\Events;
use App\Services\Opcoes;

/** Banco em memória com migrações e seed (pipeline Vendas, origens e motivos de perda). */
function bancoComSeed(): PDO
{
    $pdo = bancoDeTeste();
    $seed = new SeedRepository($pdo);
    $seed->garantirPipeline('Vendas', [
        ['Novo lead', 10, '#64748b', 'aberta'], ['Qualificado', 25, '#0ea5e9', 'aberta'], ['Proposta', 60, '#f59e0b', 'aberta'],
        ['Ganho', 100, '#22c55e', 'ganho'], ['Perdido', 0, '#ef4444', 'perdido'],
    ]);
    $seed->garantirNomes('origens', ['Indicação', 'Site']);
    $seed->garantirNomes('motivos_perda', ['Preço', 'Prazo']);
    Events::limpar();
    Opcoes::limpar();
    return $pdo;
}

/** Registra os eventos disparados e devolve a lista (nomes) por referência. */
function capturarEventos(array &$lista): void
{
    foreach (Events::CONHECIDOS as $nome) {
        Events::ouvir($nome, function (array $p) use (&$lista, $nome) {
            $lista[] = $nome;
        });
    }
}

function etapaId(string $nome): int
{
    foreach (Repositorios::etapas()->todas() as $e) {
        if ($e['nome'] === $nome) {
            return (int) $e['id'];
        }
    }
    throw new RuntimeException("Etapa {$nome} não existe no seed de teste");
}

function novaEmpresa(ActionExecutor $x, string $nome = 'Padaria Central', array $extra = []): int
{
    $r = $x->criar('empresas', ['nome_fantasia' => $nome] + $extra);
    verdadeiro($r->ok, 'criar empresa: ' . $r->mensagem);
    return (int) $r->id;
}

function novoNegocio(ActionExecutor $x, array $dados = []): int
{
    $r = $x->criar('negocios', $dados + ['titulo' => 'Site novo', 'valor_estimado' => '8.000,00']);
    verdadeiro($r->ok, 'criar negócio: ' . $r->mensagem . json_encode($r->erros));
    return (int) $r->id;
}

teste('ActionExecutor: cria empresa com padrões, audita e dispara empresa.criada', function () {
    $pdo = bancoComSeed();
    $eventos = [];
    capturarEventos($eventos);
    $x = new ActionExecutor();

    $r = $x->criar('empresas', ['nome_fantasia' => '  Padaria Central  ', 'email_geral' => 'CONTATO@Padaria.com.br', 'ticket_potencial' => '12.500,50']);
    verdadeiro($r->ok);
    $e = Repositorios::empresas()->encontrar((int) $r->id);
    igual('Padaria Central', $e['nome_fantasia'], 'aparado');
    igual('lead', $e['status'], 'status padrão');
    igual('Brasil', $e['pais']);
    igual('contato@padaria.com.br', $e['email_geral'], 'e-mail minúsculo');
    igual(1250050, $e['ticket_potencial'], 'reais → centavos');
    igual('humano', $e['criado_por']);
    verdadeiro($e['criado_em'] !== '' && $e['atualizado_em'] !== '');
    igual(['empresa.criada'], $eventos);

    $log = $pdo->query('SELECT * FROM log_auditoria')->fetch();
    igual('criar', $log['acao']);
    igual('empresas', $log['entidade']);
    igual((int) $r->id, (int) $log['registro_id']);
    igual('humano', $log['origem']);
    igual('Padaria Central', json_decode($log['depois'], true)['nome_fantasia']);
    verdadeiro($log['antes'] === null);
});

teste('ActionExecutor: valida obrigatórios, tipos e campos fora da whitelist', function () {
    bancoComSeed();
    $x = new ActionExecutor();

    $r = $x->criar('empresas', []);
    verdadeiro(!$r->ok);
    contem('obrigatório', $r->erros['nome_fantasia']);

    $r = $x->criar('empresas', ['nome_fantasia' => 'A', 'status' => 'xpto', 'email_geral' => 'x', 'uf' => 'SPP', 'cnpj' => '11111111111111']);
    verdadeiro(!$r->ok);
    foreach (['status', 'email_geral', 'uf', 'cnpj'] as $campo) {
        verdadeiro(isset($r->erros[$campo]), "esperava erro em {$campo}");
    }

    $r = $x->criar('empresas', ['nome_fantasia' => 'A', 'id' => 99, 'criado_por' => 'ia', 'arquivado_em' => '2020-01-01', 'campo_inexistente' => 'x']);
    verdadeiro(!$r->ok);
    contem('não permitido', $r->erros['id']);
    contem('não permitido', $r->erros['criado_por']);
    contem('não permitido', $r->erros['arquivado_em']);
    contem('não permitido', $r->erros['campo_inexistente']);

    $r = $x->criar('negocios', ['titulo' => 'X', 'codigo' => 'NEG-2000-0001', 'status' => 'ganho']);
    verdadeiro(!$r->ok);
    contem('não permitido', $r->erros['codigo'], 'campos controlados pelo servidor');

    verdadeiro(!$x->criar('tabela_qualquer', ['a' => 1])->ok, 'entidade fora da whitelist');
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM empresas')->fetchColumn(), 'nada foi gravado');
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM log_auditoria')->fetchColumn(), 'nada foi auditado');
});

teste('ActionExecutor: CNPJ é normalizado, validado e único entre ativas', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $id = novaEmpresa($x, 'A', ['cnpj' => '11.222.333/0001-81']);
    igual('11222333000181', Repositorios::empresas()->encontrar($id)['cnpj']);

    $r = $x->criar('empresas', ['nome_fantasia' => 'B', 'cnpj' => '11222333000181']);
    verdadeiro(!$r->ok);
    contem('Já existe', $r->erros['cnpj']);

    $x->arquivar('empresas', $id);
    verdadeiro($x->criar('empresas', ['nome_fantasia' => 'B', 'cnpj' => '11222333000181'])->ok, 'CNPJ de arquivada pode ser reutilizado');
});

teste('ActionExecutor: atualizar audita só o que mudou e ignora alterações vazias', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    $id = novaEmpresa($x);

    $r = $x->atualizar('empresas', $id, ['segmento' => 'Alimentação', 'nome_fantasia' => 'Padaria Central']);
    verdadeiro($r->ok);
    $log = $pdo->query("SELECT * FROM log_auditoria WHERE acao = 'atualizar'")->fetch();
    igual(['segmento' => null], json_decode($log['antes'], true));
    igual(['segmento' => 'Alimentação'], json_decode($log['depois'], true));

    $r = $x->atualizar('empresas', $id, ['segmento' => 'Alimentação']);
    verdadeiro($r->ok);
    igual('Nada a alterar.', $r->mensagem);
    igual(1, (int) $pdo->query("SELECT COUNT(*) FROM log_auditoria WHERE acao = 'atualizar'")->fetchColumn(), 'sem novo log');

    $r = $x->atualizar('empresas', $id, ['nome_fantasia' => '']);
    verdadeiro(!$r->ok, 'obrigatório não pode ser esvaziado');
    verdadeiro(!$x->atualizar('empresas', 9999, ['segmento' => 'x'])->ok, 'registro inexistente');
});

teste('ActionExecutor: arquivar faz soft delete (sem DELETE físico) e some das listas', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    $id = novaEmpresa($x);

    $r = $x->arquivar('empresas', $id);
    verdadeiro($r->ok);
    igual(null, Repositorios::empresas()->encontrar($id), 'não aparece nos ativos');
    verdadeiro(Repositorios::empresas()->encontrar($id, true)['arquivado_em'] !== null, 'a linha continua no banco');
    igual(0, Repositorios::empresas()->listar()['total']);
    igual(1, (int) $pdo->query("SELECT COUNT(*) FROM log_auditoria WHERE acao = 'arquivar'")->fetchColumn());
    verdadeiro(!$x->arquivar('empresas', $id)->ok, 'não arquiva duas vezes');
});

teste('ActionExecutor: desfazer reverte criar, atualizar e arquivar e é auditado', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    $id = novaEmpresa($x);

    // desfazer atualizar
    $x->atualizar('empresas', $id, ['segmento' => 'Alimentação', 'classificacao' => 'A']);
    $r = $x->desfazer();
    verdadeiro($r->ok, $r->mensagem);
    $e = Repositorios::empresas()->encontrar($id);
    igual(null, $e['segmento']);
    igual(null, $e['classificacao']);

    // desfazer arquivar
    $x->arquivar('empresas', $id);
    verdadeiro($x->desfazer()->ok);
    verdadeiro(Repositorios::empresas()->encontrar($id) !== null, 'voltou a ficar ativa');

    // desfazer criar → arquiva
    $r = $x->desfazer();
    verdadeiro($r->ok, $r->mensagem);
    igual(null, Repositorios::empresas()->encontrar($id), 'criação desfeita = arquivada');

    // tudo desfeito
    $r = $x->desfazer();
    verdadeiro(!$r->ok);
    igual('Não há ação para desfazer.', $r->mensagem);

    igual(3, (int) $pdo->query("SELECT COUNT(*) FROM log_auditoria WHERE acao = 'desfazer'")->fetchColumn(), 'cada desfazer é auditado');
});

teste('ActionExecutor: desfazer por id, não repete e não desfaz um desfazer', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    $id = novaEmpresa($x);
    $x->atualizar('empresas', $id, ['segmento' => 'A']);
    $x->atualizar('empresas', $id, ['segmento' => 'B']);

    $primeiro = (int) $pdo->query("SELECT id FROM log_auditoria WHERE acao = 'atualizar' ORDER BY id LIMIT 1")->fetchColumn();
    $r = $x->desfazer($primeiro);
    verdadeiro($r->ok);
    igual(null, Repositorios::empresas()->encontrar($id)['segmento'], 'restaurou o valor anterior ao primeiro update');
    verdadeiro(!$x->desfazer($primeiro)->ok, 'já desfeita');

    $logDesfazer = (int) $pdo->query("SELECT id FROM log_auditoria WHERE acao = 'desfazer'")->fetchColumn();
    $r = $x->desfazer($logDesfazer);
    verdadeiro(!$r->ok);
    contem('não pode ser desfeito', $r->mensagem);
});

teste('ActionExecutor: novo negócio recebe código sequencial, etapa inicial, probabilidade e atividade de sistema', function () {
    $pdo = bancoComSeed();
    $eventos = [];
    capturarEventos($eventos);
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);

    $a = novoNegocio($x, ['empresa_id' => $emp]);
    $b = novoNegocio($x, ['titulo' => 'Outro']);
    $n = Repositorios::negocios()->encontrar($a);
    $ano = date('Y');
    igual("NEG-{$ano}-0001", $n['codigo']);
    igual("NEG-{$ano}-0002", Repositorios::negocios()->encontrar($b)['codigo']);
    igual('Novo lead', $n['etapa_nome']);
    igual(10, $n['probabilidade']);
    igual('aberto', $n['status']);
    igual(800000, $n['valor_estimado']);
    verdadeiro($n['pipeline_id'] !== null && $n['entrou_etapa_em'] !== null);
    igual(80000, (int) $n['valor_ponderado'], 'valor ponderado = estimado × probabilidade');
    verdadeiro(in_array('negocio.criado', $eventos, true));

    $atv = $pdo->query("SELECT * FROM atividades WHERE negocio_id = {$a}")->fetch();
    igual('sistema', $atv['tipo']);
    igual($emp, (int) $atv['empresa_id']);
    igual('sistema', $atv['criado_por']);

    $x->arquivar('negocios', $b);
    $c = novoNegocio($x, ['titulo' => 'Terceiro']);
    igual("NEG-{$ano}-0003", Repositorios::negocios()->encontrar($c)['codigo'], 'código de arquivado não é reutilizado');
});

teste('ActionExecutor: mover etapa aberta atualiza probabilidade, dias na etapa, atividade e evento', function () {
    $pdo = bancoComSeed();
    $eventos = [];
    $x = new ActionExecutor();
    $id = novoNegocio($x);
    capturarEventos($eventos);

    $r = $x->moverEtapa($id, ['etapa_id' => etapaId('Proposta')]);
    verdadeiro($r->ok, $r->mensagem);
    $n = Repositorios::negocios()->encontrar($id);
    igual('Proposta', $n['etapa_nome']);
    igual(60, $n['probabilidade']);
    igual('aberto', $n['status']);
    igual(['negocio.etapa_mudou'], $eventos);

    $atv = $pdo->query("SELECT descricao FROM atividades WHERE negocio_id = {$id} AND assunto = 'Etapa alterada'")->fetchColumn();
    igual('Novo lead → Proposta', $atv);
    $log = $pdo->query("SELECT acao FROM log_auditoria WHERE acao = 'mover_etapa'")->fetchColumn();
    igual('mover_etapa', $log);

    $r = $x->moverEtapa($id, ['etapa_id' => etapaId('Proposta')]);
    igual('Nada a alterar.', $r->mensagem, 'mesma etapa não gera atividade');
    verdadeiro(!$x->moverEtapa($id, [])->ok, 'etapa é obrigatória');
    verdadeiro(!$x->moverEtapa($id, ['etapa_id' => 9999])->ok, 'etapa inexistente');
});

teste('ActionExecutor: ganho exige valor fechado; ao ganhar define status, data e evento', function () {
    $pdo = bancoComSeed();
    $eventos = [];
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    $id = novoNegocio($x, ['empresa_id' => $emp]);
    capturarEventos($eventos);
    $ganho = etapaId('Ganho');

    $r = $x->moverEtapa($id, ['etapa_id' => $ganho]);
    verdadeiro(!$r->ok);
    contem('valor fechado', $r->erros['valor_fechado']);
    $n = Repositorios::negocios()->encontrar($id);
    igual('Novo lead', $n['etapa_nome'], 'nada mudou (rollback)');
    igual('aberto', $n['status']);
    igual([], $eventos, 'nenhum evento em caso de falha');
    igual(0, (int) $pdo->query("SELECT COUNT(*) FROM log_auditoria WHERE acao = 'mover_etapa'")->fetchColumn(), 'nada auditado');

    $r = $x->moverEtapa($id, ['etapa_id' => $ganho, 'valor_fechado' => '7.500,00']);
    verdadeiro($r->ok, $r->mensagem . json_encode($r->erros));
    $n = Repositorios::negocios()->encontrar($id);
    igual('ganho', $n['status']);
    igual(750000, $n['valor_fechado']);
    igual(hoje(), $n['data_fechamento']);
    igual(100, $n['probabilidade']);
    igual(['negocio.etapa_mudou', 'negocio.ganho'], $eventos);
    igual(750000, (int) Repositorios::empresas()->encontrar($emp)['ltv'], 'LTV soma os negócios ganhos');

    $desc = $pdo->query("SELECT descricao FROM atividades WHERE negocio_id = {$id} AND assunto = 'Etapa alterada'")->fetchColumn();
    contem('R$ 7.500,00', $desc);
});

teste('ActionExecutor: perda exige motivo; ao perder define status e evento', function () {
    bancoComSeed();
    $eventos = [];
    $x = new ActionExecutor();
    $id = novoNegocio($x);
    capturarEventos($eventos);
    $perdido = etapaId('Perdido');

    $r = $x->moverEtapa($id, ['etapa_id' => $perdido]);
    verdadeiro(!$r->ok);
    contem('motivo', $r->erros['motivo_perda_id']);
    verdadeiro(!$x->moverEtapa($id, ['etapa_id' => $perdido, 'motivo_perda_id' => 9999])->ok, 'motivo inexistente');

    $motivo = (int) DB::conexao()->query("SELECT id FROM motivos_perda WHERE nome = 'Preço'")->fetchColumn();
    $r = $x->moverEtapa($id, ['etapa_id' => $perdido, 'motivo_perda_id' => $motivo, 'detalhe_perda' => 'Achou caro']);
    verdadeiro($r->ok, json_encode($r->erros));
    $n = Repositorios::negocios()->encontrar($id);
    igual('perdido', $n['status']);
    igual($motivo, $n['motivo_perda_id']);
    igual(hoje(), $n['data_fechamento']);
    igual(0, $n['probabilidade']);
    igual(['negocio.etapa_mudou', 'negocio.perdido'], $eventos);
});

teste('ActionExecutor: reabrir negócio ganho volta a aberto e limpa a data de fechamento; status ganho/perdido não é editável', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $id = novoNegocio($x);
    $x->moverEtapa($id, ['etapa_id' => etapaId('Ganho'), 'valor_fechado' => 5000]);

    $r = $x->moverEtapa($id, ['etapa_id' => etapaId('Qualificado')]);
    verdadeiro($r->ok);
    $n = Repositorios::negocios()->encontrar($id);
    igual('aberto', $n['status']);
    igual(null, $n['data_fechamento']);
    igual(25, $n['probabilidade']);

    $r = $x->atualizar('negocios', $id, ['status' => 'ganho']);
    verdadeiro(!$r->ok, 'ganhar exige mover para a etapa de ganho');
    contem('etapa correspondente', $r->erros['status']);
    verdadeiro($x->atualizar('negocios', $id, ['status' => 'pausado'])->ok);
    igual('pausado', Repositorios::negocios()->encontrar($id)['status']);
});

teste('ActionExecutor: desfazer mover_etapa restaura etapa, status e probabilidade', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $id = novoNegocio($x);
    $x->moverEtapa($id, ['etapa_id' => etapaId('Ganho'), 'valor_fechado' => 5000]);

    $r = $x->desfazer();
    verdadeiro($r->ok, $r->mensagem);
    $n = Repositorios::negocios()->encontrar($id);
    igual('Novo lead', $n['etapa_nome']);
    igual('aberto', $n['status']);
    igual(10, $n['probabilidade']);
    igual(null, $n['data_fechamento']);
});

teste('ActionExecutor: atividade de contato atualiza último contato; exige vínculo; herda a empresa', function () {
    $pdo = bancoComSeed();
    $eventos = [];
    capturarEventos($eventos);
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    $cid = (int) $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $emp])->id;

    $r = $x->criar('atividades', ['tipo' => 'ligacao', 'assunto' => 'Retorno']);
    verdadeiro(!$r->ok, 'sem vínculo');

    $r = $x->criar('atividades', ['tipo' => 'ligacao', 'assunto' => 'Retorno', 'contato_id' => $cid, 'data_hora' => '2026-09-10T14:30']);
    verdadeiro($r->ok, json_encode($r->erros));
    $a = Repositorios::atividades()->encontrar((int) $r->id);
    igual($emp, (int) $a['empresa_id'], 'empresa herdada do contato');
    igual('2026-09-10 14:30:00', $a['data_hora']);
    igual('2026-09-10 14:30:00', Repositorios::contatos()->encontrar($cid)['ultimo_contato_em']);
    verdadeiro(in_array('atividade.criada', $eventos, true));

    $x->criar('atividades', ['tipo' => 'nota', 'assunto' => 'Só uma nota', 'contato_id' => $cid, 'data_hora' => '2026-09-20 09:00']);
    igual('2026-09-10 14:30:00', Repositorios::contatos()->encontrar($cid)['ultimo_contato_em'], 'nota não conta como contato');

    $x->criar('atividades', ['tipo' => 'reuniao', 'contato_id' => $cid, 'data_hora' => '2026-09-01 10:00']);
    igual('2026-09-10 14:30:00', Repositorios::contatos()->encontrar($cid)['ultimo_contato_em'], 'não retrocede');

    $r = $x->criar('atividades', ['tipo' => 'sistema', 'assunto' => 'Fraude', 'empresa_id' => $emp]);
    verdadeiro(!$r->ok, 'atividade de sistema não pode ser criada por humano');
    verdadeiro($x->criar('atividades', ['tipo' => 'sistema', 'assunto' => 'Ok', 'empresa_id' => $emp], 'sistema')->ok);

    $sistema = (int) $pdo->query("SELECT id FROM atividades WHERE tipo = 'sistema' LIMIT 1")->fetchColumn();
    verdadeiro(!$x->atualizar('atividades', $sistema, ['assunto' => 'x'])->ok, 'sistema não é editável');
    verdadeiro(!$x->arquivar('atividades', $sistema)->ok, 'sistema não é arquivável');
});

teste('ActionExecutor: tarefas — concluir preenche concluida_em e reabrir limpa; default e validações', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $r = $x->criar('tarefas', ['titulo' => 'Ligar para Ana', 'vencimento' => '25/09/2026']);
    verdadeiro($r->ok, json_encode($r->erros));
    $t = Repositorios::tarefas()->encontrar((int) $r->id);
    igual('pendente', $t['status']);
    igual('media', $t['prioridade']);
    igual('nenhuma', $t['recorrencia']);
    igual('2026-09-25', $t['vencimento']);

    $x->concluirTarefa((int) $r->id);
    $t = Repositorios::tarefas()->encontrar((int) $r->id);
    igual('concluida', $t['status']);
    verdadeiro($t['concluida_em'] !== null);

    $x->atualizar('tarefas', (int) $r->id, ['status' => 'pendente']);
    igual(null, Repositorios::tarefas()->encontrar((int) $r->id)['concluida_em']);

    $r2 = $x->criar('tarefas', ['titulo' => 'Reunião', 'vencimento' => '2026-09-25 14:30']);
    igual('2026-09-25 14:30:00', Repositorios::tarefas()->encontrar((int) $r2->id)['vencimento']);
    verdadeiro(!$x->criar('tarefas', ['titulo' => 'X', 'vencimento' => '31/02/2026'])->ok, 'data inexistente');
    verdadeiro(!$x->criar('tarefas', ['titulo' => 'X', 'prioridade' => 'critica'])->ok, 'enum inválido');
    verdadeiro(!$x->criar('tarefas', ['titulo' => 'X', 'empresa_id' => 999])->ok, 'FK inexistente');
});

teste('ActionExecutor: converter em cliente muda status, define cliente_desde e dispara empresa.convertida', function () {
    $pdo = bancoComSeed();
    $eventos = [];
    $x = new ActionExecutor();
    $id = novaEmpresa($x);
    capturarEventos($eventos);

    $r = $x->converterCliente($id);
    verdadeiro($r->ok);
    $e = Repositorios::empresas()->encontrar($id);
    igual('cliente', $e['status']);
    igual(hoje(), $e['cliente_desde']);
    igual(['empresa.atualizada', 'empresa.convertida'], $eventos);
    igual(1, (int) $pdo->query("SELECT COUNT(*) FROM atividades WHERE empresa_id = {$id} AND assunto = 'Empresa convertida em cliente'")->fetchColumn());
    igual('converter_cliente', $pdo->query("SELECT acao FROM log_auditoria ORDER BY id DESC LIMIT 1")->fetchColumn());

    $eventos = [];
    igual('Nada a alterar.', $x->converterCliente($id)->mensagem);
    igual([], $eventos, 'já é cliente: sem evento');
});

teste('ActionExecutor: tags são definidas, auditadas e desfeitas', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    $t1 = (int) $x->criar('tags', ['nome' => 'VIP', 'cor' => '#FF0000'])->id;
    $t2 = (int) $x->criar('tags', ['nome' => 'Local'])->id;
    igual('#ff0000', Repositorios::tags()->encontrar($t1)['cor']);

    verdadeiro($x->definirTags('empresas', $emp, [$t1, $t2])->ok);
    $ids = static function (int $emp): array {
        $v = Repositorios::tags()->idsDe('empresas', $emp);
        sort($v);
        return $v;
    };
    igual([$t1, $t2], $ids($emp));
    verdadeiro($x->definirTags('empresas', $emp, [$t2])->ok);
    igual([$t2], Repositorios::tags()->idsDe('empresas', $emp));
    igual('Nada a alterar.', $x->definirTags('empresas', $emp, [$t2])->mensagem);
    verdadeiro(!$x->definirTags('empresas', $emp, [9999])->ok, 'tag inexistente');
    verdadeiro(!$x->definirTags('tarefas', 1, [$t1])->ok, 'entidade sem tags');

    verdadeiro($x->desfazer()->ok);
    igual([$t1, $t2], $ids($emp));
    igual(1, Repositorios::empresas()->listar(['tag_id' => $t1])['total'], 'filtro por tag');
    igual(0, Repositorios::empresas()->listar(['tag_id' => 9999])['total']);
    verdadeiro(!$x->criar('tags', ['nome' => 'vip'])->ok, 'nome de tag é único sem diferenciar caixa');
});

teste('ActionExecutor: vínculo de contatos ao negócio é auditado e desfeito', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $neg = novoNegocio($x);
    $cid = (int) $x->criar('contatos', ['nome' => 'Ana'])->id;

    verdadeiro($x->vincularContato($neg, $cid, 'financeiro')->ok);
    igual('financeiro', Repositorios::negocios()->contatosVinculados($neg)[0]['papel']);
    igual(1, count(Repositorios::negocios()->porContato($cid)));
    verdadeiro($x->desvincularContato($neg, $cid)->ok);
    igual([], Repositorios::negocios()->contatosVinculados($neg));
    verdadeiro($x->desfazer()->ok, 'desfaz o desvincular');
    igual(1, count(Repositorios::negocios()->contatosVinculados($neg)));
    verdadeiro($x->desfazer()->ok, 'desfaz o vincular');
    igual([], Repositorios::negocios()->contatosVinculados($neg));
    verdadeiro(!$x->vincularContato($neg, 9999, null)->ok);
});

teste('ActionExecutor: configurações — etapas com negócios ativos e pipeline padrão não são arquivados; nomes únicos', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    novoNegocio($x);

    $r = $x->arquivar('etapas', etapaId('Novo lead'));
    verdadeiro(!$r->ok);
    contem('negócios ativos', $r->mensagem);
    verdadeiro($x->arquivar('etapas', etapaId('Qualificado'))->ok, 'etapa vazia pode ser arquivada');

    $pipeline = (int) Repositorios::pipelines()->padrao()['id'];
    contem('padrão', $x->arquivar('pipelines', $pipeline)->mensagem);

    $novo = $x->criar('pipelines', ['nome' => 'Pós-venda']);
    verdadeiro($novo->ok);
    igual(0, (int) Repositorios::pipelines()->encontrar((int) $novo->id)['padrao']);
    $e = $x->criar('etapas', ['pipeline_id' => $novo->id, 'nome' => 'Onboarding', 'tipo' => 'aberta', 'probabilidade_padrao' => 50]);
    verdadeiro($e->ok, json_encode($e->erros));
    igual(1, (int) Repositorios::etapas()->encontrar((int) $e->id)['ordem'], 'ordem automática');
    verdadeiro(!$x->criar('etapas', ['pipeline_id' => $novo->id, 'nome' => 'onboarding', 'probabilidade_padrao' => 1])->ok, 'nome único por pipeline');
    verdadeiro($x->criar('etapas', ['pipeline_id' => $pipeline, 'nome' => 'Onboarding', 'probabilidade_padrao' => 1])->ok, 'mesmo nome em outro pipeline');

    verdadeiro(!$x->criar('origens', ['nome' => 'indicação'])->ok, 'origem duplicada (sem acento/caixa)');
    verdadeiro($x->criar('origens', ['nome' => 'Feira'])->ok);
    verdadeiro(!$x->criar('etapas', ['pipeline_id' => $pipeline, 'nome' => 'X', 'probabilidade_padrao' => 150])->ok, 'probabilidade fora de 0–100');
});

teste('ActionExecutor: origem inválida é rejeitada e origens de agente/formulário ficam registradas', function () {
    $pdo = bancoComSeed();
    $x = new ActionExecutor();
    dispara(InvalidArgumentException::class, fn () => $x->criar('empresas', ['nome_fantasia' => 'A'], 'hacker'));
    $r = $x->criar('empresas', ['nome_fantasia' => 'Via agente'], 'agente:pesquisador');
    verdadeiro($r->ok);
    igual('agente:pesquisador', Repositorios::empresas()->encontrar((int) $r->id)['criado_por']);
    igual('agente:pesquisador', $pdo->query('SELECT origem FROM log_auditoria')->fetchColumn());
    $r = $x->criar('empresas', ['nome_fantasia' => 'Via form'], 'formulario:7');
    igual('formulario:7', Repositorios::empresas()->encontrar((int) $r->id)['criado_por']);
});

teste('ActionExecutor: executar() despacha ações do chat e recusa desconhecidas', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $r = $x->executar('criar', 'empresas', null, ['nome_fantasia' => 'Via chat'], 'ia');
    verdadeiro($r->ok);
    verdadeiro($x->executar('atualizar', 'empresas', (int) $r->id, ['segmento' => 'TI'], 'ia')->ok);
    verdadeiro($x->executar('converter_cliente', 'empresas', (int) $r->id, [], 'ia')->ok);
    verdadeiro(!$x->executar('atualizar', 'empresas', null, ['segmento' => 'TI'])->ok, 'exige id');
    verdadeiro(!$x->executar('apagar_tudo', 'empresas', 1, [])->ok);
    verdadeiro($x->executar('desfazer', 'empresas', null, [], 'ia')->ok);
    igual('ia', (new AuditoriaRepository())->listar(['origem' => 'ia'])['linhas'][0]['origem']);
});

teste('ActionExecutor: eventos só disparam depois do commit e um ouvinte com erro não derruba a operação', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $vistoNoBanco = null;
    Events::ouvir('empresa.criada', function (array $p) use (&$vistoNoBanco) {
        $vistoNoBanco = Repositorios::empresas()->encontrar($p['id']) !== null && !DB::conexao()->inTransaction();
        throw new RuntimeException('ouvinte quebrado');
    });
    $r = @$x->criar('empresas', ['nome_fantasia' => 'A']);
    verdadeiro($r->ok, 'operação concluída mesmo com ouvinte quebrado');
    igual(true, $vistoNoBanco, 'ouvinte viu o registro já gravado e fora da transação');
});

teste('Repositórios: busca sem acento/caixa, filtros, ordenação por whitelist e paginação', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    foreach (['São João Padaria', 'Padaria Central', 'Studio Aurora', 'Mercado Bom Preço'] as $i => $nome) {
        novaEmpresa($x, $nome, ['cidade' => $i % 2 ? 'Recife' : 'São Paulo', 'status' => $i === 3 ? 'cliente' : 'lead']);
    }
    $repo = Repositorios::empresas();
    igual(1, $repo->listar(['busca' => 'sao joao'])['total'], 'sem acento');
    igual(2, $repo->listar(['busca' => 'PADARIA'])['total'], 'sem caixa');
    igual(1, $repo->listar(['busca' => 'preco'])['total']);
    igual(0, $repo->listar(['busca' => "%'; DROP TABLE empresas; --"])['total'], 'injeção é literal');
    igual(1, $repo->listar(['filtros' => ['status' => 'cliente']])['total']);
    igual(4, $repo->listar(['filtros' => ['coluna_inexistente' => 'x']])['total'], 'filtro fora da whitelist é ignorado');

    $ordem = array_column($repo->listar(['ordem' => 'nome_fantasia', 'dir' => 'desc'])['linhas'], 'nome_fantasia');
    igual('Studio Aurora', $ordem[0]);
    $padrao = array_column($repo->listar(['ordem' => 'id; DROP TABLE x'])['linhas'], 'nome_fantasia');
    igual('Mercado Bom Preço', $padrao[0], 'ordem inválida cai na padrão (nome)');

    $p = $repo->listar(['por_pagina' => 3, 'pagina' => 2]);
    igual(1, count($p['linhas']));
    igual(4, $p['total']);
    igual(2, $p['paginas']);
    igual(2, $repo->listar(['por_pagina' => 3, 'pagina' => 99])['pagina'], 'página limitada ao máximo');
    igual(4, count((new \App\Repositories\BuscaRepository())->buscar('a')['empresas']));
    igual(1, count((new \App\Repositories\BuscaRepository())->buscar('sao joao')['empresas']));
});

teste('Repositórios: colunas inválidas são recusadas', function () {
    bancoComSeed();
    dispara(InvalidArgumentException::class, fn () => Repositorios::empresas()->inserir(['nome_fantasia' => 'A', 'coluna_falsa' => 1]));
    dispara(InvalidArgumentException::class, fn () => Repositorios::empresas()->atualizar(1, ['x; DROP TABLE empresas' => 1]));
});

teste('Tarefas: grupos hoje, atrasadas, próximos 7 dias e todas', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $hoje = hoje();
    $x->criar('tarefas', ['titulo' => 'Atrasada', 'vencimento' => date('Y-m-d', strtotime('-2 days'))]);
    $x->criar('tarefas', ['titulo' => 'Hoje', 'vencimento' => $hoje . ' 15:00']);
    $x->criar('tarefas', ['titulo' => 'Amanhã', 'vencimento' => date('Y-m-d', strtotime('+1 day'))]);
    $x->criar('tarefas', ['titulo' => 'Daqui a 20 dias', 'vencimento' => date('Y-m-d', strtotime('+20 days'))]);
    $x->criar('tarefas', ['titulo' => 'Sem data']);
    $feita = (int) $x->criar('tarefas', ['titulo' => 'Feita', 'vencimento' => $hoje])->id;
    $x->concluirTarefa($feita);

    $repo = Repositorios::tarefas();
    $limite = date('Y-m-d', strtotime('+7 days'));
    $titulos = static fn (string $g) => array_column($repo->grupo($g, $hoje, $limite), 'titulo');
    igual(['Hoje'], $titulos('hoje'), 'concluída não entra em hoje');
    igual(['Atrasada'], $titulos('atrasadas'));
    igual(['Amanhã'], $titulos('proximas'));
    igual(6, count($titulos('todas')));
    igual('Feita', array_slice($titulos('todas'), -1)[0], 'concluídas por último em "todas"');
    igual(['hoje' => 1, 'atrasadas' => 1, 'proximas' => 1, 'todas' => 6], $repo->contagens($hoje, $limite));
    dispara(InvalidArgumentException::class, fn () => $repo->grupo('inexistente', $hoje, $limite));
});

teste('ActionExecutor: texto fora de UTF-8 vira erro de validação, não exceção', function () {
    bancoComSeed();
    $r = (new ActionExecutor())->criar('empresas', ['nome_fantasia' => "S\xe3o Paulo"]);
    verdadeiro(!$r->ok);
    contem('UTF-8', $r->erros['nome_fantasia']);
});
