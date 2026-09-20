<?php

declare(strict_types=1);

use App\Core\DB;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\AI\AgenteDefinicao;
use App\Services\AI\ContextBuilder;
use App\Services\Metas;

/** Meta pelo ActionExecutor (valor_alvo em centavos para faturamento/mrr, quantidade nos demais). */
function novaMeta(string $tipo, int $valor, string $inicio = '2026-09-01', string $periodo = 'mensal'): int
{
    $r = (new ActionExecutor())->criar('metas', ['tipo' => $tipo, 'periodo' => $periodo, 'valor_alvo' => $valor, 'data_inicio' => $inicio]);
    verdadeiro($r->ok, 'criar meta: ' . json_encode($r->erros));
    return (int) $r->id;
}

/** Negócio ganho com valor fechado e data de fechamento fixos (o executor usa a data de hoje). */
function negocioGanho(ActionExecutor $x, int $empresa, string $valor, string $fechamento): int
{
    $id = novoNegocio($x, ['empresa_id' => $empresa, 'valor_estimado' => $valor]);
    $r = $x->moverEtapa($id, ['etapa_id' => etapaId('Ganho'), 'valor_fechado' => $valor]);
    verdadeiro($r->ok, 'ganhar: ' . json_encode($r->erros));
    DB::conexao()->prepare('UPDATE negocios SET data_fechamento = :d WHERE id = :id')->execute(['d' => $fechamento, 'id' => $id]);
    return $id;
}

teste('metas: fim do período por tipo, sem estourar meses curtos', function () {
    igual('2026-09-30', Metas::fimDoPeriodo('2026-09-01', 'mensal'));
    igual('2026-12-31', Metas::fimDoPeriodo('2026-10-01', 'trimestral'));
    igual('2027-02-14', Metas::fimDoPeriodo('2026-11-15', 'trimestral'));
    igual('2026-12-31', Metas::fimDoPeriodo('2026-01-01', 'anual'));
    igual('2026-02-27', Metas::fimDoPeriodo('2026-01-31', 'mensal'), '31/01 + 1 mês cai em 28/02 (não 03/03)');
    igual(null, Metas::fimDoPeriodo('2026-02-30', 'mensal'));
    igual(null, Metas::fimDoPeriodo('2026-09-01', 'semanal'));
});

teste('metas: criar deriva data_fim, valida e não aceita data_fim na entrada', function () {
    bancoComSeed();
    $id = novaMeta('faturamento', 3000000);
    $meta = Repositorios::metas()->encontrar($id);
    igual('2026-09-30', $meta['data_fim']);
    igual(3000000, (int) $meta['valor_alvo']);
    igual('humano', $meta['criado_por']);
    contem('Faturamento (mensal)', Metas::rotulo($meta));

    $x = new ActionExecutor();
    $base = ['tipo' => 'mrr', 'periodo' => 'mensal', 'valor_alvo' => 5000, 'data_inicio' => '2026-09-01'];
    verdadeiro(isset($x->criar('metas', ['valor_alvo' => 0] + $base)->erros['valor_alvo']), 'alvo zero');
    verdadeiro(isset($x->criar('metas', ['valor_alvo' => '-3'] + $base)->erros['valor_alvo']), 'alvo negativo');
    verdadeiro(isset($x->criar('metas', ['valor_alvo' => 'abc'] + $base)->erros['valor_alvo']), 'alvo texto');
    verdadeiro(isset($x->criar('metas', ['tipo' => 'lucro'] + $base)->erros['tipo']), 'tipo fora da whitelist');
    verdadeiro(isset($x->criar('metas', ['periodo' => 'semanal'] + $base)->erros['periodo']), 'período fora da whitelist');
    verdadeiro(isset($x->criar('metas', ['data_inicio' => '2026-13-01'] + $base)->erros['data_inicio']), 'data inválida');
    verdadeiro(isset($x->criar('metas', ['data_fim' => '2030-01-01'] + $base)->erros['data_fim']), 'data_fim é do servidor');
    igual(1, count(Repositorios::metas()->listar()['linhas']), 'nenhuma inválida foi gravada');
});

teste('metas: atualizar recalcula o fim, desfazer restaura e arquivar esconde', function () {
    bancoComSeed();
    $id = novaMeta('negocios_ganhos', 4);
    $x = new ActionExecutor();
    verdadeiro($x->atualizar('metas', $id, ['periodo' => 'trimestral'])->ok);
    igual('2026-11-30', Repositorios::metas()->encontrar($id)['data_fim']);
    verdadeiro($x->atualizar('metas', $id, ['data_inicio' => '2026-10-01'])->ok);
    igual('2026-12-31', Repositorios::metas()->encontrar($id)['data_fim']);
    igual('Nada a alterar.', $x->atualizar('metas', $id, ['valor_alvo' => 4])->mensagem);

    verdadeiro($x->desfazer()->ok);
    $meta = Repositorios::metas()->encontrar($id);
    igual('2026-09-01', $meta['data_inicio']);
    igual('2026-11-30', $meta['data_fim'], 'o fim derivado volta junto com o início');

    verdadeiro($x->arquivar('metas', $id)->ok);
    igual(null, Repositorios::metas()->encontrar($id));
    igual([], Repositorios::metas()->vigentes('2026-09-15'));
});

teste('metas: progresso de faturamento e negócios ganhos só conta ganhos do período', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    negocioGanho($x, $emp, '10.000,00', '2026-09-05');
    negocioGanho($x, $emp, '2.500,50', '2026-09-30');
    negocioGanho($x, $emp, '9.999,00', '2026-08-31'); // fora do período
    negocioGanho($x, $emp, '7.000,00', '2026-10-01'); // fora do período
    $perdido = novoNegocio($x, ['empresa_id' => $emp]);
    verdadeiro($x->moverEtapa($perdido, ['etapa_id' => etapaId('Perdido'), 'motivo_perda_id' => 1])->ok);
    $arquivado = negocioGanho($x, $emp, '1.000,00', '2026-09-10');
    verdadeiro($x->arquivar('negocios', $arquivado)->ok);

    $fat = Metas::progresso(Repositorios::metas()->encontrar(novaMeta('faturamento', 5000000)), '2026-09-30');
    igual(1250050, $fat['realizado']);
    igual(25, $fat['percentual']);
    igual('R$ 12.500,50', $fat['realizado_txt']);
    igual('R$ 50.000,00', $fat['alvo_txt']);
    igual('abaixo_ritmo', $fat['situacao'], 'no último dia, 25% está abaixo do ritmo de 100%');

    $ganhos = Metas::progresso(Repositorios::metas()->encontrar(novaMeta('negocios_ganhos', 2)), '2026-09-30');
    igual(2, $ganhos['realizado']);
    igual('atingida', $ganhos['situacao']);
    igual('2', $ganhos['realizado_txt'], 'quantidade sem moeda');
});

teste('metas: novos clientes, propostas enviadas e MRR', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $pdo = DB::conexao();
    [$emp, , $neg] = negocioComContato($x);
    $outra = novaEmpresa($x, 'Loja Azul');
    verdadeiro($x->converterCliente($emp)->ok);
    verdadeiro($x->converterCliente($outra)->ok);
    $pdo->exec("UPDATE empresas SET cliente_desde = '2026-09-03' WHERE id = {$emp}");
    $pdo->exec("UPDATE empresas SET cliente_desde = '2026-08-30' WHERE id = {$outra}");

    $p1 = novaProposta($x, $neg);
    verdadeiro($x->enviarProposta($p1)->ok);
    $v2 = (int) $x->novaVersaoProposta($p1)->id;
    verdadeiro($x->enviarProposta($v2)->ok);
    $p3 = novaProposta($x, $neg); // rascunho: não conta
    $pdo->exec("UPDATE propostas SET enviada_em = '2026-09-08 10:00:00' WHERE enviada_em IS NOT NULL");

    $agora = agora();
    $ins = $pdo->prepare(
        'INSERT INTO contratos (numero, titulo, status, valor_mensal, data_inicio, data_fim, token_publico, criado_em, atualizado_em)
         VALUES (:n, :t, :s, :v, :i, :f, :k, :a, :a)'
    );
    $contrato = static fn (string $n, string $status, ?int $mensal, ?string $ini, ?string $fim) => $ins->execute(
        ['n' => $n, 't' => $n, 's' => $status, 'v' => $mensal, 'i' => $ini, 'f' => $fim, 'k' => bin2hex(random_bytes(16)), 'a' => $agora]
    );
    $contrato('CT-1', 'ativo', 150000, '2026-01-01', '2026-12-31');
    $contrato('CT-2', 'assinado', 80000, '2026-09-10', null);            // vigente, sem fim
    $contrato('CT-3', 'vencido', 60000, '2026-01-01', '2026-09-12');     // ainda vigente em 10/09
    $contrato('CT-4', 'rascunho', 999900, '2026-01-01', '2026-12-31');   // não assinado
    $contrato('CT-5', 'cancelado', 999900, '2026-01-01', '2026-12-31');
    $contrato('CT-6', 'ativo', 70000, '2026-09-25', '2026-12-31');       // começa depois da referência
    $contrato('CT-7', 'ativo', 50000, null, '2026-12-31');              // sem início: não conta

    $clientes = Metas::progresso(Repositorios::metas()->encontrar(novaMeta('novos_clientes', 4)), '2026-09-20');
    igual(1, $clientes['realizado'], 'só quem virou cliente em setembro');
    igual(25, $clientes['percentual']);

    $props = Metas::progresso(Repositorios::metas()->encontrar(novaMeta('propostas_enviadas', 3)), '2026-09-20');
    igual(1, $props['realizado'], 'a proposta e sua nova versão contam uma vez; rascunho não conta');

    $mrr = Metas::progresso(Repositorios::metas()->encontrar(novaMeta('mrr', 500000)), '2026-09-11');
    igual(150000 + 80000 + 60000, $mrr['realizado'], 'contratos vigentes em 11/09');
    igual(null, $mrr['esperado'], 'MRR é uma foto: sem ritmo linear');
    igual('em_andamento', $mrr['situacao']);
    $mrrFim = Metas::progresso(Repositorios::metas()->encontrar(novaMeta('mrr', 500000)), '2026-10-05');
    igual(150000 + 80000 + 70000, $mrrFim['realizado'], 'depois do período, mede no último dia (30/09)');
    igual('nao_atingida', $mrrFim['situacao']);
});

teste('metas: ritmo esperado, futuras e encerradas', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    negocioGanho($x, $emp, '3.000,00', '2026-09-02');
    $meta = Repositorios::metas()->encontrar(novaMeta('faturamento', 1000000)); // 30% no dia 20 de 30 dias

    $p = Metas::progresso($meta, '2026-09-15');
    igual(50, $p['esperado'], '15 de 30 dias');
    igual('abaixo_ritmo', $p['situacao']);
    igual(15, $p['dias_restantes']);
    igual('no_ritmo', Metas::progresso($meta, '2026-09-09')['situacao'], '30% de 9 dias = 30%: no ritmo');
    igual('futura', Metas::progresso($meta, '2026-08-31')['situacao']);
    igual(0, Metas::progresso($meta, '2026-08-31')['realizado']);
    igual('nao_atingida', Metas::progresso($meta, '2026-10-02')['situacao']);
    igual(0, Metas::progresso($meta, '2026-10-02')['dias_restantes']);

    igual(1, count(Metas::vigentes('2026-09-15')));
    igual(0, count(Metas::vigentes('2026-10-01')));
});

teste('metas: filtro de vigência da lista', function () {
    bancoComSeed();
    novaMeta('mrr', 100, date('Y-m-01'));
    novaMeta('mrr', 100, date('Y-m-d', strtotime('-3 months')));
    novaMeta('mrr', 100, date('Y-m-d', strtotime('+2 months')));
    $repo = Repositorios::metas();
    igual(3, $repo->listar()['total']);
    igual(1, $repo->listar(['filtros' => ['vigencia' => 'vigentes']])['total']);
    igual(1, $repo->listar(['filtros' => ['vigencia' => 'futuras']])['total']);
    igual(1, $repo->listar(['filtros' => ['vigencia' => 'encerradas']])['total']);
});

teste('metas: contexto do analista traz realizado × meta calculado pelo servidor', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $emp = novaEmpresa($x);
    negocioGanho($x, $emp, '4.000,00', '2026-09-03');
    novaMeta('faturamento', 1000000);
    novaMeta('negocios_ganhos', 2);

    $def = json_decode((string) file_get_contents(dirname(__DIR__) . '/library/analista-pipeline.agent.json'), true);
    verdadeiro(AgenteDefinicao::validar($def)['ok'], implode('; ', AgenteDefinicao::validar($def)['erros']));
    verdadeiro(isset($def['contexto_relacionado']['metas']), 'o analista pede as metas');

    $ctx = json_decode((new ContextBuilder())->paraAgente($def, null, null, null, '2026-09-15'), true);
    $metas = $ctx['relacionado']['metas'];
    igual(2, count($metas));
    $fat = array_values(array_filter($metas, static fn (array $m): bool => $m['meta'] === 'Faturamento'))[0];
    igual(10000, $fat['alvo'], 'reais, não centavos');
    igual(4000, $fat['realizado']);
    igual(40, $fat['percentual_atingido']);
    igual(50, $fat['ritmo_esperado_pct']);
    igual('Abaixo do ritmo', $fat['situacao']);
    igual(15, $fat['dias_restantes']);
    $ganhos = array_values(array_filter($metas, static fn (array $m): bool => $m['meta'] === 'Negócios ganhos'))[0];
    igual(2, $ganhos['alvo']);
    igual(1, $ganhos['realizado']);
});

teste('metas: card do Início mostra progresso, e sem meta convida a cadastrar', function () {
    bancoComSeed();
    contem('Nenhuma meta vigente', metas_card([]));
    novaMeta('negocios_ganhos', 2, date('Y-m-01'));
    $html = metas_card(Metas::vigentes());
    contem('Negócios ganhos (mensal)', $html);
    contem('0 de 2 (0%)', $html);
    contem('role="progressbar"', $html);
});
