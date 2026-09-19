<?php

declare(strict_types=1);

use App\Core\Response;
use App\Core\Router;

final class RotaFalsa
{
    public function ver(array $params): Response
    {
        return Response::texto('ver:' . $params['id']);
    }
}

teste('Router despacha GET com parâmetros', function () {
    $r = new Router();
    $r->get('/empresas/{id}', fn (array $p) => Response::texto('empresa ' . $p['id']));
    $resp = $r->despachar('GET', '/empresas/42');
    igual(200, $resp->status);
    igual('empresa 42', $resp->corpo);
});

teste('Router restringe parâmetros por regex e normaliza barras', function () {
    $r = new Router();
    $r->get('/negocios/{id:\d+}', fn (array $p) => 'n' . $p['id']);
    igual('n7', $r->despachar('GET', '/negocios/7/')->corpo, 'barra final');
    igual(404, $r->despachar('GET', '/negocios/abc')->status);
    igual('n7', $r->despachar('GET', '//negocios//7')->corpo, 'barras duplicadas');
});

teste('Router diferencia métodos: 405 e 404', function () {
    $r = new Router();
    $r->post('/salvar', fn () => 'ok');
    igual(405, $r->despachar('GET', '/salvar')->status);
    igual(404, $r->despachar('GET', '/nada')->status);
    igual('ok', $r->despachar('post', '/salvar')->corpo, 'método case-insensitive');
});

teste('Router suporta PUT e DELETE', function () {
    $r = new Router();
    $r->put('/x/{id}', fn (array $p) => 'put' . $p['id']);
    $r->delete('/x/{id}', fn (array $p) => 'del' . $p['id']);
    igual('put1', $r->despachar('PUT', '/x/1')->corpo);
    igual('del1', $r->despachar('DELETE', '/x/1')->corpo);
});

teste('Router agrupa rotas com prefixo /api e converte array em JSON', function () {
    $r = new Router();
    $r->grupo('/api', function (Router $r) {
        $r->get('/status', fn () => ['ok' => true, 'msg' => 'olá']);
        $r->grupo('v1', fn (Router $r) => $r->get('/itens/{id}', fn (array $p) => ['id' => $p['id']]));
    });
    $resp = $r->despachar('GET', '/api/status');
    contem('application/json', $resp->cabecalhos['Content-Type']);
    igual('{"ok":true,"msg":"olá"}', $resp->corpo);
    igual('{"id":"9"}', $r->despachar('GET', '/api/v1/itens/9')->corpo);
    igual(404, $r->despachar('GET', '/status')->status, 'fora do grupo não casa');
});

teste('Router chama controllers [Classe, método] e repassa opções da rota', function () {
    $r = new Router();
    $r->get('/c/{id}', [RotaFalsa::class, 'ver']);
    igual('ver:5', $r->despachar('GET', '/c/5')->corpo);

    $r->get('/op', fn (array $p, array $o) => $o['titulo'], ['titulo' => 'Título']);
    igual('Título', $r->despachar('GET', '/op')->corpo);
});

teste('Router executa middlewares globais e de grupo, e interrompe quando devolvem Response', function () {
    $ordem = [];
    $r = new Router();
    $r->global(function (array $ctx) use (&$ordem) {
        $ordem[] = 'global';
        return null;
    });
    $bloqueio = fn (array $ctx) => Response::redirecionar('/login');
    $r->grupo('/privado', function (Router $r) {
        $r->get('/a', fn () => 'segredo');
    }, [$bloqueio]);
    $r->get('/publico', fn () => 'aberto');

    $resp = $r->despachar('GET', '/privado/a');
    igual(302, $resp->status);
    igual('/login', $resp->cabecalhos['Location']);
    igual('aberto', $r->despachar('GET', '/publico')->corpo);
    igual(['global', 'global'], $ordem);
});

teste('Router entrega o contexto ao middleware', function () {
    $visto = null;
    $r = new Router();
    $r->global(function (array $ctx) use (&$visto) {
        $visto = $ctx;
        return null;
    });
    $r->post('/p/{x}', fn () => 'ok', ['csrf' => false]);
    $r->despachar('POST', '/p/1');
    igual('POST', $visto['metodo']);
    igual('/p/1', $visto['caminho']);
    igual(['x' => '1'], $visto['params']);
    igual(false, $visto['opcoes']['csrf']);
});

teste('Response.json e redirecionar montam status e cabeçalhos', function () {
    $j = Response::json(['a' => 'é'], 201);
    igual(201, $j->status);
    igual('{"a":"é"}', $j->corpo);
    $r = Response::redirecionar('/x', 303);
    igual(303, $r->status);
    igual('/x', $r->cabecalhos['Location']);
});
