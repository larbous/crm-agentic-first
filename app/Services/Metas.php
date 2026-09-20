<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Repositorios;
use DateTimeImmutable;

/**
 * Metas comerciais (SPEC §4.11): fim do período, rótulos e progresso calculado em tempo real.
 *
 * Realizado por tipo (sempre do início da meta até hoje, ou até o fim se já passou):
 *  - faturamento: soma do valor fechado dos negócios ganhos com data de fechamento no período (centavos);
 *  - negocios_ganhos: quantidade desses negócios;
 *  - novos_clientes: empresas cujo `cliente_desde` cai no período;
 *  - propostas_enviadas: propostas (por número) com envio no período;
 *  - mrr: soma do valor mensal dos contratos vigentes na data de referência (foto do momento, não acumulado).
 */
final class Metas
{
    public const MESES_DO_PERIODO = ['mensal' => 1, 'trimestral' => 3, 'anual' => 12];

    public const SITUACOES = [
        'futura'       => ['Começa em breve', 'secondary'],
        'no_ritmo'     => ['No ritmo', 'success'],
        'abaixo_ritmo' => ['Abaixo do ritmo', 'warning'],
        'em_andamento' => ['Em andamento', 'info'],
        'atingida'     => ['Atingida', 'success'],
        'nao_atingida' => ['Não atingida', 'destructive'],
    ];

    /** Último dia do período que começa em $inicio (ISO): início + N meses − 1 dia, sem estourar meses curtos. */
    public static function fimDoPeriodo(string $inicio, string $periodo): ?string
    {
        $meses = self::MESES_DO_PERIODO[$periodo] ?? null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        if ($meses === null || $d === false || $d->format('Y-m-d') !== $inicio) {
            return null;
        }
        $total = (int) $d->format('n') - 1 + $meses;
        $ano = (int) $d->format('Y') + intdiv($total, 12);
        $mes = $total % 12 + 1;
        $ultimoDia = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes)))->format('t');
        $proximoInicio = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, min((int) $d->format('j'), $ultimoDia)));
        return $proximoInicio->modify('-1 day')->format('Y-m-d');
    }

    public static function monetaria(string $tipo): bool
    {
        return in_array($tipo, ['faturamento', 'mrr'], true);
    }

    /** Valor de alvo/realizado legível: dinheiro para faturamento e MRR, número para os demais. */
    public static function formatarValor(string $tipo, int $valor): string
    {
        return self::monetaria($tipo) ? moeda($valor) : number_format($valor, 0, ',', '.');
    }

    public static function rotulo(array $meta): string
    {
        $tipo = Schema::opcoes('tipo_meta')[$meta['tipo']] ?? (string) $meta['tipo'];
        $periodo = mb_strtolower(Schema::opcoes('periodo_meta')[$meta['periodo']] ?? (string) $meta['periodo']);
        return "{$tipo} ({$periodo}) · " . data_br($meta['data_inicio']) . ' a ' . data_br($meta['data_fim']);
    }

    /**
     * @return array{rotulo:string,tipo:string,periodo:string,inicio:string,fim:string,alvo:int,realizado:int,percentual:int,
     *               esperado:?int,situacao:string,dias_restantes:int,alvo_txt:string,realizado_txt:string}
     */
    public static function progresso(array $meta, ?string $hoje = null): array
    {
        $hoje ??= hoje();
        $tipo = (string) $meta['tipo'];
        $inicio = (string) $meta['data_inicio'];
        $fim = (string) $meta['data_fim'];
        $alvo = (int) $meta['valor_alvo'];
        $referencia = min($hoje, $fim);
        $futura = $hoje < $inicio;

        $realizado = $futura ? 0 : self::realizado($tipo, $inicio, $referencia);
        $percentual = $alvo > 0 ? (int) round($realizado / $alvo * 100) : 0;

        // Ritmo linear esperado (só para totais acumulados; o MRR é uma foto e não cresce em linha reta).
        $esperado = null;
        if ($tipo !== 'mrr' && !$futura) {
            $total = self::dias($inicio, $fim) + 1;
            $esperado = (int) round(min($total, self::dias($inicio, $referencia) + 1) / $total * 100);
        }

        $situacao = match (true) {
            $futura => 'futura',
            $realizado >= $alvo => 'atingida',
            $hoje > $fim => 'nao_atingida',
            $esperado === null => 'em_andamento',
            $percentual >= $esperado => 'no_ritmo',
            default => 'abaixo_ritmo',
        };

        return [
            'rotulo' => self::rotulo($meta), 'tipo' => $tipo, 'periodo' => (string) $meta['periodo'], 'inicio' => $inicio, 'fim' => $fim,
            'alvo' => $alvo, 'realizado' => $realizado, 'percentual' => $percentual, 'esperado' => $esperado, 'situacao' => $situacao,
            'dias_restantes' => max(0, self::dias($hoje, $fim)),
            'alvo_txt' => self::formatarValor($tipo, $alvo), 'realizado_txt' => self::formatarValor($tipo, $realizado),
        ];
    }

    private static function realizado(string $tipo, string $inicio, string $referencia): int
    {
        $repo = Repositorios::metas();
        return match ($tipo) {
            'faturamento'        => $repo->faturamento($inicio, $referencia),
            'negocios_ganhos'    => $repo->negociosGanhos($inicio, $referencia),
            'novos_clientes'     => $repo->novosClientes($inicio, $referencia),
            'propostas_enviadas' => $repo->propostasEnviadas($inicio, $referencia),
            'mrr'                => $repo->mrrEm($referencia),
            default              => 0,
        };
    }

    /** Dias entre duas datas ISO (b − a). */
    private static function dias(string $a, string $b): int
    {
        return (int) (new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->format('%r%a');
    }

    /**
     * Metas vigentes hoje com o progresso já calculado (Início e contexto do analista-pipeline).
     * @return list<array>
     */
    public static function vigentes(?string $hoje = null): array
    {
        $hoje ??= hoje();
        return array_map(static fn (array $m): array => self::progresso($m, $hoje) + ['id' => (int) $m['id']], Repositorios::metas()->vigentes($hoje));
    }
}
