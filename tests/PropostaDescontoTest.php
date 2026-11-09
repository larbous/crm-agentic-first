<?php
declare(strict_types=1);
use App\Services\ActionExecutor;
teste('Proposta: desconto em branco no formulário vale zero (criar)', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $r = $x->criar('propostas', ['titulo' => 'X', 'desconto_valor' => '', 'desconto_tipo' => 'valor', 'layout' => 'padrao', 'itens' => itensExemplo()]);
    verdadeiro($r->ok, json_encode($r->erros));
});
teste('Proposta: desconto em branco no formulário vale zero (atualizar)', function () {
    bancoComSeed();
    $x = new ActionExecutor();
    $id = (int) $x->criar('propostas', ['titulo' => 'X', 'desconto_valor' => '10,00', 'itens' => itensExemplo()])->id;
    $r = $x->atualizar('propostas', $id, ['desconto_valor' => '']);
    verdadeiro($r->ok, json_encode($r->erros));
    igual(794000, (int) \App\Repositories\Repositorios::propostas()->encontrar($id)['total']);
});
