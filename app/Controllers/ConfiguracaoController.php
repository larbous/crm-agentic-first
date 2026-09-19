<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Schema;

/** Configurações: pipelines/etapas, origens, motivos de perda e tags (tudo via ActionExecutor). */
final class ConfiguracaoController
{
    /** segmento da URL => [entidade, aba] */
    private const TIPOS = [
        'pipelines'     => ['pipelines', 'pipelines'],
        'etapas'        => ['etapas', 'pipelines'],
        'origens'       => ['origens', 'origens'],
        'motivos-perda' => ['motivos_perda', 'motivos'],
        'tags'          => ['tags', 'tags'],
    ];

    public function index(): Response
    {
        $etapasPorPipeline = [];
        foreach (Repositorios::etapas()->todas() as $e) {
            $etapasPorPipeline[(int) $e['pipeline_id']][] = $e;
        }
        return View::pagina('configuracoes/index', [
            'titulo'    => 'Configurações',
            'aba'       => in_array($_GET['aba'] ?? '', ['pipelines', 'origens', 'motivos', 'tags'], true) ? $_GET['aba'] : 'pipelines',
            'pipelines' => Repositorios::pipelines()->todas(),
            'etapas'    => $etapasPorPipeline,
            'origens'   => Repositorios::para('origens')->todas(),
            'motivos'   => Repositorios::para('motivos_perda')->todas(),
            'tags'      => Repositorios::tags()->todas(),
        ]);
    }

    public function criar(array $p): Response
    {
        [$entidade, $aba] = $this->tipo($p['tipo']);
        $r = (new ActionExecutor())->criar($entidade, Schema::filtrar($entidade, $_POST), 'humano');
        return $this->responder($r->ok, $r->mensagem, $aba);
    }

    public function atualizar(array $p): Response
    {
        [$entidade, $aba] = $this->tipo($p['tipo']);
        // O pipeline de uma etapa não muda por aqui.
        $entrada = array_diff_key(Schema::filtrar($entidade, $_POST), ['pipeline_id' => 1]);
        $r = (new ActionExecutor())->atualizar($entidade, (int) $p['id'], $entrada, 'humano');
        return $this->responder($r->ok, $r->mensagem, $aba);
    }

    public function arquivar(array $p): Response
    {
        [$entidade, $aba] = $this->tipo($p['tipo']);
        $r = (new ActionExecutor())->arquivar($entidade, (int) $p['id'], 'humano');
        return $this->responder($r->ok, $r->mensagem, $aba);
    }

    /** @return array{0:string,1:string} */
    private function tipo(string $segmento): array
    {
        return self::TIPOS[$segmento] ?? throw new \InvalidArgumentException('Tipo de configuração inválido.');
    }

    private function responder(bool $ok, string $mensagem, string $aba): Response
    {
        if (!$ok || ($mensagem !== '' && $mensagem !== 'Nada a alterar.')) {
            Session::flash($ok ? 'success' : 'error', $mensagem);
        }
        return Response::redirecionar(url('/configuracoes?aba=' . $aba));
    }
}
