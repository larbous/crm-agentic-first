<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AcaoPendenteRepository;
use App\Repositories\Repositorios;
use App\Services\AI\AgentRunner;

/** Ações de agentes aguardando aprovação (SPEC §9): diff antes/depois, aprovar/rejeitar individual ou em lote. */
final class AcaoPendenteController
{
    public static function registrar(Router $r): void
    {
        $r->get('/acoes-pendentes', [self::class, 'index']);
        $r->post('/acoes-pendentes/lote', [self::class, 'lote']);
        $r->post('/acoes-pendentes/{id:\d+}/aprovar', [self::class, 'aprovar']);
        $r->post('/acoes-pendentes/{id:\d+}/rejeitar', [self::class, 'rejeitar']);
    }

    public function index(): Response
    {
        $repo = new AcaoPendenteRepository();
        $grupos = [];
        foreach ($repo->pendentes() as $p) {
            $x = (int) $p['execucao_id'];
            if (!isset($grupos[$x])) {
                $alvo = null;
                if ($p['alvo_entidade'] !== null && $p['alvo_id'] !== null) {
                    $reg = Repositorios::para((string) $p['alvo_entidade'])->encontrar((int) $p['alvo_id'], true);
                    $alvo = $reg !== null ? ['entidade' => $p['alvo_entidade'], 'id' => (int) $p['alvo_id'], 'nome' => self::nomeDe($reg)] : null;
                }
                $grupos[$x] = ['execucao_id' => $x, 'agente' => (string) ($p['agente_nome'] ?? '?'), 'criado_em' => $p['criado_em'], 'alvo' => $alvo, 'acoes' => []];
            }
            $grupos[$x]['acoes'][] = $p;
        }
        return View::pagina('acoes_pendentes/index', [
            'titulo'   => 'Ações pendentes',
            'grupos'   => array_values($grupos),
            'decididas' => $repo->decididas(15),
        ]);
    }

    public function aprovar(array $p): Response
    {
        return $this->decidir([(int) $p['id']], true);
    }

    public function rejeitar(array $p): Response
    {
        return $this->decidir([(int) $p['id']], false);
    }

    /** POST /acoes-pendentes/lote {decisao: aprovar|rejeitar, ids[]} */
    public function lote(): Response
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn (int $i): bool => $i > 0)));
        sort($ids);
        if ($ids === []) {
            Session::flash('error', 'Selecione ao menos uma ação.');
            return Response::redirecionar(url('/acoes-pendentes'));
        }
        return $this->decidir($ids, ($_POST['decisao'] ?? '') === 'aprovar');
    }

    /** @param list<int> $ids em ordem crescente (a ordem em que o agente propôs) */
    private function decidir(array $ids, bool $aprovar): Response
    {
        $runner = new AgentRunner();
        $ok = 0;
        $falhas = [];
        foreach ($ids as $id) {
            $r = $runner->decidir($id, $aprovar);
            if ($r['ok']) {
                $ok++;
            } else {
                $falhas[] = "#{$id}: {$r['mensagem']}";
            }
        }
        $verbo = $aprovar ? 'aprovada(s) e aplicada(s)' : 'rejeitada(s)';
        if ($falhas === []) {
            Session::flash('success', "{$ok} ação(ões) {$verbo}.");
        } else {
            Session::flash('error', ($ok > 0 ? "{$ok} ação(ões) {$verbo}. " : '') . 'Falhou: ' . implode(' ', array_slice($falhas, 0, 3)));
        }
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/acoes-pendentes')));
    }

    private static function nomeDe(array $registro): string
    {
        return (string) ($registro['nome_fantasia'] ?? (isset($registro['numero']) ? $registro['numero'] . ' — ' . ($registro['titulo'] ?? '') : ($registro['titulo'] ?? trim(($registro['nome'] ?? '') . ' ' . ($registro['sobrenome'] ?? '')))));
    }
}
