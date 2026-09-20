<?php

declare(strict_types=1);

use App\Core\DB;
use App\Repositories\FormularioRepository;
use App\Repositories\Repositorios;
use App\Repositories\SubmissaoRepository;
use App\Services\ActionExecutor;
use App\Services\CamposExtras;
use App\Services\Events;
use App\Services\Gatilhos;
use App\Services\Resultado;

/** Campos típicos de um formulário de contato: empresa, nome, e-mail, WhatsApp, site, necessidade. */
function camposFormPadrao(): array
{
    return [
        ['campo_destino' => 'empresa.nome_fantasia', 'rotulo' => 'Empresa', 'tipo' => 'texto', 'obrigatorio' => 1, 'largura' => 12],
        ['campo_destino' => 'contato.nome', 'rotulo' => 'Seu nome', 'tipo' => 'texto', 'obrigatorio' => 1, 'largura' => 6],
        ['campo_destino' => 'contato.email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'obrigatorio' => 1, 'largura' => 6],
        ['campo_destino' => 'contato.whatsapp', 'rotulo' => 'WhatsApp', 'tipo' => 'telefone', 'largura' => 6],
        ['campo_destino' => 'empresa.site', 'rotulo' => 'Site', 'tipo' => 'texto', 'largura' => 6],
        ['campo_destino' => 'negocio.dor_principal', 'rotulo' => 'Como podemos ajudar?', 'tipo' => 'textarea', 'largura' => 12],
    ];
}

/** Cria um formulário pelo ActionExecutor e devolve [formulário, mapa destino => nome do input]. */
function novoFormulario(array $config = [], ?array $campos = null): array
{
    $r = (new ActionExecutor())->salvarFormulario(null, $config + ['nome' => 'Contato do site', 'regra_duplicado' => 'tarefa'], $campos ?? camposFormPadrao());
    verdadeiro($r->ok, json_encode($r->erros));
    return formularioPorId((int) $r->id);
}

function formularioPorId(int $id): array
{
    $repo = new FormularioRepository();
    $mapa = [];
    foreach ($repo->campos($id) as $c) {
        $mapa[$c['campo_destino']] = 'c' . $c['id'];
    }
    return [$repo->encontrar($id), $mapa];
}

/** Envia o formulário com valores indexados por destino (traduz para os nomes de input). */
function enviar(array $f, array $mapa, array $valores, array $meta = []): Resultado
{
    $entrada = [];
    foreach ($valores as $destino => $v) {
        $entrada[$mapa[$destino]] = $v;
    }
    return (new ActionExecutor())->receberFormulario($f, $entrada, $meta + ['ip' => '203.0.113.9']);
}

const LEAD_PADRAO = [
    'empresa.nome_fantasia' => 'Padaria Sol', 'contato.nome' => 'Ana Souza', 'contato.email' => 'Ana@Padaria.com.br',
    'contato.whatsapp' => '(11) 98888-7777', 'empresa.site' => 'https://www.padariasol.com.br', 'negocio.dor_principal' => 'Quero um site novo',
];

teste('formulário: definição valida destinos, tipos, duplicidade e campo identificador', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $ruim = static fn (array $campos) => $x->salvarFormulario(null, ['nome' => 'X'], $campos);

    verdadeiro(isset($ruim([])->erros['campos']), 'sem campos');
    verdadeiro(isset($ruim([['campo_destino' => 'empresa.status', 'rotulo' => 'S', 'tipo' => 'texto']])->erros['campo_1']), 'status é do servidor');
    verdadeiro(isset($ruim([['campo_destino' => 'empresa.utm_source', 'rotulo' => 'U', 'tipo' => 'texto']])->erros['campo_1']), 'utm vem do rastreamento');
    verdadeiro(isset($ruim([['campo_destino' => 'negocio.etapa_id', 'rotulo' => 'E', 'tipo' => 'texto']])->erros['campo_1']), 'etapa não é exposta');
    verdadeiro(isset($ruim([['campo_destino' => 'contato.email', 'rotulo' => 'E', 'tipo' => 'texto']])->erros['campo_1']), 'e-mail só aceita tipo e-mail');
    verdadeiro(isset($ruim([
        ['campo_destino' => 'contato.nome', 'rotulo' => 'A', 'tipo' => 'texto', 'obrigatorio' => 1],
        ['campo_destino' => 'contato.nome', 'rotulo' => 'B', 'tipo' => 'texto'],
    ])->erros['campo_2']), 'destino repetido');
    verdadeiro(isset($ruim([['campo_destino' => 'contato.email', 'rotulo' => 'E', 'tipo' => 'email', 'obrigatorio' => 1]])->erros['campos']), 'exige nome identificador obrigatório');
    verdadeiro(isset($ruim([['campo_destino' => 'contato.nome', 'rotulo' => 'N', 'tipo' => 'texto']])->erros['campos']), 'nome não obrigatório não identifica');
    verdadeiro(isset($x->salvarFormulario(null, ['nome' => ''], camposFormPadrao())->erros['nome']));
    verdadeiro(isset($x->salvarFormulario(null, ['nome' => 'X', 'redirect_url' => 'javascript:alert(1)'], camposFormPadrao())->erros['redirect_url']));
    verdadeiro(isset($x->salvarFormulario(null, ['nome' => 'X', 'squad_disparado' => 'nao-existe'], camposFormPadrao())->erros['squad_disparado']));
    verdadeiro(isset($x->salvarFormulario(null, ['nome' => 'X', 'regra_duplicado' => 'apagar'], camposFormPadrao())->erros['regra_duplicado']));
    verdadeiro(isset($ruim([['campo_destino' => 'contato.nome', 'rotulo' => 'N', 'tipo' => 'select', 'obrigatorio' => 1]])->erros['campo_1']), 'lista sem opções');
});

teste('formulário: salvar gera chave de 32 caracteres, troca campos e mantém a chave na edição', function () {
    bancoComSeed();
    [$f] = novoFormulario();
    verdadeiro(preg_match('/^[a-f0-9]{32}$/', $f['chave']) === 1);
    igual(6, count((new FormularioRepository())->campos((int) $f['id'])));
    $r = (new ActionExecutor())->salvarFormulario((int) $f['id'], ['nome' => 'Novo nome'], array_slice(camposFormPadrao(), 0, 2));
    verdadeiro($r->ok);
    [$depois] = formularioPorId((int) $f['id']);
    igual($f['chave'], $depois['chave']);
    igual('Novo nome', $depois['nome']);
    igual(2, count((new FormularioRepository())->campos((int) $f['id'])));
    verdadeiro((new ActionExecutor())->arquivarFormulario((int) $f['id'])->ok);
    igual(null, (new FormularioRepository())->porChave($f['chave']), 'arquivado não abre mais');
});

teste('formulário: envio cria empresa, contato e negócio com UTMs, origem e vínculo', function () {
    bancoComSeed();
    $eventos = [];
    Events::ouvir('formulario.submetido', function (array $p) use (&$eventos) {
        $eventos[] = $p;
    });
    [$f, $mapa] = novoFormulario(['criar_negocio' => 1, 'origem_id_padrao' => Repositorios::para('origens')->todas()[0]['id'], 'status_padrao' => 'prospect']);
    $r = enviar($f, $mapa, LEAD_PADRAO, ['utm' => ['utm_source' => 'google', 'utm_campaign' => 'verao'], 'pagina_origem' => 'https://x.com/lp', 'user_agent' => 'Teste']);
    verdadeiro($r->ok, json_encode($r->erros));
    igual('processada', $r->registro['status']);

    $e = Repositorios::empresas()->encontrar((int) $r->registro['empresa_id']);
    igual('Padaria Sol', $e['nome_fantasia']);
    igual('prospect', $e['status']);
    igual('google', $e['utm_source']);
    igual('verao', $e['utm_campaign']);
    igual((int) $f['id'], (int) $e['formulario_id']);
    igual($r->id, (int) $e['submissao_id']);
    igual('formulario:' . $f['id'], $e['criado_por']);
    verdadeiro($e['origem_id'] !== null);

    $c = Repositorios::contatos()->encontrar((int) $r->registro['contato_id']);
    igual('ana@padaria.com.br', $c['email'], 'e-mail em minúsculas');
    igual((int) $e['id'], (int) $c['empresa_id']);

    $n = Repositorios::negocios()->encontrar((int) $r->registro['negocio_id']);
    igual('Quero um site novo', $n['dor_principal']);
    igual('google', $n['utm_source']);
    igual((int) $c['id'], (int) $n['contato_principal_id']);
    igual($r->id, (int) $n['submissao_id']);

    $s = (new SubmissaoRepository())->encontrar((int) $r->id);
    igual('processada', $s['status']);
    igual('https://x.com/lp', $s['pagina_origem']);
    igual((int) $e['id'], (int) $s['empresa_id']);
    igual(1, count($eventos));
    igual('empresas', $eventos[0]['entidade']);
    igual((int) $e['id'], $eventos[0]['id']);
    igual((int) $n['id'], $eventos[0]['registro']['negocio_id']);
    igual('formulario:' . $f['id'], $eventos[0]['origem']);
});

teste('formulário: validação por campo devolve erros e não grava nada', function () {
    bancoComSeed();
    [$f, $mapa] = novoFormulario();
    $r = enviar($f, $mapa, ['empresa.nome_fantasia' => '', 'contato.nome' => 'Ana', 'contato.email' => 'invalido']);
    verdadeiro(!$r->ok);
    igual('Preencha este campo.', $r->erros[$mapa['empresa.nome_fantasia']]);
    igual('Informe um e-mail válido.', $r->erros[$mapa['contato.email']]);
    igual(0, Repositorios::empresas()->listar()['total']);
    igual(0, (new SubmissaoRepository())->listar()['total']);

    // erro de regra do ActionExecutor (CNPJ inválido) volta para o campo certo e desfaz tudo
    [$f2, $m2] = novoFormulario([], array_merge(camposFormPadrao(), [['campo_destino' => 'empresa.cnpj', 'rotulo' => 'CNPJ', 'tipo' => 'texto']]));
    $r = enviar($f2, $m2, LEAD_PADRAO + ['empresa.cnpj' => '11.111.111/1111-11']);
    verdadeiro(!$r->ok);
    verdadeiro(isset($r->erros[$m2['empresa.cnpj']]), 'erro no campo do CNPJ: ' . json_encode($r->erros));
    igual(0, Repositorios::empresas()->listar()['total'], 'empresa não fica pela metade');
    igual(0, (new SubmissaoRepository())->listar()['total']);
});

teste('formulário: duplicado por e-mail com regra tarefa cria só a tarefa', function () {
    bancoComSeed();
    [$f, $mapa] = novoFormulario(['criar_negocio' => 1]);
    $primeiro = enviar($f, $mapa, LEAD_PADRAO);
    verdadeiro($primeiro->ok);

    $r = enviar($f, $mapa, ['empresa.nome_fantasia' => 'Outro nome', 'contato.nome' => 'Ana S.', 'contato.email' => 'ANA@padaria.com.br']);
    verdadeiro($r->ok);
    igual('duplicada', $r->registro['status']);
    igual(1, Repositorios::empresas()->listar()['total'], 'não duplicou a empresa');
    igual(1, Repositorios::contatos()->listar()['total'], 'não duplicou o contato');
    igual(1, Repositorios::negocios()->listar()['total'], 'nem criou negócio');
    $tarefas = Repositorios::tarefas()->listar()['linhas'];
    igual(1, count($tarefas));
    contem('Revisar envio duplicado', $tarefas[0]['titulo']);
    igual((int) $primeiro->registro['contato_id'], (int) $tarefas[0]['contato_id']);
    contem('e-mail', $tarefas[0]['descricao']);
});

teste('formulário: duplicado por WhatsApp, CNPJ e domínio', function () {
    bancoComSeed();
    [$f, $mapa] = novoFormulario([], array_merge(camposFormPadrao(), [['campo_destino' => 'empresa.cnpj', 'rotulo' => 'CNPJ', 'tipo' => 'texto']]));
    $cnpj = '11.222.333/0001-81';
    verdadeiro(cnpj_valido($cnpj), 'CNPJ de teste precisa ser válido');
    $primeiro = enviar($f, $mapa, [
        'empresa.nome_fantasia' => 'Alfa', 'contato.nome' => 'Bia', 'contato.email' => 'bia@alfa.com', 'contato.whatsapp' => '+55 (11) 91234-5678',
        'empresa.site' => 'alfa.com.br', 'empresa.cnpj' => $cnpj,
    ]);
    verdadeiro($primeiro->ok, json_encode($primeiro->erros));

    $porZap = enviar($f, $mapa, ['empresa.nome_fantasia' => 'X', 'contato.nome' => 'Y', 'contato.email' => 'y@y.com', 'contato.whatsapp' => '11 91234-5678']);
    igual('duplicada', $porZap->registro['status'], 'WhatsApp com e sem DDI/formatação');
    $porCnpj = enviar($f, $mapa, ['empresa.nome_fantasia' => 'Z', 'contato.nome' => 'Z', 'contato.email' => 'z@z.com', 'empresa.cnpj' => '11222333000181']);
    igual('duplicada', $porCnpj->registro['status'], 'CNPJ');
    $porSite = enviar($f, $mapa, ['empresa.nome_fantasia' => 'W', 'contato.nome' => 'W', 'contato.email' => 'w@w.com', 'empresa.site' => 'HTTPS://www.Alfa.com.br/contato']);
    igual('duplicada', $porSite->registro['status'], 'domínio do site');
    $novo = enviar($f, $mapa, ['empresa.nome_fantasia' => 'Beta', 'contato.nome' => 'Cris', 'contato.email' => 'cris@beta.com', 'empresa.site' => 'beta.com.br']);
    igual('processada', $novo->registro['status']);
    igual(2, Repositorios::empresas()->listar()['total']);
});

teste('formulário: regra mesclar preenche só campos vazios e cria negócio', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $e = $x->criar('empresas', ['nome_fantasia' => 'Padaria Sol', 'cidade' => 'Santos']);
    $c = $x->criar('contatos', ['nome' => 'Ana Souza', 'email' => 'ana@padaria.com.br', 'empresa_id' => $e->id, 'cargo' => 'Dona']);
    [$f, $mapa] = novoFormulario(['regra_duplicado' => 'mesclar', 'criar_negocio' => 1], array_merge(camposFormPadrao(), [
        ['campo_destino' => 'empresa.cidade', 'rotulo' => 'Cidade', 'tipo' => 'texto'],
        ['campo_destino' => 'contato.cargo', 'rotulo' => 'Cargo', 'tipo' => 'texto'],
    ]));
    $r = enviar($f, $mapa, LEAD_PADRAO + ['empresa.cidade' => 'São Paulo', 'contato.cargo' => 'Sócia'], ['utm' => ['utm_medium' => 'cpc']]);
    verdadeiro($r->ok, json_encode($r->erros));
    igual('duplicada', $r->registro['status']);
    igual(1, Repositorios::empresas()->listar()['total']);
    igual(1, Repositorios::contatos()->listar()['total']);

    $empresa = Repositorios::empresas()->encontrar((int) $e->id);
    igual('Santos', $empresa['cidade'], 'não sobrescreve campo preenchido');
    igual('https://www.padariasol.com.br', $empresa['site'], 'preenche campo vazio');
    igual('cpc', $empresa['utm_medium']);
    $contato = Repositorios::contatos()->encontrar((int) $c->id);
    igual('Dona', $contato['cargo']);
    igual('(11) 98888-7777', $contato['whatsapp']);
    $negocio = Repositorios::negocios()->encontrar((int) $r->registro['negocio_id']);
    igual((int) $e->id, (int) $negocio['empresa_id'], 'o negócio novo vai para a empresa existente');
});

teste('formulário: regra criar ignora duplicados, mesmo com CNPJ repetido', function () {
    bancoComSeed();
    [$f, $mapa] = novoFormulario(['regra_duplicado' => 'criar'], array_merge(camposFormPadrao(), [['campo_destino' => 'empresa.cnpj', 'rotulo' => 'CNPJ', 'tipo' => 'texto']]));
    $v = ['empresa.nome_fantasia' => 'Alfa', 'contato.nome' => 'Bia', 'contato.email' => 'bia@alfa.com', 'empresa.cnpj' => '11.222.333/0001-81'];
    verdadeiro(enviar($f, $mapa, $v)->ok);
    $r = enviar($f, $mapa, $v);
    verdadeiro($r->ok, json_encode($r->erros));
    igual('processada', $r->registro['status']);
    igual(2, Repositorios::empresas()->listar()['total']);
    igual(2, Repositorios::contatos()->listar()['total']);
});

teste('formulário: campos extras, listas do sistema e checkbox', function () {
    bancoComSeed();
    verdadeiro(campoExtra('empresas', 'nicho', 'select', ['opcoes' => "Moda\nSaúde"])->ok);
    [$f, $mapa] = novoFormulario([], array_merge(camposFormPadrao(), [
        ['campo_destino' => 'extra.nicho', 'rotulo' => 'Nicho', 'tipo' => 'select', 'largura' => 6],
        ['campo_destino' => 'contato.opt_in_marketing', 'rotulo' => 'Aceito receber novidades', 'tipo' => 'checkbox'],
        ['campo_destino' => 'contato.papel_decisao', 'rotulo' => 'Papel', 'tipo' => 'select'],
    ]));
    $base = ['empresa.nome_fantasia' => 'Loja', 'contato.nome' => 'Lu', 'contato.email' => 'lu@loja.com'];
    $ruim = enviar($f, $mapa, $base + ['extra.nicho' => 'Automotivo']);
    verdadeiro(isset($ruim->erros[$mapa['extra.nicho']]), 'opção fora da lista');
    $ruim = enviar($f, $mapa, $base + ['contato.papel_decisao' => 'rei']);
    verdadeiro(isset($ruim->erros[$mapa['contato.papel_decisao']]));

    $r = enviar($f, $mapa, $base + ['extra.nicho' => 'Moda', 'contato.opt_in_marketing' => '1', 'contato.papel_decisao' => 'decisor']);
    verdadeiro($r->ok, json_encode($r->erros));
    $e = Repositorios::empresas()->encontrar((int) $r->registro['empresa_id']);
    igual('Moda', CamposExtras::valores($e['campos_extras'])['nicho']);
    $c = Repositorios::contatos()->encontrar((int) $r->registro['contato_id']);
    igual(1, (int) $c['opt_in_marketing']);
    igual('decisor', $c['papel_decisao']);
});

teste('formulário: só contato (sem campo de empresa) usa o nome do contato como empresa', function () {
    bancoComSeed();
    [$f, $mapa] = novoFormulario([], [
        ['campo_destino' => 'contato.nome', 'rotulo' => 'Nome', 'tipo' => 'texto', 'obrigatorio' => 1],
        ['campo_destino' => 'contato.email', 'rotulo' => 'E-mail', 'tipo' => 'email', 'obrigatorio' => 1],
    ]);
    $r = enviar($f, $mapa, ['contato.nome' => 'Marta Lima', 'contato.email' => 'marta@x.com']);
    verdadeiro($r->ok, json_encode($r->erros));
    igual('Marta Lima', Repositorios::empresas()->encontrar((int) $r->registro['empresa_id'])['nome_fantasia']);
    igual(null, $r->registro['negocio_id']);
});

teste('formulário: honeypot vira spam sem criar registros; limite de 5 envios por IP por hora', function () {
    bancoComSeed();
    $eventos = [];
    Events::ouvir('formulario.submetido', function (array $p) use (&$eventos) {
        $eventos[] = $p;
    });
    [$f, $mapa] = novoFormulario();
    $r = enviar($f, $mapa, LEAD_PADRAO, ['honeypot' => true, 'ip' => '198.51.100.1']);
    verdadeiro($r->ok, 'o robô recebe a mesma resposta de sucesso');
    igual('spam', (new SubmissaoRepository())->encontrar((int) $r->id)['status']);
    igual(0, Repositorios::empresas()->listar()['total']);
    igual([], $eventos, 'spam não dispara evento');

    // o spam já contou 1; mais 4 envios válidos do mesmo IP completam 5 e o próximo é barrado
    for ($i = 1; $i <= 4; $i++) {
        $ok = enviar($f, $mapa, ['empresa.nome_fantasia' => "Emp {$i}", 'contato.nome' => "P{$i}", 'contato.email' => "p{$i}@x.com"], ['ip' => '198.51.100.1']);
        verdadeiro($ok->ok, "envio {$i}");
    }
    $barrado = enviar($f, $mapa, ['empresa.nome_fantasia' => 'Emp 6', 'contato.nome' => 'P6', 'contato.email' => 'p6@x.com'], ['ip' => '198.51.100.1']);
    verdadeiro(!$barrado->ok && isset($barrado->erros['_limite']));
    verdadeiro(enviar($f, $mapa, ['empresa.nome_fantasia' => 'Outro IP', 'contato.nome' => 'Q', 'contato.email' => 'q@x.com'], ['ip' => '198.51.100.2'])->ok, 'outro IP não é afetado');
});

teste('formulário: squad escolhido no formulário entra na fila uma única vez', function () {
    bancoComSeed();
    Gatilhos::registrar();
    $s = (new ActionExecutor())->salvarSquad([
        'slug' => 'triagem-x', 'nome' => 'Triagem X', 'descricao' => 'teste', 'versao' => 1, 'entrada' => 'empresas',
        'etapas' => [['acao' => 'tarefa', 'dados' => ['titulo' => 'Ligar']]], 'gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.criada'],
    ]);
    verdadeiro($s->ok, json_encode($s->erros));
    [$f, $mapa] = novoFormulario(['squad_disparado' => 'triagem-x']);
    $r = enviar($f, $mapa, LEAD_PADRAO);
    verdadeiro($r->ok);
    $fila = DB::conexao()->query('SELECT * FROM execucoes WHERE squad_id IS NOT NULL AND squad_execucao_id IS NULL')->fetchAll();
    igual(1, count($fila), 'uma só execução (empresa.criada e o formulário apontam para o mesmo registro)');
    igual('fila', $fila[0]['status']);
    igual((int) $r->registro['empresa_id'], (int) $fila[0]['registro_id']);
    contem('formulario:' . $f['id'], (string) $fila[0]['entrada']);
});
