<?php

declare(strict_types=1);

use App\Controllers\EmpresaController;
use App\Controllers\FormularioController;
use App\Controllers\FormularioPublicoController;
use App\Controllers\PesquisaController;
use App\Controllers\PesquisaPublicaController;
use App\Core\DB;
use App\Repositories\FormularioRepository;
use App\Repositories\PesquisaRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\AI\AgenteDefinicao;
use App\Services\Events;
use App\Services\FormularioDefinicao;
use App\Services\Gatilhos;
use App\Services\Nps;
use App\Services\Rotinas;
use App\Services\Worker;

// Fase 12 — pesquisas de satisfação / NPS reaproveitando o construtor de formulários.

/** Campos de uma pesquisa: nota obrigatória, comentário e uma pergunta extra em lista. */
function camposPesquisa(): array
{
    return [
        ['campo_destino' => 'pesquisa.nota', 'rotulo' => 'De 0 a 10, o quanto você nos recomendaria?', 'tipo' => 'numero', 'obrigatorio' => 1, 'largura' => 12, 'ajuda' => '0 = nada provável'],
        ['campo_destino' => 'pesquisa.comentario', 'rotulo' => 'Por quê?', 'tipo' => 'textarea', 'obrigatorio' => 0, 'largura' => 12],
        ['campo_destino' => 'pergunta.1', 'rotulo' => 'Qual serviço você mais usa?', 'tipo' => 'select', 'obrigatorio' => 0, 'largura' => 12, 'opcoes' => ['Site', 'Tráfego', 'Social']],
    ];
}

/** Cria uma pesquisa pelo ActionExecutor. Devolve [formulário, mapa destino => nome do input]. */
function novaPesquisaForm(array $config = [], ?array $campos = null): array
{
    $r = (new ActionExecutor())->salvarFormulario(null, $config + ['tipo' => 'pesquisa', 'nome' => 'NPS trimestral', 'titulo' => 'Como estamos?'], $campos ?? camposPesquisa());
    verdadeiro($r->ok, json_encode($r->erros));
    return formularioPorId((int) $r->id);
}

/** Empresa cliente com um contato que tem WhatsApp e e-mail. */
function clienteComContato(string $nome = 'Padaria Sol'): array
{
    $x = new ActionExecutor();
    $empresa = (int) $x->criar('empresas', ['nome_fantasia' => $nome, 'status' => 'cliente'])->id;
    $contato = $x->criar('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $empresa, 'email' => 'ana@padaria.com.br', 'whatsapp' => '(11) 98888-7777', 'papel_decisao' => 'decisor']);
    verdadeiro($contato->ok, json_encode($contato->erros));
    return [$empresa, (int) $contato->id];
}

function criarEnvio(array $f, int $empresa, ?int $contato = null, string $gatilho = 'manual', string $origem = 'humano', ?int $contrato = null): array
{
    $r = (new ActionExecutor())->criarPesquisa($f, $empresa, $contato, $contrato, $gatilho, $origem);
    verdadeiro($r->ok, $r->mensagem);
    return (new PesquisaRepository())->encontrar((int) $r->id);
}

/** Responde pelo token com valores por destino. */
function responder(array $envio, array $mapa, array $valores, array $meta = ['ip' => '203.0.113.9']): \App\Services\Resultado
{
    $entrada = [];
    foreach ($valores as $destino => $v) {
        $entrada[$mapa[$destino]] = $v;
    }
    return (new ActionExecutor())->responderPesquisa($envio['token'], $entrada, $meta);
}

// ---- Definição (o construtor) ------------------------------------------------------------------

teste('pesquisa: o construtor aceita nota + comentário + perguntas extras; nota obrigatória é exigida; o tipo não muda depois de criado', function () {
    bancoComSeed();
    [$f] = novaPesquisaForm(['gatilho_tipo' => 'contrato_assinado', 'gatilho_dias' => '30', 'validade_dias' => '20']);
    igual('pesquisa', $f['tipo']);
    igual('contrato_assinado', $f['gatilho_tipo']);
    igual(30, (int) $f['gatilho_dias']);
    igual(20, (int) $f['validade_dias']);
    igual(0, (int) $f['tarefa_detrator'], 'sem o campo marcado (checkbox ausente), a tarefa de detrator fica desligada');
    igual(3, count((new FormularioRepository())->campos((int) $f['id'])));

    $x = new ActionExecutor();
    $semNota = $x->salvarFormulario(null, ['tipo' => 'pesquisa', 'nome' => 'Sem nota'], [camposPesquisa()[1]]);
    verdadeiro(!$semNota->ok);
    contem('Nota', $semNota->erros['campos']);
    $notaOpcional = camposPesquisa();
    $notaOpcional[0]['obrigatorio'] = 0;
    verdadeiro(!$x->salvarFormulario(null, ['tipo' => 'pesquisa', 'nome' => 'Nota opcional'], $notaOpcional)->ok, 'a nota precisa ser obrigatória');
    verdadeiro(!$x->salvarFormulario(null, ['tipo' => 'pesquisa', 'nome' => 'Destino de captação'], [['campo_destino' => 'empresa.nome_fantasia', 'rotulo' => 'Empresa', 'tipo' => 'texto', 'obrigatorio' => 1, 'largura' => 12]])->ok, 'destino de captação não vale em pesquisa');

    // Tipo é definido na criação: editar com "captacao" no POST continua sendo pesquisa.
    $r = $x->salvarFormulario((int) $f['id'], ['tipo' => 'captacao', 'nome' => 'Renomeada'], camposPesquisa());
    verdadeiro($r->ok, json_encode($r->erros));
    igual('pesquisa', (new FormularioRepository())->encontrar((int) $f['id'])['tipo']);
    igual('Renomeada', (new FormularioRepository())->encontrar((int) $f['id'])['nome']);
});

teste('pesquisa: gatilho automático exige dias válidos; validade do link entre 1 e 365 dias', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    foreach ([['gatilho_tipo' => 'periodica'], ['gatilho_tipo' => 'periodica', 'gatilho_dias' => '0'], ['gatilho_tipo' => 'contrato_assinado', 'gatilho_dias' => 'abc'], ['gatilho_tipo' => 'semana']] as $ruim) {
        verdadeiro(!$x->salvarFormulario(null, $ruim + ['tipo' => 'pesquisa', 'nome' => 'P'], camposPesquisa())->ok, json_encode($ruim));
    }
    verdadeiro(!$x->salvarFormulario(null, ['tipo' => 'pesquisa', 'nome' => 'P', 'validade_dias' => '0'], camposPesquisa())->ok);
    verdadeiro(!$x->salvarFormulario(null, ['tipo' => 'pesquisa', 'nome' => 'P', 'validade_dias' => '400'], camposPesquisa())->ok);
    $ok = $x->salvarFormulario(null, ['tipo' => 'pesquisa', 'nome' => 'Manual com dias ignorados', 'gatilho_tipo' => 'manual', 'gatilho_dias' => '15'], camposPesquisa());
    verdadeiro($ok->ok);
    igual(null, (new FormularioRepository())->encontrar((int) $ok->id)['gatilho_dias'], 'dias são ignorados no modo manual');
});

teste('pesquisa: formulário de captação continua igual (tipo padrão) e a pesquisa não abre pelo endereço genérico /f/{chave}', function () {
    bancoComSeed();
    [$cap] = novoFormulario();
    igual('captacao', $cap['tipo']);
    [$pesq] = novaPesquisaForm();

    $_GET = [];
    $ctl = new FormularioPublicoController();
    igual(200, $ctl->mostrar(['chave' => $cap['chave']])->status);
    igual(404, $ctl->mostrar(['chave' => $pesq['chave']])->status, 'pesquisa só responde pelo link individual');
    $_POST = [];
    igual(404, $ctl->enviar(['chave' => $pesq['chave']])->status);
    igual(1, count((new FormularioRepository())->pesquisasAtivas()));
});

teste('pesquisa: o editor e a lista de formulários renderizam nos dois tipos', function () {
    bancoComSeed();
    novoFormulario();
    novaPesquisaForm();
    $ctl = new FormularioController();
    $_GET = ['tipo' => 'pesquisa'];
    $novo = $ctl->novo()->corpo;
    contem('name="tipo" value="pesquisa"', $novo);
    contem('gatilho_tipo', $novo);
    contem('pesquisa.nota', $novo);
    verdadeiro(!str_contains($novo, 'squad_disparado'), 'sem as opções de captação');
    $_GET = [];
    $cap = $ctl->novo()->corpo;
    contem('name="tipo" value="captacao"', $cap);
    contem('squad_disparado', $cap);
    $lista = $ctl->index()->corpo;
    contem('Pesquisa NPS', $lista);
    contem('Captação', $lista);
    $_GET = [];
});

// ---- Criar o envio ----------------------------------------------------------------------------

teste('envio: cria link individual de 40 hex, escolhe o contato, registra na timeline e dispara pesquisa.criada; manual não abre tarefa', function () {
    bancoComSeed();
    [$f] = novaPesquisaForm();
    [$empresa, $contato] = clienteComContato();
    $eventos = [];
    Events::ouvir('pesquisa.criada', static function (array $p) use (&$eventos): void {
        $eventos[] = $p;
    });

    $envio = criarEnvio($f, $empresa);
    verdadeiro((bool) preg_match('/^[a-f0-9]{40}$/', $envio['token']), 'token aleatório de 40 hex');
    igual('pendente', $envio['status']);
    igual($contato, (int) $envio['contato_id'], 'o decisor com e-mail/WhatsApp é o destinatário');
    igual('manual', $envio['gatilho']);
    verdadeiro(strtotime($envio['expira_em']) > time() + 29 * 86400 && strtotime($envio['expira_em']) < time() + 31 * 86400, 'validade padrão de 30 dias');
    igual(null, $envio['enviada_em']);
    igual(1, count($eventos));
    igual($empresa, $eventos[0]['id']);
    igual((int) $envio['id'], $eventos[0]['pesquisa_id']);

    $tl = DB::conexao()->query("SELECT * FROM atividades WHERE empresa_id = {$empresa} AND tipo = 'sistema'")->fetchAll();
    igual(1, count($tl));
    contem('Pesquisa NPS criada', $tl[0]['assunto']);
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM tarefas')->fetchColumn(), 'envio manual não cria tarefa');

    $outro = criarEnvio($f, $empresa);
    verdadeiro($outro['token'] !== $envio['token'], 'cada envio tem o seu token');
});

teste('envio: recusa formulário de captação, pesquisa inativa e empresa inexistente', function () {
    bancoComSeed();
    [$cap] = novoFormulario();
    [$pesq] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $x = new ActionExecutor();
    verdadeiro(!$x->criarPesquisa($cap, $empresa)->ok);
    verdadeiro(!$x->criarPesquisa($pesq, 99999)->ok);
    (new FormularioRepository())->atualizar((int) $pesq['id'], ['ativo' => 0]);
    verdadeiro(!$x->criarPesquisa((new FormularioRepository())->encontrar((int) $pesq['id']), $empresa)->ok);
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM pesquisas')->fetchColumn());
});

teste('envio: sem contato com canal, a pesquisa é criada sem destinatário; contato de outra empresa é ignorado', function () {
    bancoComSeed();
    [$f] = novaPesquisaForm();
    $x = new ActionExecutor();
    $vazia = (int) $x->criar('empresas', ['nome_fantasia' => 'Sem contato'])->id;
    igual(null, criarEnvio($f, $vazia)['contato_id']);
    [$empresa, $contato] = clienteComContato();
    $outra = (int) $x->criar('empresas', ['nome_fantasia' => 'Outra'])->id;
    igual(null, criarEnvio($f, $outra, $contato)['contato_id'], 'contato de outra empresa não vale');
    igual($contato, (int) criarEnvio($f, $empresa, $contato)['contato_id']);
});

// ---- Responder --------------------------------------------------------------------------------

teste('resposta: grava nota, categoria, comentário e extras; registra na timeline e dispara pesquisa.respondida', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $envio = criarEnvio($f, $empresa);
    $eventos = [];
    Events::ouvir('pesquisa.respondida', static function (array $p) use (&$eventos): void {
        $eventos[] = $p;
    });

    $r = responder($envio, $mapa, ['pesquisa.nota' => '10', 'pesquisa.comentario' => 'Atendimento excelente', 'pergunta.1' => 'Site']);
    verdadeiro($r->ok, json_encode($r->erros));
    $p = (new PesquisaRepository())->encontrar((int) $envio['id']);
    igual('respondida', $p['status']);
    igual(10, (int) $p['nota']);
    igual('promotor', $p['categoria']);
    igual('Atendimento excelente', $p['comentario']);
    igual([['rotulo' => 'Qual serviço você mais usa?', 'valor' => 'Site']], $p['respostas']);
    verdadeiro($p['respondida_em'] !== null);
    igual('203.0.113.9', $p['ip']);
    igual(1, count($eventos));
    igual(10, $eventos[0]['nota']);
    igual('promotor', $eventos[0]['categoria']);
    igual($empresa, $eventos[0]['id']);
    igual('formulario:' . $f['id'], $eventos[0]['origem']);

    $tl = DB::conexao()->query("SELECT assunto, descricao FROM atividades WHERE empresa_id = {$empresa} AND assunto LIKE 'Pesquisa NPS respondida%'")->fetchAll();
    igual(1, count($tl));
    contem('nota 10', $tl[0]['assunto']);
    contem('Atendimento excelente', $tl[0]['descricao']);
    contem('Site', $tl[0]['descricao']);
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM tarefas')->fetchColumn(), 'promotor não abre tarefa');
});

teste('resposta: cada link responde uma vez; expirado, cancelado e pesquisa inativa não aceitam', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $x = new ActionExecutor();

    $a = criarEnvio($f, $empresa);
    verdadeiro(responder($a, $mapa, ['pesquisa.nota' => '8'])->ok);
    $de = responder($a, $mapa, ['pesquisa.nota' => '3']);
    verdadeiro(!$de->ok);
    contem('já foi respondida', $de->erros['_']);
    igual(8, (int) (new PesquisaRepository())->encontrar((int) $a['id'])['nota'], 'a primeira resposta prevalece');

    $b = criarEnvio($f, $empresa);
    DB::conexao()->exec("UPDATE pesquisas SET expira_em = '2000-01-01 00:00:00' WHERE id = " . (int) $b['id']);
    $exp = responder($b, $mapa, ['pesquisa.nota' => '9']);
    verdadeiro(!$exp->ok);
    contem('prazo', $exp->erros['_']);
    igual('expirada', (new PesquisaRepository())->encontrar((int) $b['id'])['status'], 'a tentativa também marca como expirada');

    $c = criarEnvio($f, $empresa);
    verdadeiro($x->cancelarPesquisa((int) $c['id'])->ok);
    verdadeiro(!responder($c, $mapa, ['pesquisa.nota' => '9'])->ok);
    verdadeiro(!$x->cancelarPesquisa((int) $c['id'])->ok, 'só cancela pendente');

    $d = criarEnvio($f, $empresa);
    (new FormularioRepository())->atualizar((int) $f['id'], ['ativo' => 0]);
    contem('não está mais disponível', responder($d, $mapa, ['pesquisa.nota' => '9'])->erros['_']);
    verdadeiro(!(new ActionExecutor())->responderPesquisa('0000', [])->ok, 'token desconhecido');
});

teste('resposta: valida a nota (0–10 inteira), obrigatórios e opções; nada é gravado quando há erro', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $envio = criarEnvio($f, $empresa);
    foreach (['', '11', '-1', '7.5', 'dez', ' '] as $ruim) {
        $r = responder($envio, $mapa, ['pesquisa.nota' => $ruim]);
        verdadeiro(!$r->ok, "nota '{$ruim}'");
        verdadeiro(isset($r->erros[$mapa['pesquisa.nota']]), 'erro no campo da nota');
    }
    $r = responder($envio, $mapa, ['pesquisa.nota' => '5', 'pergunta.1' => 'Fax']);
    verdadeiro(!$r->ok);
    contem('opções', $r->erros[$mapa['pergunta.1']]);
    igual('pendente', (new PesquisaRepository())->encontrar((int) $envio['id'])['status'], 'segue pendente');
    verdadeiro(responder($envio, $mapa, ['pesquisa.nota' => '0'])->ok, 'zero é uma nota válida');
    igual(0, (int) (new PesquisaRepository())->encontrar((int) $envio['id'])['nota']);

    // Pergunta extra obrigatória.
    $campos = camposPesquisa();
    $campos[2]['obrigatorio'] = 1;
    [$f2, $mapa2] = novaPesquisaForm(['nome' => 'Com extra obrigatória'], $campos);
    $envio2 = criarEnvio($f2, $empresa);
    $r2 = responder($envio2, $mapa2, ['pesquisa.nota' => '9']);
    verdadeiro(!$r2->ok);
    verdadeiro(isset($r2->erros[$mapa2['pergunta.1']]));
});

teste('resposta: detrator (0–6) abre tarefa de ligação urgente com o comentário, se a pesquisa pedir; neutro não abre', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm(['tarefa_detrator' => '1']);
    [$empresa, $contato] = clienteComContato();

    responder(criarEnvio($f, $empresa), $mapa, ['pesquisa.nota' => '7', 'pesquisa.comentario' => 'ok']);
    igual(0, (int) DB::conexao()->query('SELECT COUNT(*) FROM tarefas')->fetchColumn(), 'neutro (7) não abre tarefa');
    igual('neutro', (new PesquisaRepository())->listar(['status' => 'respondida'])['linhas'][0]['categoria']);

    responder(criarEnvio($f, $empresa), $mapa, ['pesquisa.nota' => '6', 'pesquisa.comentario' => 'Demoraram para responder']);
    $t = DB::conexao()->query('SELECT * FROM tarefas')->fetchAll();
    igual(1, count($t));
    contem('Ligar para Padaria Sol', $t[0]['titulo']);
    contem('nota 6', $t[0]['titulo']);
    contem('Demoraram para responder', $t[0]['descricao']);
    igual('alta', $t[0]['prioridade']);
    igual('ligar', $t[0]['tipo']);
    igual($empresa, (int) $t[0]['empresa_id']);
    igual($contato, (int) $t[0]['contato_id']);

    [$semTarefa, $mapa2] = novaPesquisaForm(['nome' => 'Sem tarefa de detrator', 'tarefa_detrator' => '0']);
    responder(criarEnvio($semTarefa, $empresa), $mapa2, ['pesquisa.nota' => '0']);
    igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM tarefas')->fetchColumn(), 'tarefa_detrator desligada não cria');
});

teste('resposta: agentes e squads podem disparar por pesquisa.criada / pesquisa.respondida (gatilho por evento)', function () {
    bancoComSeed();
    Gatilhos::registrar();
    foreach (['pesquisa.criada', 'pesquisa.respondida'] as $ev) {
        $v = AgenteDefinicao::validar(defAgente(['slug' => 'ag-' . str_replace('.', '-', $ev), 'gatilho' => ['tipo' => 'evento', 'evento' => $ev]]));
        verdadeiro($v['ok'], $ev . ': ' . implode('; ', $v['erros']));
    }
    agenteNoBanco(['slug' => 'analisa-nps', 'gatilho' => ['tipo' => 'evento', 'evento' => 'pesquisa.respondida']]);
    [$f, $mapa] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $envio = criarEnvio($f, $empresa);
    igual(0, count((new \App\Repositories\ExecucaoRepository())->daFila(10)), 'a criação não dispara o agente de "respondida"');
    responder($envio, $mapa, ['pesquisa.nota' => '9']);
    $fila = (new \App\Repositories\ExecucaoRepository())->daFila(10);
    igual(1, count($fila));
    igual($empresa, (int) $fila[0]['registro_id'], 'o alvo do agente é a empresa');
});

// ---- Página pública -----------------------------------------------------------------------------

teste('público: /nps/{token} mostra a escala 0–10, valida, grava e responde só uma vez; sem indexação nem cache', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $envio = criarEnvio($f, $empresa);
    $ctl = new PesquisaPublicaController();

    $pag = $ctl->mostrar(['token' => $envio['token']]);
    igual(200, $pag->status);
    contem('Como estamos?', $pag->corpo);
    contem('fp-nps', $pag->corpo);
    contem('value="10"', $pag->corpo);
    contem('Site', $pag->corpo, 'pergunta extra em lista');
    contem('noindex', $pag->cabecalhos['X-Robots-Tag']);
    contem('no-store', $pag->cabecalhos['Cache-Control']);
    contem('no-referrer', $pag->cabecalhos['Referrer-Policy']);

    $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    $_POST = [$mapa['pesquisa.comentario'] => 'sem nota'];
    $erro = $ctl->enviar(['token' => $envio['token']]);
    igual(422, $erro->status);
    contem('Escolha uma nota', $erro->corpo);
    contem('sem nota', $erro->corpo, 'mantém o que já foi digitado');

    $_POST = [$mapa['pesquisa.nota'] => '9', $mapa['pesquisa.comentario'] => 'Muito bom'];
    $ok = $ctl->enviar(['token' => $envio['token']]);
    igual(200, $ok->status);
    contem('Obrigado pela sua resposta', $ok->corpo);
    igual('198.51.100.7', (new PesquisaRepository())->encontrar((int) $envio['id'])['ip']);

    contem('já foi respondida', $ctl->mostrar(['token' => $envio['token']])->corpo);
    $_POST = [$mapa['pesquisa.nota'] => '2'];
    contem('já foi respondida', $ctl->enviar(['token' => $envio['token']])->corpo);
    igual(9, (int) (new PesquisaRepository())->encontrar((int) $envio['id'])['nota']);

    igual(404, $ctl->mostrar(['token' => str_repeat('a', 40)])->status);
    $_POST = [];
});

teste('público: link expirado, cancelado ou de pesquisa inativa mostra o aviso sem o formulário', function () {
    bancoComSeed();
    [$f] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $ctl = new PesquisaPublicaController();

    $exp = criarEnvio($f, $empresa);
    DB::conexao()->exec("UPDATE pesquisas SET expira_em = '2000-01-01 00:00:00' WHERE id = " . (int) $exp['id']);
    $corpo = $ctl->mostrar(['token' => $exp['token']])->corpo;
    contem('prazo', $corpo);
    verdadeiro(!str_contains($corpo, 'fp-nps'));

    $can = criarEnvio($f, $empresa);
    (new ActionExecutor())->cancelarPesquisa((int) $can['id']);
    contem('não está mais disponível', $ctl->mostrar(['token' => $can['token']])->corpo);

    $ativo = criarEnvio($f, $empresa);
    (new FormularioRepository())->atualizar((int) $f['id'], ['ativo' => 0]);
    igual(404, $ctl->mostrar(['token' => $ativo['token']])->status);
});

// ---- Worker -----------------------------------------------------------------------------------

teste('worker: pesquisa "N dias após contrato assinado" cria uma por contrato, dentro da janela, com tarefa para enviar o link', function () {
    bancoComSeed();
    [$f] = novaPesquisaForm(['gatilho_tipo' => 'contrato_assinado', 'gatilho_dias' => '30']);
    [$empresa] = clienteComContato();
    $x = new ActionExecutor();
    $contrato = static function (string $titulo, string $status, ?string $assinadoEm) use ($x, $empresa): int {
        $id = (int) $x->criar('contratos', ['titulo' => $titulo, 'recorrencia' => 'unica', 'empresa_id' => $empresa])->id;
        DB::conexao()->prepare('UPDATE contratos SET status = :s, assinado_em = :a WHERE id = :id')->execute(['s' => $status, 'a' => $assinadoEm, 'id' => $id]);
        return $id;
    };
    $dia = static fn (int $atras): string => date('Y-m-d H:i:s', strtotime("-{$atras} days"));
    $antigo = $contrato('Assinado há 20 dias (ainda cedo)', 'assinado', $dia(20));
    $devido = $contrato('Assinado há 31 dias', 'assinado', $dia(31));
    $ativo = $contrato('Ativo há 45 dias', 'ativo', $dia(45));
    $velho = $contrato('Assinado há 120 dias (fora da janela)', 'assinado', $dia(120));
    $rascunho = $contrato('Rascunho', 'rascunho', null);

    $criadas = (new Rotinas())->executar()['pesquisas_criadas'];
    igual(2, $criadas);
    $contratos = array_column(DB::conexao()->query('SELECT contrato_id FROM pesquisas')->fetchAll(), 'contrato_id');
    sort($contratos);
    igual([$devido, $ativo], $contratos, 'só os contratos com N dias ou mais e dentro da janela de 30 dias extras');
    $t = DB::conexao()->query("SELECT * FROM tarefas WHERE tipo = 'enviar'")->fetchAll();
    igual(2, count($t));
    contem('Enviar pesquisa NPS: Padaria Sol', $t[0]['titulo']);
    contem('/nps/', $t[0]['descricao']);

    igual(0, (new Rotinas())->executar()['pesquisas_criadas'], 'não duplica a cada rodada do worker');
    $p = DB::conexao()->query('SELECT gatilho, criado_por FROM pesquisas')->fetch();
    igual('contrato_assinado', $p['gatilho']);
    igual('sistema', $p['criado_por']);

    // O contrato que ainda não tinha N dias entra quando chegar a hora.
    DB::conexao()->exec("UPDATE contratos SET assinado_em = '" . $dia(30) . "' WHERE id = {$antigo}");
    igual(1, (new Rotinas())->executar()['pesquisas_criadas']);
});

teste('worker: pesquisa periódica cria para clientes ativos há N dias, sem repetir dentro do intervalo, e ignora cancelada só quando recente', function () {
    bancoComSeed();
    [$f] = novaPesquisaForm(['gatilho_tipo' => 'periodica', 'gatilho_dias' => '90']);
    $x = new ActionExecutor();
    $cliente = static function (string $nome, string $status, ?string $desde) use ($x): int {
        $id = (int) $x->criar('empresas', ['nome_fantasia' => $nome, 'status' => $status])->id;
        DB::conexao()->prepare('UPDATE empresas SET cliente_desde = :d WHERE id = :id')->execute(['d' => $desde, 'id' => $id]);
        return $id;
    };
    $antigo = $cliente('Cliente antigo', 'cliente', date('Y-m-d', strtotime('-200 days')));
    $recente = $cliente('Cliente recente', 'cliente', date('Y-m-d', strtotime('-10 days')));
    $lead = $cliente('Lead', 'lead', null);
    $semData = $cliente('Cliente sem data', 'cliente', null);

    igual(2, (new Rotinas())->executar()['pesquisas_criadas']);
    $empresas = array_column(DB::conexao()->query('SELECT empresa_id FROM pesquisas')->fetchAll(), 'empresa_id');
    sort($empresas);
    igual([$antigo, $semData], array_map('intval', $empresas), 'cliente há 200 dias e cliente sem data; nem o recente nem o lead');
    igual(0, (new Rotinas())->executar()['pesquisas_criadas'], 'sem repetir dentro do intervalo');

    // Passados os 90 dias, a pesquisa antiga não conta mais e uma nova é criada.
    DB::conexao()->exec("UPDATE pesquisas SET criado_em = '" . date('Y-m-d H:i:s', strtotime('-91 days')) . "' WHERE empresa_id = {$antigo}");
    igual(1, (new Rotinas())->executar()['pesquisas_criadas']);
});

teste('worker: pesquisa manual não cria sozinha; link vencido expira; o Worker inclui as rotinas na rodada', function () {
    bancoComSeed();
    [$manual] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    igual(0, (new Rotinas())->executar()['pesquisas_criadas']);

    $envio = criarEnvio($manual, $empresa);
    DB::conexao()->exec("UPDATE pesquisas SET expira_em = '2000-01-01 00:00:00' WHERE id = " . (int) $envio['id']);
    $r = (new Worker())->rodada();
    igual(1, $r['rotinas']['pesquisas_expiradas']);
    igual('expirada', (new PesquisaRepository())->encontrar((int) $envio['id'])['status']);
    igual(0, (new Rotinas())->executar()['pesquisas_expiradas']);
});

teste('worker: no máximo 50 pesquisas por rodada; o resto entra na seguinte', function () {
    bancoComSeed();
    novaPesquisaForm(['gatilho_tipo' => 'periodica', 'gatilho_dias' => '30']);
    $x = new ActionExecutor();
    for ($i = 1; $i <= 55; $i++) {
        $id = (int) $x->criar('empresas', ['nome_fantasia' => "Cliente {$i}", 'status' => 'cliente'])->id;
        DB::conexao()->exec("UPDATE empresas SET cliente_desde = '2020-01-01' WHERE id = {$id}");
    }
    igual(50, (new Rotinas())->executar()['pesquisas_criadas']);
    igual(5, (new Rotinas())->executar()['pesquisas_criadas']);
});

// ---- Tabulação --------------------------------------------------------------------------------

teste('nps: categoria por nota, índice = %promotores − %detratores e zonas de leitura', function () {
    foreach ([0 => 'detrator', 6 => 'detrator', 7 => 'neutro', 8 => 'neutro', 9 => 'promotor', 10 => 'promotor'] as $nota => $cat) {
        igual($cat, Nps::categoria($nota), "nota {$nota}");
    }
    $r = Nps::resumir([10 => 5, 9 => 3, 8 => 2, 7 => 1, 6 => 1, 0 => 3]);
    igual(15, $r['total']);
    igual(8, $r['promotores']);
    igual(3, $r['neutros']);
    igual(4, $r['detratores']);
    igual(27, $r['nps'], '(8 − 4) / 15 = 26,67 → 27');
    igual(53, $r['pct_promotores']);
    igual(20, $r['pct_neutros']);
    igual(27, $r['pct_detratores']);
    igual(null, Nps::resumir([])['nps'], 'sem respostas não há índice');
    igual(100, Nps::resumir([10 => 4])['nps']);
    igual(-100, Nps::resumir([0 => 2, 3 => 2])['nps']);
    igual('Excelência', Nps::zona(80));
    igual('Qualidade', Nps::zona(50));
    igual('Aperfeiçoamento', Nps::zona(0));
    igual('Crítica', Nps::zona(-1));
});

teste('tabulação: contagens por nota, mês e status respeitam formulário e período', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm();
    [$f2, $mapa2] = novaPesquisaForm(['nome' => 'Outra pesquisa']);
    [$empresa] = clienteComContato();
    $repo = new PesquisaRepository();
    foreach ([10, 10, 9, 8, 3] as $nota) {
        responder(criarEnvio($f, $empresa), $mapa, ['pesquisa.nota' => (string) $nota]);
    }
    responder(criarEnvio($f2, $empresa), $mapa2, ['pesquisa.nota' => '0']);
    criarEnvio($f, $empresa);                                                   // pendente
    (new ActionExecutor())->cancelarPesquisa((int) criarEnvio($f, $empresa)['id']); // cancelada

    igual([0 => 1, 3 => 1, 8 => 1, 9 => 1, 10 => 2], $repo->contagemPorNota());
    igual([3 => 1, 8 => 1, 9 => 1, 10 => 2], $repo->contagemPorNota(['formulario_id' => (int) $f['id']]));
    igual([], $repo->contagemPorNota(['desde' => date('Y-m-d', strtotime('+1 day'))]));
    $porStatus = $repo->contagemPorStatus(['formulario_id' => (int) $f['id']]);
    igual(5, $porStatus['respondida']);
    igual(1, $porStatus['pendente']);
    igual(1, $porStatus['cancelada']);

    DB::conexao()->exec("UPDATE pesquisas SET respondida_em = '2026-01-15 10:00:00' WHERE nota IN (10, 9)");
    $meses = $repo->contagemPorMes();
    igual(['2026-01', date('Y-m')], array_keys($meses));
    igual([9 => 1, 10 => 2], $meses['2026-01']);
    igual(1, $repo->contarAEnviar(), 'só a pendente com o link ainda não entregue');
});

// ---- Telas ------------------------------------------------------------------------------------

teste('tela: /pesquisas mostra índice, distribuição, tendência, fila e respostas; filtros por pesquisa e período', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm();
    [$f2, $mapa2] = novaPesquisaForm(['nome' => 'Semestral']);
    [$empresa] = clienteComContato();
    foreach ([10, 9, 3] as $nota) {
        responder(criarEnvio($f, $empresa), $mapa, ['pesquisa.nota' => (string) $nota, 'pesquisa.comentario' => "Comentário nota {$nota}"]);
    }
    responder(criarEnvio($f2, $empresa), $mapa2, ['pesquisa.nota' => '10']);
    criarEnvio($f, $empresa);
    $ctl = new PesquisaController();

    $_GET = [];
    $corpo = $ctl->index()->corpo;
    contem('Pesquisas NPS', $corpo);
    contem('Aguardando resposta', $corpo);
    contem('Tendência mensal', $corpo);
    contem('Comentário nota 3', $corpo);
    contem('Detrator', $corpo);
    contem('Promotor', $corpo);
    contem('Padaria Sol', $corpo);
    contem('>50<', $corpo, 'NPS de 4 respostas: 3 promotores e 1 detrator = 50');

    $_GET = ['formulario' => (string) $f['id'], 'periodo' => '30'];
    $filtrado = $ctl->index()->corpo;
    contem('>33<', $filtrado, 'NPS só da primeira pesquisa: (2 − 1) / 3 = 33');

    $_GET = ['formulario' => '9999', 'periodo' => 'abc'];
    igual(200, $ctl->index()->status, 'filtros inválidos são ignorados');
    $_GET = [];
});

teste('tela: página do envio traz link, mensagem pronta, WhatsApp com DDI 55 e e-mail; marcar enviada e cancelar', function () {
    bancoComSeed();
    [$f, $mapa] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $envio = criarEnvio($f, $empresa);
    $ctl = new PesquisaController();

    $corpo = $ctl->mostrar(['id' => (string) $envio['id']])->corpo;
    contem('/nps/' . $envio['token'], $corpo);
    contem('Olá, Ana!', $corpo);
    contem('https://wa.me/5511988887777?text=', $corpo);
    contem('mailto:ana%40padaria.com.br', $corpo);
    contem('Marcar como enviada', $corpo);

    $x = new ActionExecutor();
    verdadeiro($x->marcarPesquisaEnviada((int) $envio['id'])->ok);
    verdadeiro((new PesquisaRepository())->encontrar((int) $envio['id'])['enviada_em'] !== null);
    contem('Enviada em', $ctl->mostrar(['id' => (string) $envio['id']])->corpo);
    igual(0, (new PesquisaRepository())->contarAEnviar());

    verdadeiro($x->cancelarPesquisa((int) $envio['id'])->ok);
    $cancelada = $ctl->mostrar(['id' => (string) $envio['id']])->corpo;
    contem('Pesquisa cancelada', $cancelada);
    verdadeiro(!str_contains($cancelada, '/nps/' . $envio['token']), 'sem link depois de cancelada');
    igual(404, $ctl->mostrar(['id' => '99999'])->status);
});

teste('tela: criar envio pela empresa (POST /pesquisas) e a aba Pesquisas NPS do detalhe da empresa', function () {
    bancoComSeed();
    [$f] = novaPesquisaForm();
    [$empresa] = clienteComContato();
    $ctl = new PesquisaController();

    $_POST = ['empresa_id' => (string) $empresa, 'formulario_id' => (string) $f['id'], 'voltar' => '/empresas/' . $empresa];
    $r = $ctl->criar();
    igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM pesquisas')->fetchColumn());
    $id = (int) DB::conexao()->query('SELECT id FROM pesquisas')->fetchColumn();
    contem('/pesquisas/' . $id, (string) ($r->cabecalhos['Location'] ?? ''));

    $_POST = ['empresa_id' => (string) $empresa, 'formulario_id' => '9999', 'voltar' => '//evil.example'];
    $ruim = $ctl->criar();
    verdadeiro(!str_contains((string) ($ruim->cabecalhos['Location'] ?? ''), 'evil.example'), 'redirecionamento só para caminhos internos');
    igual(1, (int) DB::conexao()->query('SELECT COUNT(*) FROM pesquisas')->fetchColumn());
    $_POST = [];

    $detalhe = (new EmpresaController())->mostrar(['id' => (string) $empresa])->corpo;
    contem('Pesquisas NPS', $detalhe);
    contem('NPS trimestral', $detalhe);
    contem('Pendente', $detalhe);
});
