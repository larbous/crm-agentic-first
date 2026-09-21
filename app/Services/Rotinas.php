<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FormularioRepository;
use App\Repositories\PesquisaRepository;
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
            'pesquisas_criadas'   => $this->pesquisasAutomaticas(),
            'pesquisas_expiradas' => $this->pesquisasExpiradas(),
        ];
    }

    /** Contratos assinados há pelo menos N dias entram na pesquisa; só os dos últimos N + 30 dias (ativar o gatilho não dispara para contratos antigos). */
    private const JANELA_CONTRATOS_DIAS = 30;
    private const LIMITE_PESQUISAS = 50;

    /**
     * Cria as pesquisas dos formulários com gatilho: `contrato_assinado` (N dias depois da assinatura, uma por contrato) e
     * `periodica` (clientes ativos há pelo menos N dias e sem pesquisa nos últimos N). Cada uma abre uma tarefa para o operador
     * entregar o link, até 50 por rodada.
     */
    private function pesquisasAutomaticas(): int
    {
        $n = 0;
        $pesquisas = new PesquisaRepository();
        foreach ((new FormularioRepository())->pesquisasAutomaticas() as $f) {
            $dias = (int) $f['gatilho_dias'];
            if ($f['gatilho_tipo'] === 'contrato_assinado') {
                $ate = date('Y-m-d', strtotime("-{$dias} days"));
                $de = date('Y-m-d', strtotime('-' . ($dias + self::JANELA_CONTRATOS_DIAS) . ' days'));
                foreach ($pesquisas->contratosParaPesquisar((int) $f['id'], $de, $ate, self::LIMITE_PESQUISAS - $n) as $c) {
                    $n += $this->executor->criarPesquisa($f, (int) $c['empresa_id'], $c['contato_id'] !== null ? (int) $c['contato_id'] : null, (int) $c['id'], 'contrato_assinado', 'sistema')->ok ? 1 : 0;
                }
            } else {
                $desde = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
                $clienteAte = date('Y-m-d', strtotime("-{$dias} days"));
                foreach ($pesquisas->clientesParaPesquisar((int) $f['id'], $desde, $clienteAte, self::LIMITE_PESQUISAS - $n) as $e) {
                    $n += $this->executor->criarPesquisa($f, (int) $e['id'], null, null, 'periodica', 'sistema')->ok ? 1 : 0;
                }
            }
            if ($n >= self::LIMITE_PESQUISAS) {
                break;
            }
        }
        return $n;
    }

    /** Link não respondido dentro do prazo → expirada. */
    private function pesquisasExpiradas(): int
    {
        $n = 0;
        foreach ((new PesquisaRepository())->vencidas(agora(), self::LIMITE) as $id) {
            $n += $this->executor->expirarPesquisa($id)->ok ? 1 : 0;
        }
        return $n;
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
