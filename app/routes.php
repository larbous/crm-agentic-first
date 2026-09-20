<?php

declare(strict_types=1);

use App\Controllers\AcaoPendenteController;
use App\Controllers\AgenteController;
use App\Controllers\AnexoController;
use App\Controllers\AtividadeController;
use App\Controllers\AuditoriaController;
use App\Controllers\AuthController;
use App\Controllers\BuscaController;
use App\Controllers\ChatController;
use App\Controllers\ConfiguracaoController;
use App\Controllers\ContatoController;
use App\Controllers\ContratoController;
use App\Controllers\CrudController;
use App\Controllers\EmpresaController;
use App\Controllers\ExecucaoController;
use App\Controllers\NegocioController;
use App\Controllers\ModeloController;
use App\Controllers\PaginaController;
use App\Controllers\PropostaController;
use App\Controllers\PublicoController;
use App\Controllers\ServicoController;
use App\Controllers\SquadController;
use App\Controllers\TagController;
use App\Controllers\TarefaController;
use App\Core\Auth;
use App\Core\Router;

/** Definição de rotas. Rotas JSON ficam sob /api; telas, sem prefixo. */
return static function (Router $r): void {
    // Públicas
    $r->get('/login', [AuthController::class, 'formLogin']);
    $r->post('/login', [AuthController::class, 'login']);

    // Links públicos de proposta e contrato (só por token)
    PublicoController::registrar($r);

    // Autenticadas
    $r->grupo('', static function (Router $r): void {
        $r->get('/', [PaginaController::class, 'inicio']);
        $r->post('/logout', [AuthController::class, 'logout']);
        $r->get('/ui', [PaginaController::class, 'guiaEstilo']);

        // CRUDs principais
        CrudController::registrar($r, '/empresas', EmpresaController::class);
        $r->post('/empresas/{id:\d+}/converter', [EmpresaController::class, 'converter']);
        CrudController::registrar($r, '/contatos', ContatoController::class);
        CrudController::registrar($r, '/negocios', NegocioController::class);
        NegocioController::registrarExtras($r);
        TarefaController::registrarRotas($r);

        // Comercial: catálogo, modelos, propostas e contratos
        CrudController::registrar($r, '/servicos', ServicoController::class);
        CrudController::registrar($r, '/modelos', ModeloController::class);
        ModeloController::registrarExtras($r);
        CrudController::registrar($r, '/propostas', PropostaController::class);
        PropostaController::registrarExtras($r);
        CrudController::registrar($r, '/contratos', ContratoController::class);
        ContratoController::registrarExtras($r);

        // Agentes e squads de IA, ações pendentes de aprovação e execuções
        AgenteController::registrar($r);
        SquadController::registrar($r);
        AcaoPendenteController::registrar($r);
        ExecucaoController::registrar($r);

        // Timeline, anexos e tags
        $r->post('/atividades', [AtividadeController::class, 'criar']);
        $r->post('/atividades/{id:\d+}/arquivar', [AtividadeController::class, 'arquivar']);
        $r->post('/anexos', [AnexoController::class, 'enviar']);
        $r->get('/anexos/{id:\d+}', [AnexoController::class, 'baixar']);
        $r->post('/anexos/{id:\d+}/arquivar', [AnexoController::class, 'arquivar']);
        $r->post('/tags/definir', [TagController::class, 'definir']);

        // Auditoria e desfazer
        $r->get('/auditoria', [AuditoriaController::class, 'index']);
        $r->post('/auditoria/{id:\d+}/desfazer', [AuditoriaController::class, 'desfazer']);
        $r->post('/desfazer', [AuditoriaController::class, 'desfazerUltima']);

        // Configurações
        $tipos = 'pipelines|etapas|origens|motivos-perda|tags|contrato-tipos';
        $r->get('/configuracoes', [ConfiguracaoController::class, 'index']);
        $r->post('/configuracoes/agencia', [ConfiguracaoController::class, 'salvarAgencia']);
        $r->post("/configuracoes/{tipo:{$tipos}}", [ConfiguracaoController::class, 'criar']);
        $r->post("/configuracoes/{tipo:{$tipos}}/{id:\\d+}", [ConfiguracaoController::class, 'atualizar']);
        $r->post("/configuracoes/{tipo:{$tipos}}/{id:\\d+}/arquivar", [ConfiguracaoController::class, 'arquivar']);

        $r->grupo('/api', static function (Router $r): void {
            $r->get('/ping', [PaginaController::class, 'ping']);
            $r->get('/busca', [BuscaController::class, 'buscar']);
            $r->get('/chat/historico', [ChatController::class, 'historico']);
            $r->post('/chat', [ChatController::class, 'enviar']);
            $r->post('/chat/acao', [ChatController::class, 'acao']);
        });
    }, [[Auth::class, 'exigir']]);
};
