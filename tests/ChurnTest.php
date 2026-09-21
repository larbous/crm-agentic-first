<?php

declare(strict_types=1);

use App\Core\DB;
use App\Repositories\ConfiguracaoRepository;
use App\Services\ActionExecutor;
use App\Services\Rotinas;

// Fase 16 — monitoramento de exceções: cliente sem interação e chamado parado (tarefa para o operador, com briefing).

/** Cria um cliente ativo cuja última interação (ligação nossa) foi há $dias dias. @return array{0:int,1:int} [empresa, atividade] */
function clienteCalado(int $dias, string $direcao = 'saida'): array
{
    $x = new ActionExecutor();
    $empresa = (int) $x->criar('empresas', ['nome_fantasia' => 'Clínica Aurora', 'status' => 'cliente'])->id;
    $quando = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
    DB::conexao()->exec("UPDATE empresas SET criado_em = '{$quando}' WHERE id = {$empresa}");
    $a = $x->criar('atividades', ['tipo' => 'ligacao', 'direcao' => $direcao, 'assunto' => 'Alinhamento', 'empresa_id' => $empresa, 'data_hora' => $quando]);
    verdadeiro($a->ok, json_encode($a->erros));
    return [$empresa, (int) $a->id];
}

function tarefasDeRisco(string $prefixo): array
{
    $st = DB::conexao()->prepare('SELECT * FROM tarefas WHERE titulo LIKE :p ORDER BY id');
    $st->execute(['p' => $prefixo . '%']);
    return $st->fetchAll();
}

function primeiraArea(): int
{
    return array_values(areasDeTeste())[0];
}

teste('churn: cliente ativo sem interação há 30+ dias ganha uma tarefa com briefing, uma vez por silêncio', function () {
    bancoComSeed();
    [$empresa, $atividade] = clienteCalado(20);
    igual(0, (new Rotinas())->executar()['clientes_em_risco'], '20 dias ainda está dentro do limiar');

    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-35 days')) . "'");
    igual(1, (new Rotinas())->executar()['clientes_em_risco']);
    $t = tarefasDeRisco('Risco de churn');
    igual(1, count($t));
    igual([$empresa, 'ligar', 'alta', 'pendente'], [(int) $t[0]['empresa_id'], $t[0]['tipo'], $t[0]['prioridade'], $t[0]['status']]);
    verdadeiro(str_contains($t[0]['titulo'], 'Clínica Aurora') && str_contains($t[0]['titulo'], '35 dias'), $t[0]['titulo']);
    verdadeiro(str_contains((string) $t[0]['descricao'], 'Contratos vigentes: nenhum.'), (string) $t[0]['descricao']);
    igual(0, (new Rotinas())->executar()['clientes_em_risco'], 'o mesmo silêncio não alerta duas vezes');
    igual('sistema', DB::conexao()->query('SELECT criado_por FROM tarefas WHERE id = ' . (int) $t[0]['id'])->fetchColumn());

    // A nota não zera o silêncio; uma interação nova zera e um novo silêncio alerta de novo.
    (new ActionExecutor())->criar('atividades', ['tipo' => 'nota', 'assunto' => 'Anotação', 'empresa_id' => $empresa, 'data_hora' => agora()]);
    igual(0, (new Rotinas())->executar()['clientes_em_risco']);
    $x = (new ActionExecutor())->criar('atividades', ['tipo' => 'whatsapp', 'direcao' => 'entrada', 'assunto' => 'Oi', 'empresa_id' => $empresa, 'data_hora' => agora()]);
    verdadeiro($x->ok, json_encode($x->erros));
    igual(0, (new Rotinas())->executar()['clientes_em_risco']);
    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-31 days')) . "' WHERE id = " . (int) $x->id);
    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-40 days')) . "' WHERE id = {$atividade}");
    igual(1, (new Rotinas())->executar()['clientes_em_risco'], 'novo silêncio, novo alerta');
    verdadeiro(str_contains((string) tarefasDeRisco('Risco de churn')[1]['descricao'], 'sem resposta'), 'a última mensagem é do cliente');
});

teste('churn: só clientes ativos; conta interação de contato; limiar configurável e desligável', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $lead = (int) $x->criar('empresas', ['nome_fantasia' => 'Lead Frio'])->id;
    DB::conexao()->exec("UPDATE empresas SET criado_em = '" . date('Y-m-d H:i:s', strtotime('-90 days')) . "' WHERE id = {$lead}");
    igual(0, (new Rotinas())->executar()['clientes_em_risco'], 'lead (não cliente) não entra');

    [$empresa] = clienteCalado(60);
    $contato = (int) $x->criar('contatos', ['nome' => 'Ana', 'empresa_id' => $empresa])->id;
    $r = $x->criar('atividades', ['tipo' => 'email', 'direcao' => 'saida', 'assunto' => 'E-mail', 'contato_id' => $contato, 'data_hora' => agora()]);
    verdadeiro($r->ok, json_encode($r->erros));
    igual(0, (new Rotinas())->executar()['clientes_em_risco'], 'e-mail recente do contato da empresa conta');

    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-45 days')) . "'");
    (new ConfiguracaoRepository())->definir('churn.dias_sem_interacao', '60');
    igual(0, (new Rotinas())->executar()['clientes_em_risco'], '45 dias < 60 configurados');
    (new ConfiguracaoRepository())->definir('churn.dias_sem_interacao', '0');
    igual(0, (new Rotinas())->executar()['clientes_em_risco'], '0 desliga');
    (new ConfiguracaoRepository())->definir('churn.dias_sem_interacao', '40');
    igual(1, (new Rotinas())->executar()['clientes_em_risco']);

    // Empresa arquivada não recebe alerta.
    DB::conexao()->exec("UPDATE atividades SET data_hora = '" . date('Y-m-d H:i:s', strtotime('-50 days')) . "'");
    DB::conexao()->exec("UPDATE empresas SET arquivado_em = '" . agora() . "'");
    igual(0, (new Rotinas())->executar()['clientes_em_risco']);
});

teste('churn: o briefing traz contrato vigente, negócios abertos e chamados em andamento', function () {
    bancoComSeed();
    [$empresa] = clienteCalado(45);
    $x = new ActionExecutor();
    $c = $x->criar('contratos', ['titulo' => 'Gestão de tráfego', 'empresa_id' => $empresa, 'valor_mensal' => 2500, 'data_inicio' => '2026-01-01', 'data_fim' => '2027-01-01']);
    verdadeiro($c->ok, json_encode($c->erros));
    DB::conexao()->exec("UPDATE contratos SET status = 'ativo' WHERE id = " . (int) $c->id);
    $n = $x->criar('negocios', ['titulo' => 'Site novo', 'empresa_id' => $empresa, 'valor_estimado' => 8000]);
    verdadeiro($n->ok, json_encode($n->erros));
    $ch = $x->criar('chamados', ['titulo' => 'Arte do post', 'area_id' => primeiraArea(), 'empresa_id' => $empresa]);
    verdadeiro($ch->ok, json_encode($ch->erros));

    igual(1, (new Rotinas())->executar()['clientes_em_risco']);
    $d = (string) tarefasDeRisco('Risco de churn')[0]['descricao'];
    foreach (['R$ 2.500,00/mês', 'vence em 01/01/2027', 'Negócios abertos: 1 (R$ 8.000,00', 'Chamados em andamento: 1'] as $trecho) {
        verdadeiro(str_contains($d, $trecho), "faltou \"{$trecho}\" em:\n{$d}");
    }
});

teste('churn: chamado sem alteração há 5+ dias vira tarefa, uma vez por parada, e alterar o chamado zera', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $empresa = (int) $x->criar('empresas', ['nome_fantasia' => 'Loja Sol', 'status' => 'cliente'])->id;
    $ch = $x->criar('chamados', ['titulo' => 'Campanha de maio', 'area_id' => primeiraArea(), 'empresa_id' => $empresa, 'vencimento' => date('Y-m-d', strtotime('-1 day'))]);
    verdadeiro($ch->ok, json_encode($ch->erros));
    $id = (int) $ch->id;
    igual(0, (new Rotinas())->executar()['chamados_parados'], 'recém-aberto');

    DB::conexao()->exec("UPDATE chamados SET atualizado_em = '" . date('Y-m-d H:i:s', strtotime('-6 days')) . "' WHERE id = {$id}");
    igual(1, (new Rotinas())->executar()['chamados_parados']);
    $t = tarefasDeRisco('Chamado parado');
    igual(1, count($t));
    igual([$empresa, 'interno', 'alta'], [(int) $t[0]['empresa_id'], $t[0]['tipo'], $t[0]['prioridade']]);
    verdadeiro(str_contains((string) $t[0]['descricao'], '6 dias') && str_contains((string) $t[0]['descricao'], '(atrasado)'), (string) $t[0]['descricao']);
    igual(0, (new Rotinas())->executar()['chamados_parados'], 'não repete para a mesma parada');

    $r = $x->atualizar('chamados', $id, ['status' => 'andamento']);
    verdadeiro($r->ok, json_encode($r->erros));
    igual(0, (new Rotinas())->executar()['chamados_parados'], 'alterar zera');
    DB::conexao()->exec("UPDATE chamados SET atualizado_em = '" . date('Y-m-d H:i:s', strtotime('-9 days')) . "' WHERE id = {$id}");
    igual(1, (new Rotinas())->executar()['chamados_parados'], 'nova parada, novo alerta');

    // Concluído não alerta; limiar configurável.
    DB::conexao()->exec("UPDATE chamados SET status = 'concluido', atualizado_em = '" . date('Y-m-d H:i:s', strtotime('-30 days')) . "' WHERE id = {$id}");
    igual(0, (new Rotinas())->executar()['chamados_parados']);
    DB::conexao()->exec("UPDATE chamados SET status = 'aguardando', atualizado_em = '" . date('Y-m-d H:i:s', strtotime('-12 days')) . "' WHERE id = {$id}");
    (new ConfiguracaoRepository())->definir('churn.dias_chamado_parado', '15');
    igual(0, (new Rotinas())->executar()['chamados_parados'], '12 dias < 15 configurados');
});

teste('churn: no máximo 20 alertas por rodada; o resto sai nas rodadas seguintes', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $velho = date('Y-m-d H:i:s', strtotime('-60 days'));
    for ($i = 1; $i <= 25; $i++) {
        $id = (int) $x->criar('empresas', ['nome_fantasia' => "Cliente {$i}", 'status' => 'cliente'])->id;
        DB::conexao()->exec("UPDATE empresas SET criado_em = '{$velho}' WHERE id = {$id}");
    }
    igual(20, (new Rotinas())->executar()['clientes_em_risco']);
    igual(5, (new Rotinas())->executar()['clientes_em_risco']);
    igual(0, (new Rotinas())->executar()['clientes_em_risco']);
});
