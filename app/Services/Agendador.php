<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\CronExpressao;
use App\Repositories\AgendamentoRepository;
use App\Repositories\AgenteRepository;
use App\Repositories\SquadRepository;
use DateTimeImmutable;

/**
 * Gatilhos "agendado" (SPEC §6.1, §7.1, §11): mantém `agendamentos_execucao` em sincronia com as definições ativas
 * e, a cada rodada do worker, põe na fila o que venceu. Uma agenda perdida (worker parado) roda uma vez só quando
 * o worker volta, e o próximo horário é sempre calculado a partir de agora.
 */
final class Agendador
{
    /** Cria, atualiza e remove agendamentos conforme os agentes e squads ativos com gatilho agendado. */
    public static function sincronizar(?DateTimeImmutable $agora = null): void
    {
        $agora ??= new DateTimeImmutable();
        $repo = new AgendamentoRepository();

        $desejados = [];
        foreach ([['agente', (new AgenteRepository())->todos(true)], ['squad', (new SquadRepository())->todos(true)]] as [$tipo, $donos]) {
            foreach ($donos as $dono) {
                $g = (array) ($dono['def']['gatilho'] ?? []);
                if (($g['tipo'] ?? '') === 'agendado' && is_string($g['cron'] ?? null)) {
                    $desejados[$tipo . ':' . (int) $dono['id']] = $g['cron'];
                }
            }
        }

        foreach ($repo->todos() as $existente) {
            $chave = $existente['tipo'] . ':' . $existente['dono_id'];
            if (!isset($desejados[$chave])) {
                $repo->remover((int) $existente['id']);
            } elseif ($desejados[$chave] === $existente['cron']) {
                unset($desejados[$chave]); // já em dia
            }
        }
        foreach ($desejados as $chave => $cron) {
            [$tipo, $donoId] = explode(':', $chave);
            $proximo = CronExpressao::analisar($cron)?->proximo($agora);
            if ($proximo !== null) {
                $repo->salvar($tipo, (int) $donoId, $cron, $proximo->format('Y-m-d H:i:s'));
            }
        }
    }

    /**
     * Enfileira os agendamentos vencidos e calcula o próximo horário de cada um.
     * @return int quantas execuções foram para a fila
     */
    public static function disparar(?DateTimeImmutable $agora = null): int
    {
        $agora ??= new DateTimeImmutable();
        $repo = new AgendamentoRepository();
        $enfileiradas = 0;

        foreach ($repo->vencidos($agora->format('Y-m-d H:i:s')) as $a) {
            $tipo = $a['agente_id'] !== null ? 'agente' : 'squad';
            $dono = $tipo === 'agente' ? (new AgenteRepository())->encontrar((int) $a['agente_id']) : (new SquadRepository())->encontrar((int) $a['squad_id']);
            if ($dono !== null && (int) $dono['ativo'] === 1 && Gatilhos::enfileirar($tipo, $dono, null, null, 'sistema', 'agenda') !== null) {
                $enfileiradas++;
            }
            $proximo = CronExpressao::analisar((string) $a['cron'])?->proximo($agora);
            if ($proximo === null) {
                $repo->remover((int) $a['id']);
            } else {
                $repo->registrarDisparo((int) $a['id'], $agora->format('Y-m-d H:i:s'), $proximo->format('Y-m-d H:i:s'));
            }
        }
        return $enfileiradas;
    }
}
