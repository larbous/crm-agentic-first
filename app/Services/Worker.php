<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgenteRepository;
use App\Repositories\ExecucaoRepository;
use App\Services\AI\AgentRunner;
use App\Services\AI\IaErro;
use App\Services\AI\SquadRunner;
use InvalidArgumentException;
use Throwable;

/**
 * Uma rodada do worker (SPEC §11), chamada por `cron/worker.php` a cada minuto.
 *
 * Ordem: recupera execuções interrompidas; rotinas de manutenção (recorrentes, vencidas, expiradas); agendamentos
 * vencidos; e por fim a fila (agentes e squads, no máximo `$limiteFila` por rodada e sem começar nada novo depois de
 * `$orcamentoSegundos`). As rotinas e agendamentos vêm antes da fila para que o que eles enfileiram já rode na mesma rodada.
 */
final class Worker
{
    public const LIMITE_FILA = 5;
    public const ORCAMENTO_SEGUNDOS = 240;

    /** Execução "rodando" há mais que isso, sem cabeçalho de squad, é considerada morta. */
    private const TEMPO_MORTA = '-15 minutes';

    public function __construct(
        private readonly ExecucaoRepository $execucoes = new ExecucaoRepository(),
        private readonly AgenteRepository $agentes = new AgenteRepository(),
    ) {
    }

    /**
     * @return array{interrompidas:int,rotinas:array<string,int>,agendadas:int,processadas:int,erros:int,restantes:int}
     */
    public function rodada(int $limiteFila = self::LIMITE_FILA, int $orcamentoSegundos = self::ORCAMENTO_SEGUNDOS): array
    {
        $inicio = time();
        $resumo = ['interrompidas' => 0, 'rotinas' => [], 'agendadas' => 0, 'processadas' => 0, 'erros' => 0, 'restantes' => 0];

        $resumo['interrompidas'] = $this->execucoes->encerrarInterrompidas(date('Y-m-d H:i:s', strtotime(self::TEMPO_MORTA)));
        $resumo['rotinas'] = $this->tentar(static fn (): array => (new Rotinas())->executar(), []);
        Agendador::sincronizar();
        $resumo['agendadas'] = $this->tentar(static fn (): int => Agendador::disparar(), 0);

        foreach ($this->execucoes->daFila($limiteFila) as $linha) {
            if (time() - $inicio >= $orcamentoSegundos) {
                break;
            }
            if (!$this->execucoes->assumir((int) $linha['id'])) {
                continue; // outro processo assumiu ou foi cancelada
            }
            $resumo['processadas']++;
            if (!$this->processar($linha)) {
                $resumo['erros']++;
            }
        }
        $resumo['restantes'] = count($this->execucoes->daFila(1000));
        return $resumo;
    }

    /** Executa uma linha já assumida (status `rodando`). Devolve falso se terminou em erro. */
    private function processar(array $linha): bool
    {
        $id = (int) $linha['id'];
        if ($linha['agente_id'] === null) {
            (new SquadRunner())->executar($linha);
            return ($this->execucoes->encontrar($id)['status'] ?? 'erro') !== 'erro';
        }

        try {
            $agente = $this->agentes->encontrar((int) $linha['agente_id']);
            if ($agente === null || (int) $agente['ativo'] !== 1) {
                throw new InvalidArgumentException('O agente está desativado ou foi removido.');
            }
            $r = (new AgentRunner())->executar(
                $agente,
                $linha['registro_id'] !== null ? (int) $linha['registro_id'] : null,
                $linha['entrada'] !== null && $linha['entrada'] !== '' ? (string) $linha['entrada'] : null,
                false,
                ['execucao_id' => $id],
            );
            return $r['ok'];
        } catch (InvalidArgumentException | IaErro $e) {
            $this->execucoes->atualizar($id, ['status' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 500), 'concluido_em' => agora()]);
        } catch (Throwable $e) {
            error_log('Worker, execução #' . $id . ': ' . $e);
            $this->execucoes->atualizar($id, ['status' => 'erro', 'erro' => mb_substr('Erro inesperado: ' . $e->getMessage(), 0, 500), 'concluido_em' => agora()]);
        }
        return false;
    }

    /** Uma rotina que falha não pode impedir o resto da rodada. */
    private function tentar(callable $fn, mixed $padrao): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            error_log('Worker: ' . $e);
            return $padrao;
        }
    }
}
