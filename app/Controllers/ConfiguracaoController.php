<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\CamposExtras;
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
        'contrato-tipos' => ['contrato_tipos', 'contratos'],
        'areas'         => ['areas', 'areas'],
        'campos-extras' => ['campos_extras_def', 'extras'],
    ];

    public function index(): Response
    {
        $etapasPorPipeline = [];
        foreach (Repositorios::etapas()->todas() as $e) {
            $etapasPorPipeline[(int) $e['pipeline_id']][] = $e;
        }
        return View::pagina('configuracoes/index', [
            'titulo'    => 'Configurações',
            'aba'       => in_array($_GET['aba'] ?? '', ['pipelines', 'origens', 'motivos', 'tags', 'contratos', 'areas', 'extras', 'agencia'], true) ? $_GET['aba'] : 'pipelines',
            'pipelines' => Repositorios::pipelines()->todas(),
            'etapas'    => $etapasPorPipeline,
            'origens'   => Repositorios::para('origens')->todas(),
            'motivos'   => Repositorios::para('motivos_perda')->todas(),
            'tags'      => Repositorios::tags()->todas(),
            'tiposContrato' => Repositorios::para('contrato_tipos')->todas(),
            'areas'     => Repositorios::para('areas')->todas(),
            'camposExtras' => array_combine(CamposExtras::ENTIDADES, array_map(
                static fn (string $e): array => Repositorios::camposExtras()->daEntidade($e, false),
                CamposExtras::ENTIDADES,
            )),
            'agencia'   => DocumentoDados::agencia() + ['validade_dias' => (new \App\Repositories\ConfiguracaoRepository())->obter('proposta.validade_dias', '15')],
        ]);
    }

    /** Dados da agência (usados nas variáveis {larbous.*} e nos documentos) e validade padrão das propostas. */
    public function salvarAgencia(): Response
    {
        $x = new ActionExecutor();
        foreach (array_keys(\App\Services\Variaveis::CAMPOS_AGENCIA) as $campo) {
            $valor = trim((string) ($_POST[$campo] ?? ''));
            if (mb_strlen($valor) > 300 || !mb_check_encoding($valor, 'UTF-8')) {
                return $this->responder(false, "O campo {$campo} é inválido ou muito longo.", 'agencia');
            }
            $x->definirConfiguracao('empresa.' . $campo, $valor === '' ? null : $valor);
        }
        $dias = trim((string) ($_POST['validade_dias'] ?? '15'));
        if (!ctype_digit($dias) || (int) $dias < 1 || (int) $dias > 365) {
            return $this->responder(false, 'A validade padrão deve ficar entre 1 e 365 dias.', 'agencia');
        }
        $x->definirConfiguracao('proposta.validade_dias', $dias);
        return $this->responder(true, 'Dados da agência salvos.', 'agencia');
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
        // O pipeline de uma etapa e a entidade/chave de um campo extra não mudam por aqui.
        $entrada = array_diff_key(Schema::filtrar($entidade, $_POST), ['pipeline_id' => 1, 'entidade' => 1, 'chave' => 1]);
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
