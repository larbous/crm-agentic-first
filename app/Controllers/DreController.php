<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Repositories\Repositorios;
use App\Services\Opcoes;

/**
 * DRE por cliente (Fase 17): relatório calculado, não uma tela de cadastro. Por período e por empresa:
 * Receita (cobranças pagas com data de pagamento no período) − Custo (lançamentos no período) = Margem.
 */
final class DreController
{
    public static function registrar(Router $r): void
    {
        $r->get('/financeiro/dre', [self::class, 'index']);
    }

    public function index(): Response
    {
        $de = data_iso((string) ($_GET['de'] ?? '')) ?? date('Y-m-01');
        $ate = data_iso((string) ($_GET['ate'] ?? '')) ?? hoje();
        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }
        $empresaFiltro = ctype_digit((string) ($_GET['empresa_id'] ?? '')) ? (int) $_GET['empresa_id'] : 0;

        $receitas = Repositorios::cobrancas()->pagoPorEmpresaNoPeriodo($de, $ate);
        $custos = Repositorios::custos()->porEmpresaNoPeriodo($de, $ate);
        $nomes = Opcoes::para('empresas');

        $linhas = [];
        foreach (array_unique([...array_keys($receitas), ...array_keys($custos)]) as $empresaId) {
            if ($empresaFiltro > 0 && $empresaId !== $empresaFiltro) {
                continue;
            }
            $receita = $receitas[$empresaId] ?? 0;
            $custo = $custos[$empresaId] ?? 0;
            $linhas[] = [
                'empresa_id' => $empresaId, 'empresa_nome' => $nomes[$empresaId] ?? ('#' . $empresaId),
                'receita' => $receita, 'custo' => $custo, 'margem' => $receita - $custo,
            ];
        }
        usort($linhas, static fn (array $a, array $b): int => strnatcasecmp((string) $a['empresa_nome'], (string) $b['empresa_nome']));

        $totais = ['receita' => array_sum(array_column($linhas, 'receita')), 'custo' => array_sum(array_column($linhas, 'custo'))];
        $totais['margem'] = $totais['receita'] - $totais['custo'];

        return View::pagina('financeiro/dre', [
            'titulo' => 'DRE por cliente', 'de' => $de, 'ate' => $ate, 'empresaFiltro' => $empresaFiltro,
            'empresasOpcoes' => $nomes, 'linhas' => $linhas, 'totais' => $totais,
        ]);
    }
}
