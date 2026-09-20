<?php

declare(strict_types=1);

/**
 * Roda cada agente da biblioteca (/library/*.agent.json) sobre dados de exemplo (ROADMAP Fase 5: "cada agente da
 * biblioteca roda em um registro real, respeita whitelist e aprovação, e registra tokens").
 *
 * Uso:
 *   php scripts/avaliar-agentes.php              chama a API real (exige anthropic.api_key em config.local.php)
 *   php scripts/avaliar-agentes.php --offline    só valida as definições e mostra o prompt de sistema (sem API, sem custo)
 *   php scripts/avaliar-agentes.php pesquisador  roda só o agente indicado
 *
 * Roda num banco SQLite em memória (não toca em storage/db/crm.sqlite) e com aprovação "nunca", para que as ações
 * válidas cheguem ao ActionExecutor e as regras de negócio também sejam exercitadas. Cada agente vê só o seu contexto.
 * Um agente passa quando a resposta é um JSON válido no formato do SPEC §6.1 e nenhuma ação é recusada.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;
use App\Repositories\AgenteRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\MigracaoRepository;
use App\Repositories\SeedRepository;
use App\Services\ActionExecutor;
use App\Services\AI\AgentRunner;
use App\Services\AI\IaErro;

$offline = in_array('--offline', $argv, true);
$filtro = null;
foreach (array_slice($argv, 1) as $a) {
    if (!str_starts_with($a, '--')) {
        $filtro = $a;
    }
}

// ---- Banco em memória com seed e dados de exemplo ------------------------------------------

$pdo = DB::conectar(':memory:');
$migracoes = new MigracaoRepository($pdo);
$migracoes->garantirTabela();
$arquivos = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($arquivos);
foreach ($arquivos as $arquivo) {
    $migracoes->aplicar(basename($arquivo), (string) file_get_contents($arquivo));
}
DB::definir($pdo);

$seed = new SeedRepository($pdo);
$seed->garantirPipeline('Vendas', [
    ['Novo lead', 10, '#64748b', 'aberta'], ['Qualificado', 25, '#0ea5e9', 'aberta'], ['Reunião', 40, '#8b5cf6', 'aberta'],
    ['Proposta', 60, '#f59e0b', 'aberta'], ['Negociação', 80, '#f97316', 'aberta'], ['Ganho', 100, '#22c55e', 'ganho'], ['Perdido', 0, '#ef4444', 'perdido'],
]);
$seed->garantirNomes('origens', ['Indicação', 'Instagram', 'Google', 'Site', 'Formulário', 'WhatsApp', 'Evento', 'Prospecção ativa', 'Outro']);
$seed->garantirNomes('motivos_perda', ['Preço', 'Prazo', 'Escolheu concorrente', 'Sem orçamento', 'Sem resposta', 'Projeto adiado', 'Outro']);
$seed->garantirNomes('contrato_tipos', ['Desenvolvimento', 'Manutenção', 'Hospedagem', 'Consultoria', 'Tráfego']);
foreach (require dirname(__DIR__) . '/library/modelos.php' as [$tipo, $nome, $assunto, $conteudo]) {
    $seed->garantirModelo($tipo, $nome, $assunto, $conteudo);
}

$x = new ActionExecutor();
$agentes = new AgenteRepository();
foreach (glob(dirname(__DIR__) . '/library/*.agent.json') ?: [] as $arquivo) {
    $r = $x->salvarAgente((string) file_get_contents($arquivo));
    if (!$r->ok) {
        fwrite(STDERR, basename($arquivo) . ': ' . $r->mensagem . "\n");
        exit(1);
    }
}

if ($offline) {
    foreach ($agentes->todos() as $a) {
        echo str_pad($a['slug'], 20) . " v{$a['versao']}  {$a['def']['modelo']}  entrada={$a['def']['entrada']}  aprovação={$a['def']['aprovacao']}\n";
        if (in_array('--verbose', $argv, true)) {
            echo AgentRunner::sistema($a['def']) . "\n\n";
        }
    }
    echo count($agentes->todos()) . " agente(s) válido(s).\n";
    exit(0);
}

$dado = static function (string $entidade, array $dados) use ($x): int {
    $r = $x->criar($entidade, $dados, 'humano');
    if (!$r->ok) {
        fwrite(STDERR, "Dados de exemplo ({$entidade}): {$r->mensagem}\n");
        exit(1);
    }
    return (int) $r->id;
};

$empresa = $dado('empresas', [
    'nome_fantasia' => 'Padaria Pão Nobre', 'razao_social' => 'Pão Nobre Alimentos LTDA', 'segmento' => 'Alimentação', 'cidade' => 'Londrina', 'uf' => 'PR',
    'site' => 'paonobre.com.br', 'instagram' => '@paonobrelondrina', 'status' => 'prospect', 'origem_id' => 3, 'faixa_faturamento' => 'R$ 30 mil a R$ 100 mil/mês',
    'notas' => 'Chegou pelo formulário do site: "Quero um site novo com cardápio e pedidos por WhatsApp. Verba de uns 8 mil."',
]);
$contato = $dado('contatos', ['nome' => 'Ana', 'sobrenome' => 'Souza', 'empresa_id' => $empresa, 'cargo' => 'Proprietária', 'papel_decisao' => 'decisor', 'whatsapp' => '43999990000']);
foreach ([
    ['Site institucional', 'site', 'projeto', '6.500,00', 0], ['Loja virtual', 'ecommerce', 'projeto', '12.000,00', 0],
    ['Hospedagem gerenciada', 'hospedagem', 'mes', '90,00', 1], ['Manutenção mensal', 'manutencao', 'mes', '250,00', 1],
] as [$nome, $cat, $un, $preco, $rec]) {
    $dado('servicos', ['nome' => $nome, 'categoria' => $cat, 'unidade' => $un, 'preco_base' => $preco, 'recorrente' => $rec, 'ativo' => 1, 'descricao' => "Serviço de catálogo: {$nome}."]);
}
$negocio = $dado('negocios', [
    'titulo' => 'Site novo Pão Nobre', 'empresa_id' => $empresa, 'contato_principal_id' => $contato, 'etapa_id' => 4, 'valor_estimado' => '8.000,00',
    'dor_principal' => 'Site antigo não abre no celular e não recebe pedidos', 'orcamento_cliente' => 'até 8 mil', 'prazo_desejado' => 'antes do Natal',
]);
$dado('atividades', ['tipo' => 'reuniao', 'assunto' => 'Primeira conversa', 'descricao' => 'Ana quer cardápio online e botão de WhatsApp. Concorrente Padaria do Zé tem site novo.', 'empresa_id' => $empresa, 'negocio_id' => $negocio, 'data_hora' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
$negocioLoja = $dado('negocios', ['titulo' => 'Loja virtual Pão Nobre', 'empresa_id' => $empresa, 'contato_principal_id' => $contato, 'etapa_id' => 3, 'valor_estimado' => '12.000,00', 'dor_principal' => 'Quer vender pães congelados para todo o estado']);
$negocioParado = $dado('negocios', ['titulo' => 'Manutenção antiga', 'empresa_id' => $empresa, 'etapa_id' => 2, 'valor_estimado' => '3.000,00', 'previsao_fechamento' => date('Y-m-d', strtotime('-20 days'))]);
$pdo->prepare('UPDATE negocios SET entrou_etapa_em = :d WHERE id = :id')->execute(['d' => date('Y-m-d H:i:s', strtotime('-30 days')), 'id' => $negocioParado]);
$dado('tarefas', ['titulo' => 'Enviar portfólio', 'vencimento' => date('Y-m-d', strtotime('-5 days')), 'negocio_id' => $negocioParado, 'empresa_id' => $empresa]);

// Proposta aceita + contrato (rascunho) para redator-contrato e renovacao
$proposta = $dado('propostas', [
    'titulo' => 'Site institucional Pão Nobre', 'negocio_id' => $negocio, 'forma_pagamento' => '50% na entrada e 50% na entrega', 'parcelas' => 2,
    'itens' => [['servico_id' => 1, 'descricao' => 'Site institucional', 'quantidade' => 1, 'valor_unitario' => '6.500,00'], ['servico_id' => 3, 'descricao' => 'Hospedagem gerenciada', 'quantidade' => 12, 'valor_unitario' => '90,00', 'recorrente' => 1]],
]);
$x->enviarProposta($proposta, 'humano');
$x->responderProposta($proposta, 'aceita', ['nome' => 'Ana Souza', 'documento' => '529.982.247-25', 'concordo' => 1, 'ip' => '127.0.0.1'], 'sistema');
$contrato = $dado('contratos', [
    'titulo' => 'Contrato Site institucional — Pão Nobre', 'proposta_id' => $proposta, 'recorrencia' => 'mensal', 'valor_mensal' => '90,00', 'valor_total' => '7.580,00',
    'data_inicio' => date('Y-m-d', strtotime('-11 months')), 'data_fim' => date('Y-m-d', strtotime('+25 days')), 'aviso_renovacao_dias' => 30, 'indice_reajuste' => 'ipca',
]);
$pdo->prepare("UPDATE contratos SET status = 'ativo' WHERE id = :id")->execute(['id' => $contrato]);
// Negócio com proposta aceita e ainda sem contrato (redator-contrato)
$negocioContrato = $dado('negocios', ['titulo' => 'Manutenção mensal Pão Nobre', 'empresa_id' => $empresa, 'contato_principal_id' => $contato, 'etapa_id' => 5, 'valor_estimado' => '250,00', 'tipo_receita' => 'mensal', 'valor_recorrente' => '250,00']);
$propostaManutencao = $dado('propostas', [
    'titulo' => 'Manutenção mensal Pão Nobre', 'negocio_id' => $negocioContrato, 'forma_pagamento' => 'Boleto mensal, vencimento dia 10',
    'itens' => [['servico_id' => 4, 'descricao' => 'Manutenção mensal', 'quantidade' => 12, 'valor_unitario' => '250,00', 'recorrente' => 1]],
]);
$x->enviarProposta($propostaManutencao, 'humano');
$x->responderProposta($propostaManutencao, 'aceita', ['nome' => 'Ana Souza', 'documento' => '529.982.247-25', 'concordo' => 1, 'ip' => '127.0.0.1'], 'sistema');
$dado('tarefas', ['titulo' => 'Ligar para Ana', 'vencimento' => date('Y-m-d', strtotime('-2 days')), 'negocio_id' => $negocioLoja, 'empresa_id' => $empresa]);

// Registro-alvo e texto de apoio de cada agente
$casos = [
    'pesquisador'        => [$empresa, null],
    'qualificador'       => [$negocio, null],
    'triagem-formulario' => [$empresa, null],
    'redator-followup'   => [$negocio, null],
    'resumidor-reuniao'  => [$negocio, "Reunião de hoje com a Ana (43 min). Ela gostou do layout de referência, quer fechar até dia 15. O concorrente Padaria do Zé cobrou 5 mil, mas sem cardápio online. Ela disse que o orçamento pode chegar a 9 mil se incluir pedidos por WhatsApp. Combinamos que enviamos a proposta até sexta e que ela manda as fotos dos pães até quarta."],
    'montador-proposta'  => [$negocioLoja, null],
    'redator-contrato'   => [$negocioContrato, null],
    'renovacao'          => [$contrato, null],
    'analista-pipeline'  => [null, null],
];

$runner = new AgentRunner();
$passaram = 0;
$total = 0;
foreach ($casos as $slug => [$registro, $texto]) {
    if ($filtro !== null && $filtro !== $slug) {
        continue;
    }
    $agente = $agentes->porSlug($slug);
    $agente['def']['aprovacao'] = 'nunca'; // só nesta execução (não é salvo): exercita o ActionExecutor de ponta a ponta
    $total++;
    echo str_repeat('─', 78) . "\n{$slug} ({$agente['def']['modelo']})\n";
    try {
        $r = $runner->executar($agente, $registro, $texto);
    } catch (IaErro $e) {
        echo "  ERRO DE IA: {$e->getMessage()}\n";
        continue;
    } catch (InvalidArgumentException $e) {
        echo "  ERRO: {$e->getMessage()}\n";
        continue;
    }
    $exec = (new ExecucaoRepository())->encontrar($r['execucao_id']);
    $passou = $r['ok'] && $r['recusadas'] === [];
    $passaram += $passou ? 1 : 0;
    echo '  ' . ($passou ? 'PASSOU' : 'FALHOU') . " · {$exec['tokens_entrada']} tokens de entrada / {$exec['tokens_saida']} de saída · {$exec['duracao_ms']} ms\n";
    if (!$r['ok']) {
        echo "  erro: {$r['erro']}\n";
        continue;
    }
    echo '  resumo: ' . mb_strimwidth($r['resumo'], 0, 300, '…') . "\n";
    if ($r['status'] !== null) {
        echo "  status: {$r['status']}\n";
    }
    foreach ($r['aplicadas'] as $a) {
        echo "  ✓ {$a['descricao']}\n";
    }
    foreach ($r['recusadas'] as $m) {
        echo "  ✗ {$m}\n";
    }
    if (in_array('--verbose', $argv, true) && $r['texto'] !== '') {
        echo "  texto: " . mb_strimwidth(str_replace("\n", ' / ', $r['texto']), 0, 600, '…') . "\n";
    }
}
echo str_repeat('─', 78) . "\n{$passaram}/{$total} agente(s) passaram.\n";
exit($passaram === $total ? 0 : 1);
