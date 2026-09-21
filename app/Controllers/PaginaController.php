<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;
use App\Repositories\AcaoPendenteRepository;
use App\Repositories\Repositorios;
use App\Services\Metas;

/** Páginas do layout base (Início e telas ainda vazias, que ganham conteúdo nas próximas fases). */
final class PaginaController
{
    /** Dias sem movimento que tornam um negócio "parado", e janela dos contratos "vencendo" (SPEC §9). */
    public const DIAS_NEGOCIO_PARADO = 14;
    public const DIAS_CONTRATO_VENCENDO = 30;

    public function inicio(): Response
    {
        $repo = Repositorios::tarefas();
        $limite = date('Y-m-d', strtotime('+7 days'));
        $pipeline = Repositorios::pipelines()->padrao();
        return View::pagina('paginas/inicio', [
            'titulo'    => 'Início',
            'painel_chat' => false, // o chat fica central na página
            'atrasadas' => self::juntar($repo->grupo('atrasadas', hoje(), $limite), Repositorios::chamados()->grupo('atrasadas', hoje(), $limite)),
            'hoje'      => self::juntar($repo->grupo('hoje', hoje(), $limite), Repositorios::chamados()->grupo('hoje', hoje(), $limite)),
            'pipeline'  => $pipeline,
            'funil'     => $pipeline !== null ? Repositorios::negocios()->resumoPorEtapa((int) $pipeline['id']) : [],
            'parados'   => Repositorios::negocios()->parados(self::DIAS_NEGOCIO_PARADO),
            'contratos' => Repositorios::contratos()->vencendo(self::DIAS_CONTRATO_VENCENDO),
            'metas'     => Metas::vigentes(),
            'acoesPendentes' => (new AcaoPendenteRepository())->contarPendentes(),
        ]);
    }

    /**
     * Tarefas e chamados de um grupo (hoje, atrasadas) juntos e ordenados pelo prazo; cada item leva `tipo` (tarefa|chamado).
     * @return list<array>
     */
    private static function juntar(array $tarefas, array $chamados): array
    {
        $itens = [...array_map(static fn (array $t): array => $t + ['tipo_item' => 'tarefa'], $tarefas), ...array_map(static fn (array $c): array => $c + ['tipo_item' => 'chamado'], $chamados)];
        usort($itens, static fn (array $a, array $b): int => [(string) $a['vencimento'], -(int) $a['id']] <=> [(string) $b['vencimento'], -(int) $b['id']]);
        return $itens;
    }

    /** Opções da rota: titulo, icone, fase. */
    public function emBreve(array $params, array $opcoes): Response
    {
        return View::pagina('paginas/em_breve', [
            'titulo' => $opcoes['titulo'] ?? 'Em breve',
            'icone'  => $opcoes['icone'] ?? 'inbox',
            'fase'   => $opcoes['fase'] ?? 'próxima fase',
        ]);
    }

    public function guiaEstilo(): Response
    {
        return View::pagina('paginas/ui', ['titulo' => 'Guia de estilo']);
    }

    /** GET /api/ping — verificação simples de sessão para o JS. */
    public function ping(): Response
    {
        return Response::json(['ok' => true, 'hora' => agora()]);
    }
}
