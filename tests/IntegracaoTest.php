<?php

declare(strict_types=1);

use App\Controllers\IntegracaoController;
use App\Core\Config;
use App\Core\DB;
use App\Repositories\ExecucaoRepository;
use App\Repositories\Repositorios;
use App\Repositories\SeedRepository;
use App\Services\ActionExecutor;
use App\Services\Events;
use App\Services\Gatilhos;
use App\Services\Opcoes;

// Integração de entrada com o Opensquad (SPEC §13): POST /api/integracao/opensquad.

const TOKEN_OPENSQUAD = 'token-de-teste';

/** Banco com o pipeline de 8 etapas da Lárbous (com "Abordado") e a integração ligada. */
function bancoIntegracao(): PDO
{
    $pdo = bancoDeTeste();
    $seed = new SeedRepository($pdo);
    $seed->garantirPipeline('Vendas', [
        ['Novo lead', 5, '#64748b', 'aberta'], ['Abordado', 10, '#64748b', 'aberta'], ['Qualificado', 25, '#0ea5e9', 'aberta'],
        ['Reunião', 40, '#0ea5e9', 'aberta'], ['Proposta', 60, '#f59e0b', 'aberta'], ['Negociação', 80, '#f59e0b', 'aberta'],
        ['Ganho', 100, '#22c55e', 'ganho'], ['Perdido', 0, '#ef4444', 'perdido'],
    ]);
    $seed->garantirNomes('motivos_perda', ['Preço']);
    Events::limpar();
    Opcoes::limpar();
    Config::definir(array_replace_recursive(configNeutra(), ['integracao' => ['opensquad_token' => TOKEN_OPENSQUAD]]));
    return $pdo;
}

/** Envia um lote e devolve [status HTTP, corpo decodificado]. */
function enviarLote(array $eventos, string $run = 'run-1', string $squad = 'prospeccao-leads', bool $simular = false): array
{
    $corpo = json_encode(['squad' => $squad, 'run' => $run, 'simular' => $simular, 'eventos' => $eventos], JSON_UNESCAPED_UNICODE);
    $resp = (new IntegracaoController())->processar($corpo, TOKEN_OPENSQUAD);
    return [$resp->status, json_decode($resp->corpo, true)];
}

function eventoLeadJf(array $mudar = []): array
{
    return array_replace_recursive([
        'id' => 'lead-jf', 'tipo' => 'lead', 'origem' => 'Prospecção ativa (Google Maps)',
        'empresa' => ['nome_fantasia' => 'JF Cabeleireiro', 'telefone' => '(11) 98888-7777', 'cidade' => 'Guarulhos', 'uf' => 'SP', 'segmento' => 'Salão de beleza'],
        'contato' => ['nome' => 'Jéssica', 'cargo' => 'Dona'],
        'negocio' => ['etapa' => 'Abordado', 'valor_estimado' => 1500, 'dor_principal' => 'Agenda vazia de segunda a quarta'],
        'nota' => ['assunto' => 'Diagnóstico', 'descricao' => 'Ficha do Google incompleta; Instagram parado.'],
        'tarefas' => [
            ['titulo' => 'Follow-up 1', 'em_dias' => 2],
            ['titulo' => 'Follow-up 2', 'em_dias' => 6, 'prioridade' => 'alta'],
        ],
    ], $mudar);
}

function contar(string $tabela): int
{
    return (int) DB::conexao()->query("SELECT COUNT(*) FROM {$tabela} WHERE arquivado_em IS NULL")->fetchColumn();
}

teste('integração: sem token configurado, token errado, JSON ou squad inválidos são recusados', function () {
    bancoIntegracao();
    $controller = new IntegracaoController();
    $lote = json_encode(['squad' => 'x', 'run' => 'r', 'eventos' => []]);
    igual(403, $controller->processar($lote, 'errado')->status);
    igual(403, $controller->processar($lote, '')->status);
    igual(400, $controller->processar('{nao é json', TOKEN_OPENSQUAD)->status);
    igual(400, $controller->processar(json_encode(['squad' => 'Com Espaço', 'run' => 'r', 'eventos' => []]), TOKEN_OPENSQUAD)->status);
    igual(400, $controller->processar(json_encode(['squad' => 'x', 'run' => 'r', 'eventos' => ['a' => 1]]), TOKEN_OPENSQUAD)->status);
    igual(200, $controller->processar($lote, TOKEN_OPENSQUAD)->status, 'lote vazio serve de teste de conexão');

    Config::definir(configNeutra()); // token vazio = integração desligada
    $resp = $controller->processar($lote, '');
    igual(403, $resp->status);
    contem('desativada', $resp->corpo);
});

teste('integração: lead novo cria empresa, contato, negócio na etapa pedida, nota e tarefas com origem opensquad', function () {
    bancoIntegracao();
    [$status, $corpo] = enviarLote([eventoLeadJf()]);
    igual(200, $status);
    verdadeiro($corpo['ok'], json_encode($corpo));
    $r = $corpo['resultados'][0];
    igual('ok', $r['status']);

    $empresa = Repositorios::empresas()->encontrar((int) $r['empresa_id']);
    igual('JF Cabeleireiro', $empresa['nome_fantasia']);
    igual('lead', $empresa['status']);
    igual('opensquad:prospeccao-leads', $empresa['criado_por']);
    igual('Prospecção ativa (Google Maps)', Repositorios::para('origens')->encontrar((int) $empresa['origem_id'])['nome'], 'origem criada pelo nome');

    $negocio = Repositorios::negocios()->encontrar((int) $r['negocio_id']);
    igual('Abordado', $negocio['etapa_nome']);
    igual(150000, (int) $negocio['valor_estimado']);
    igual((int) $r['contato_id'], (int) $negocio['contato_principal_id']);
    igual('Oportunidade — JF Cabeleireiro', $negocio['titulo']);
    contem('/negocios/' . $r['negocio_id'], (string) $r['link']);

    $vencimentos = DB::conexao()->query("SELECT titulo, vencimento, prioridade, tipo FROM tarefas WHERE negocio_id = {$r['negocio_id']} ORDER BY id")->fetchAll();
    igual(2, count($vencimentos));
    igual(date('Y-m-d', strtotime(hoje() . ' +2 days')), substr((string) $vencimentos[0]['vencimento'], 0, 10));
    igual('alta', $vencimentos[1]['prioridade']);
    igual('followup', $vencimentos[0]['tipo']);
    igual(1, (int) DB::conexao()->query("SELECT COUNT(*) FROM atividades WHERE tipo = 'nota' AND assunto = 'Diagnóstico'")->fetchColumn());
});

teste('integração: reenviar o mesmo lote não duplica nada (idempotência por squad|run|id)', function () {
    bancoIntegracao();
    enviarLote([eventoLeadJf()]);
    $antes = [contar('empresas'), contar('negocios'), contar('tarefas'), contar('atividades')];
    [$status, $corpo] = enviarLote([eventoLeadJf()]);
    igual(200, $status);
    igual('ja_processado', $corpo['resultados'][0]['status']);
    verdadeiro($corpo['ok']);
    igual($antes, [contar('empresas'), contar('negocios'), contar('tarefas'), contar('atividades')]);

    // Outro run com o mesmo lead: não é reenvio, mas reencontra a empresa e o negócio aberto (sem duplicar).
    [, $corpo] = enviarLote([eventoLeadJf(['tarefas' => []])], 'run-2');
    igual('ok', $corpo['resultados'][0]['status']);
    igual($antes[0], contar('empresas'));
    igual($antes[1], contar('negocios'));
});

teste('integração: empresa já cadastrada é achada pelo telefone e só tem campos vazios completados', function () {
    bancoIntegracao();
    $x = new ActionExecutor();
    $empresaId = (int) $x->criar('empresas', ['nome_fantasia' => 'JF Cabelos (nome no CRM)', 'whatsapp' => '+55 11 98888-7777', 'segmento' => 'Beleza'])->id;

    [, $corpo] = enviarLote([eventoLeadJf(['negocio' => ['etapa' => 'Novo lead'], 'tarefas' => []])]);
    $r = $corpo['resultados'][0];
    igual('ok', $r['status'], json_encode($r));
    igual($empresaId, (int) $r['empresa_id']);
    contem('igual por telefone', implode(' | ', $r['acoes']));
    $empresa = Repositorios::empresas()->encontrar($empresaId);
    igual('JF Cabelos (nome no CRM)', $empresa['nome_fantasia'], 'nome do CRM não é sobrescrito');
    igual('Beleza', $empresa['segmento'], 'campo preenchido não é sobrescrito');
    igual('Guarulhos', $empresa['cidade'], 'campo vazio é completado');
    igual(1, contar('empresas'));
});

teste('integração: avança o negócio aberto, mas nunca retrocede de etapa', function () {
    bancoIntegracao();
    [, $corpo] = enviarLote([eventoLeadJf(['negocio' => ['etapa' => 'Novo lead'], 'tarefas' => []])], 'run-a');
    $negocioId = (int) $corpo['resultados'][0]['negocio_id'];

    [, $corpo] = enviarLote([eventoLeadJf(['tarefas' => [], 'negocio' => ['titulo' => 'Presença local — JF']])], 'run-b'); // pede "Abordado"
    igual($negocioId, (int) $corpo['resultados'][0]['negocio_id']);
    contem('Novo lead → Abordado', implode(' | ', $corpo['resultados'][0]['acoes']));
    igual('Presença local — JF', Repositorios::negocios()->encontrar($negocioId)['titulo'], 'título genérico da integração é substituído');

    // Título escrito por alguém (não o genérico) nunca é trocado.
    (new ActionExecutor())->atualizar('negocios', $negocioId, ['titulo' => 'Título do operador']);
    enviarLote([eventoLeadJf(['tarefas' => [], 'negocio' => ['titulo' => 'Outro título']])], 'run-b2');
    igual('Título do operador', Repositorios::negocios()->encontrar($negocioId)['titulo']);

    // O operador moveu para Reunião no CRM; um evento pedindo "Abordado" não pode puxar de volta.
    (new ActionExecutor())->moverEtapa($negocioId, ['etapa_id' => (int) Repositorios::etapas()->porNome((int) Repositorios::pipelines()->padrao()['id'], 'Reunião')['id']]);
    [, $corpo] = enviarLote([eventoLeadJf(['tarefas' => []])], 'run-c');
    igual('ok', $corpo['resultados'][0]['status']);
    contem('não retrocede', implode(' | ', $corpo['resultados'][0]['avisos']));
    igual('Reunião', Repositorios::negocios()->encontrar($negocioId)['etapa_nome']);
    igual(1, contar('negocios'));
});

teste('integração: evento com erro não grava nada (tudo ou nada) e pode ser reenviado corrigido', function () {
    bancoIntegracao();
    [, $corpo] = enviarLote([eventoLeadJf(['negocio' => ['etapa' => 'Etapa Que Não Existe']])]);
    $r = $corpo['resultados'][0];
    igual('erro', $r['status']);
    verdadeiro($corpo['ok'] === false);
    contem('Etapas: Novo lead, Abordado', $r['mensagem'], 'lista as etapas válidas');
    igual(0, contar('empresas'), 'empresa criada antes do erro foi desfeita');
    igual(0, contar('tarefas'));

    // Tarefa sem título também derruba o evento inteiro.
    $semTitulo = ['id' => 'lead-2', 'tarefas' => [['em_dias' => 1]]] + eventoLeadJf(); // substitui a lista inteira
    [, $corpo] = enviarLote([$semTitulo]);
    contem('Tarefa 1', $corpo['resultados'][0]['mensagem'] ?? '');
    igual('erro', $corpo['resultados'][0]['status']);
    igual(0, contar('empresas'));

    // Mesmo id, agora corrigido: um erro registrado não bloqueia o reenvio.
    [, $corpo] = enviarLote([eventoLeadJf()]);
    igual('ok', $corpo['resultados'][0]['status']);
    igual(1, contar('empresas'));
});

teste('integração: um evento que falha não impede os outros do mesmo lote; campos desconhecidos viram aviso', function () {
    bancoIntegracao();
    [, $corpo] = enviarLote([
        eventoLeadJf(['empresa' => ['campo_inventado' => 'x', 'status' => 'cliente']]),
        ['id' => 'sem-nome', 'tipo' => 'lead', 'empresa' => ['cidade' => 'Guarulhos']],
        ['id' => 'lead-jf', 'tipo' => 'lead'],
        ['id' => 'tipo-errado', 'tipo' => 'compra', 'empresa' => ['nome_fantasia' => 'X']],
    ]);
    $porId = array_column($corpo['resultados'], null, 'id');
    igual('ok', $corpo['resultados'][0]['status']);
    contem('empresa.campo_inventado ignorado', implode(' | ', $corpo['resultados'][0]['avisos']));
    contem('empresa.status ignorado', implode(' | ', $corpo['resultados'][0]['avisos']));
    igual('lead', Repositorios::empresas()->encontrar((int) $corpo['resultados'][0]['empresa_id'])['status'], 'status não é definido pela integração');
    contem('nome_fantasia', $porId['sem-nome']['mensagem']);
    contem('repetido', $corpo['resultados'][2]['mensagem']);
    contem('desconhecido', $porId['tipo-errado']['mensagem']);
    verdadeiro($corpo['ok'] === false);
});

teste('integração: ganho move o negócio aberto para Ganho, exige valor e converte a empresa em cliente', function () {
    bancoIntegracao();
    enviarLote([eventoLeadJf(['tarefas' => []])]);

    [, $corpo] = enviarLote([['id' => 'ganho-jf', 'tipo' => 'ganho', 'empresa' => ['nome_fantasia' => 'JF Cabeleireiro', 'telefone' => '11988887777']]], 'onb-1', 'onboarding-cliente');
    igual('erro', $corpo['resultados'][0]['status']);
    contem('valor_fechado', $corpo['resultados'][0]['mensagem']);

    [, $corpo] = enviarLote([[
        'id' => 'ganho-jf', 'tipo' => 'ganho', 'valor_fechado' => '1.290,00',
        'empresa' => ['nome_fantasia' => 'JF Cabeleireiro', 'telefone' => '11988887777'],
        'negocio' => ['tipo_receita' => 'mensal', 'valor_recorrente' => 1290],
        'tarefas' => [['titulo' => 'Cobrar briefing', 'em_dias' => 3]],
    ]], 'onb-1', 'onboarding-cliente');
    $r = $corpo['resultados'][0];
    igual('ok', $r['status'], json_encode($r));
    $negocio = Repositorios::negocios()->encontrar((int) $r['negocio_id']);
    igual('ganho', $negocio['status']);
    igual(129000, (int) $negocio['valor_fechado']);
    igual('mensal', $negocio['tipo_receita']);
    $empresa = Repositorios::empresas()->encontrar((int) $r['empresa_id']);
    igual('cliente', $empresa['status']);
    igual(hoje(), $empresa['cliente_desde']);
    igual(1, contar('negocios'));

    // Negócio já ganho (ex.: marcado à mão no CRM): outro evento de ganho não cria um segundo negócio.
    [, $corpo] = enviarLote([['id' => 'ganho-jf', 'tipo' => 'ganho', 'valor_fechado' => 1290, 'empresa' => ['nome_fantasia' => 'JF Cabeleireiro', 'telefone' => '11988887777']]], 'onb-2', 'onboarding-cliente');
    igual('ok', $corpo['resultados'][0]['status']);
    contem('já estava ganho', implode(' | ', $corpo['resultados'][0]['avisos']));
    igual(1, contar('negocios'));
});

teste('integração: ganho de cliente que não estava no CRM cria empresa e negócio já ganho', function () {
    bancoIntegracao();
    [, $corpo] = enviarLote([['id' => 'ganho-novo', 'tipo' => 'ganho', 'valor_fechado' => 2000, 'empresa' => ['nome_fantasia' => 'Oficina Motor Vivo', 'cidade' => 'Guarulhos']]], 'onb-3', 'onboarding-cliente');
    $r = $corpo['resultados'][0];
    igual('ok', $r['status'], json_encode($r));
    igual('ganho', Repositorios::negocios()->encontrar((int) $r['negocio_id'])['status']);
    igual('cliente', Repositorios::empresas()->encontrar((int) $r['empresa_id'])['status']);
});

teste('integração: perdido exige empresa existente e motivo (criado se não existir)', function () {
    bancoIntegracao();
    [, $corpo] = enviarLote([['id' => 'p1', 'tipo' => 'perdido', 'motivo' => 'Sem resposta à abordagem', 'empresa' => ['nome_fantasia' => 'Ninguém', 'cidade' => 'Lugar Nenhum']]]);
    igual('erro', $corpo['resultados'][0]['status']);
    contem('não encontrada', $corpo['resultados'][0]['mensagem']);

    enviarLote([eventoLeadJf(['tarefas' => []])], 'run-lead');
    [, $corpo] = enviarLote([['id' => 'p1', 'tipo' => 'perdido', 'motivo' => 'Sem resposta à abordagem', 'detalhe' => 'Sequência encerrada no dia 14', 'empresa' => ['nome_fantasia' => 'JF Cabeleireiro', 'cidade' => 'guarulhos']]], 'run-perda');
    $r = $corpo['resultados'][0];
    igual('ok', $r['status'], json_encode($r));
    $negocio = Repositorios::negocios()->encontrar((int) $r['negocio_id']);
    igual('perdido', $negocio['status']);
    igual('Sem resposta à abordagem', $negocio['motivo_perda_nome']);
    igual('Sequência encerrada no dia 14', $negocio['detalhe_perda']);
});

teste('integração: evento atividade registra nota e tarefa numa empresa existente, sem criar nada', function () {
    bancoIntegracao();
    enviarLote([eventoLeadJf(['tarefas' => []])]);
    [, $corpo] = enviarLote([['id' => 'a1', 'tipo' => 'atividade', 'empresa' => ['nome_fantasia' => 'JF Cabeleireiro', 'telefone' => '11 98888-7777'],
        'nota' => ['tipo' => 'WhatsApp', 'direcao' => 'saida', 'assunto' => 'Primeira mensagem enviada'], 'tarefas' => [['titulo' => 'Ligar', 'tipo' => 'ligar', 'vencimento' => '2026-12-01']]]], 'run-x');
    $r = $corpo['resultados'][0];
    igual('ok', $r['status'], json_encode($r));
    $atividade = DB::conexao()->query("SELECT * FROM atividades WHERE assunto = 'Primeira mensagem enviada'")->fetch();
    igual('whatsapp', $atividade['tipo']);
    igual((int) $r['negocio_id'], (int) $atividade['negocio_id'], 'vinculada ao negócio aberto');
    igual(1, contar('empresas'));
});

teste('integração: simulação mostra o que aconteceria sem gravar nada nem registrar o envio', function () {
    bancoIntegracao();
    [, $corpo] = enviarLote([eventoLeadJf()], 'run-1', 'prospeccao-leads', true);
    verdadeiro($corpo['simulacao']);
    $r = $corpo['resultados'][0];
    igual('ok', $r['status'], json_encode($r));
    contem('empresa nova: JF Cabeleireiro', implode(' | ', $r['acoes']));
    contem('2 tarefas criadas', implode(' | ', $r['acoes']));
    igual(0, contar('empresas'));
    igual(0, contar('tarefas'));
    igual(0, contar('origens'));

    igual(null, $r['link'], 'simulação não aponta para registros que não existem');

    // O lote é simulado de uma vez: o 2º evento enxerga o 1º (origem criada uma só vez, códigos em sequência),
    // e um evento recusado no meio não deixa rastro para os seguintes.
    $outro = ['id' => 'lead-bigode', 'tipo' => 'lead', 'origem' => 'Prospecção ativa (Google Maps)', 'empresa' => ['nome_fantasia' => 'Barbearia Dom Bigode', 'cidade' => 'Guarulhos']];
    $ruim = ['id' => 'lead-ruim', 'tipo' => 'lead', 'empresa' => ['nome_fantasia' => 'Ruim'], 'negocio' => ['etapa' => 'Inexistente']];
    [, $corpo] = enviarLote([eventoLeadJf(), $ruim, $outro], 'run-1', 'prospeccao-leads', true);
    [$a, $b, $c] = $corpo['resultados'];
    igual('erro', $b['status']);
    contem('origem nova', implode(' | ', $a['acoes']));
    verdadeiro(!str_contains(implode(' | ', $c['acoes']), 'origem nova'), 'origem já "criada" pelo 1º evento');
    verdadeiro($a['negocio_id'] !== $c['negocio_id'], 'negócios distintos na simulação');
    igual(0, contar('empresas'));
    igual(0, contar('negocios'));

    // Depois da simulação, o envio real do mesmo lote é processado normalmente.
    [, $corpo] = enviarLote([eventoLeadJf()]);
    igual('ok', $corpo['resultados'][0]['status']);
    igual(1, contar('empresas'));
});

teste('integração: registros vindos do Opensquad não disparam agentes nem squads do CRM', function () {
    bancoIntegracao();
    Gatilhos::registrar();
    iaPorAgente([]);
    agenteDeSquad('ag-a');
    agenteDeSquad('ag-evento', ['gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.criada']]);
    squadNoBanco(['gatilho' => ['tipo' => 'evento', 'evento' => 'empresa.criada']]);

    enviarLote([eventoLeadJf()]);
    igual(0, count((new ExecucaoRepository())->daFila(10)), 'nada enfileirado para origem opensquad');

    (new ActionExecutor())->criar('empresas', ['nome_fantasia' => 'Criada à mão']);
    igual(2, count((new ExecucaoRepository())->daFila(10)), 'empresa criada à mão continua disparando');
    Events::limpar();
});
