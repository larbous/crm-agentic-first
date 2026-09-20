<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\WorkerRepository;
use DateTimeImmutable;

/**
 * Rotinas de manutenção do worker (SPEC §11, itens 3 e 4): próximas ocorrências de tarefas recorrentes, tarefas vencidas,
 * propostas expiradas e contratos vencendo/vencidos. Cada aviso é emitido uma única vez (`worker_marcas`); mudanças de
 * estado passam pelo ActionExecutor com origem "sistema"; eventos disparam ao final de cada mudança/aviso.
 */
final class Rotinas
{
    private const LIMITE = 100;

    public function __construct(
        private readonly WorkerRepository $repo = new WorkerRepository(),
        private readonly ActionExecutor $executor = new ActionExecutor(),
    ) {
    }

    /** @return array<string,int> quantas ocorrências de cada tipo foram tratadas nesta rodada */
    public function executar(): array
    {
        return [
            'tarefas_recorrentes' => $this->tarefasRecorrentes(),
            'tarefas_vencidas'    => $this->tarefasVencidas(),
            'propostas_expiradas' => $this->propostasExpiradas(),
            'contratos_vencendo'  => $this->contratosVencendo(),
            'contratos_vencidos'  => $this->contratosVencidos(),
        ];
    }

    /**
     * Tarefa recorrente concluída → cria a próxima (mesmos dados, novo vencimento). Se a tarefa foi concluída muito
     * depois do vencimento, a próxima é a primeira data futura da série (não acumula um atraso de várias ocorrências).
     */
    private function tarefasRecorrentes(): int
    {
        $n = 0;
        foreach ($this->repo->tarefasRecorrentesConcluidas(self::LIMITE) as $t) {
            if (!$this->repo->marcar('tarefa.proxima:' . $t['id'])) {
                continue;
            }
            $base = substr((string) ($t['vencimento'] ?? $t['concluida_em'] ?? agora()), 0, 10);
            $proxima = self::proximaOcorrencia($base, (string) $t['recorrencia'], hoje());
            $hora = strlen((string) $t['vencimento']) > 10 ? substr((string) $t['vencimento'], 10) : '';

            $dados = ['vencimento' => $proxima . $hora, 'status' => 'pendente'];
            foreach (['titulo', 'descricao', 'tipo', 'prioridade', 'recorrencia', 'empresa_id', 'contato_id', 'negocio_id', 'contrato_id'] as $campo) {
                if ($t[$campo] !== null) {
                    $dados[$campo] = $t[$campo];
                }
            }
            $r = $this->executor->criar('tarefas', $dados, 'sistema');
            if ($r->ok) {
                $n++;
            } else {
                error_log("Worker: tarefa recorrente #{$t['id']} não gerou a próxima: {$r->mensagem}");
            }
        }
        return $n;
    }

    private function tarefasVencidas(): int
    {
        $n = 0;
        foreach ($this->repo->tarefasVencidas(hoje(), agora(), self::LIMITE) as $t) {
            if ($this->repo->marcar('tarefa.vencida:' . $t['id'] . ':' . $t['vencimento'])) {
                Events::disparar('tarefa.vencida', ['entidade' => 'tarefas', 'id' => (int) $t['id'], 'origem' => 'sistema', 'registro' => $t]);
                $n++;
            }
        }
        return $n;
    }

    private function propostasExpiradas(): int
    {
        $n = 0;
        foreach ($this->repo->propostasExpiradas(hoje(), self::LIMITE) as $p) {
            if ($this->executor->expirarProposta((int) $p['id'])->ok) {
                $n++;
            }
        }
        return $n;
    }

    private function contratosVencendo(): int
    {
        $n = 0;
        foreach ($this->repo->contratosVencendo(hoje(), self::LIMITE) as $c) {
            if ($this->repo->marcar('contrato.vencendo:' . $c['id'] . ':' . $c['data_fim'])) {
                Events::disparar('contrato.vencendo', [
                    'entidade' => 'contratos', 'id' => (int) $c['id'], 'origem' => 'sistema', 'registro' => $c, 'dias' => dias_entre(hoje(), $c['data_fim']),
                ]);
                $n++;
            }
        }
        return $n;
    }

    private function contratosVencidos(): int
    {
        $n = 0;
        foreach ($this->repo->contratosVencidos(hoje(), self::LIMITE) as $c) {
            if ($this->executor->vencerContrato((int) $c['id'])->ok) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Primeira data da série de recorrência estritamente depois de $base e não anterior a $minimo (AAAA-MM-DD).
     * Mensal e anual preservam o dia quando possível e usam o último dia do mês quando ele não existe (31/01 → 28/02).
     */
    public static function proximaOcorrencia(string $base, string $recorrencia, string $minimo): string
    {
        $data = new DateTimeImmutable($base);
        $passo = static function (DateTimeImmutable $d, int $i) use ($base, $recorrencia): DateTimeImmutable {
            $origem = new DateTimeImmutable($base);
            return match ($recorrencia) {
                'diaria' => $origem->modify("+{$i} days"),
                'semanal' => $origem->modify('+' . (7 * $i) . ' days'),
                'mensal', 'anual' => self::somarMeses($origem, $recorrencia === 'mensal' ? $i : 12 * $i),
                default => $d,
            };
        };
        for ($i = 1; $i <= 2000; $i++) {
            $candidata = $passo($data, $i);
            if ($candidata->format('Y-m-d') >= $minimo) {
                return $candidata->format('Y-m-d');
            }
        }
        return $minimo;
    }

    private static function somarMeses(DateTimeImmutable $d, int $meses): DateTimeImmutable
    {
        $dia = (int) $d->format('j');
        $primeiro = $d->modify('first day of this month')->modify("+{$meses} months");
        return $primeiro->setDate((int) $primeiro->format('Y'), (int) $primeiro->format('n'), min($dia, (int) $primeiro->format('t')));
    }
}
