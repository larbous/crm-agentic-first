<?php

declare(strict_types=1);

namespace App\Services;

/** Regras do NPS: categoria de uma nota (0–10) e o índice de um conjunto de respostas. */
final class Nps
{
    public const CATEGORIAS = ['promotor' => 'Promotor', 'neutro' => 'Neutro', 'detrator' => 'Detrator'];

    /** 9–10 promotor, 7–8 neutro, 0–6 detrator. */
    public static function categoria(int $nota): string
    {
        return match (true) {
            $nota >= 9 => 'promotor',
            $nota >= 7 => 'neutro',
            default => 'detrator',
        };
    }

    /**
     * @param array<int,int> $contagemPorNota nota => quantidade
     * @return array{total:int,promotores:int,neutros:int,detratores:int,nps:?int,pct_promotores:int,pct_neutros:int,pct_detratores:int}
     */
    public static function resumir(array $contagemPorNota): array
    {
        $p = $n = $d = 0;
        foreach ($contagemPorNota as $nota => $qtd) {
            match (self::categoria((int) $nota)) {
                'promotor' => $p += (int) $qtd,
                'neutro' => $n += (int) $qtd,
                'detrator' => $d += (int) $qtd,
            };
        }
        $total = $p + $n + $d;
        $pct = static fn (int $x): int => $total > 0 ? (int) round($x * 100 / $total) : 0;
        return [
            'total' => $total, 'promotores' => $p, 'neutros' => $n, 'detratores' => $d,
            // NPS = % promotores − % detratores, de −100 a 100; sem respostas não há índice.
            'nps' => $total > 0 ? (int) round(($p - $d) * 100 / $total) : null,
            'pct_promotores' => $pct($p), 'pct_neutros' => $pct($n), 'pct_detratores' => $pct($d),
        ];
    }

    /** Faixa de leitura comum do NPS (zona de qualidade), para dar contexto ao número. */
    public static function zona(?int $nps): string
    {
        return match (true) {
            $nps === null => '—',
            $nps >= 75 => 'Excelência',
            $nps >= 50 => 'Qualidade',
            $nps >= 0 => 'Aperfeiçoamento',
            default => 'Crítica',
        };
    }
}
