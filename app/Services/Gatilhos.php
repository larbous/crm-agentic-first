<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgenteRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\SquadRepository;
use App\Services\AI\AcaoAgente;
use App\Services\AI\SquadRunner;
use DateTimeImmutable;

/**
 * Gatilhos por evento (SPEC §5, §6.1, §7.1). Assina todos os eventos conhecidos e, para cada agente/squad ativo cujo
 * `gatilho` é aquele evento, põe uma execução na fila do worker (nunca roda IA dentro da requisição que gerou o evento).
 *
 * Proteção contra loop: um evento causado por um agente não re-dispara esse mesmo agente (origem `agente:<slug>`), nem
 * um squad cujas etapas incluam esse agente (ou cujo slug seja a origem, como nas ações fixas); não se enfileira o que
 * já está na fila/rodando para o mesmo registro; e cada agente/squad tem um teto de execuções por hora.
 */
final class Gatilhos
{
    /** Teto de execuções disparadas por hora para um mesmo agente ou squad (freio contra cadeias A→B→A). */
    public const LIMITE_POR_HORA = 30;

    /** Assina os eventos. Chamado na inicialização (bootstrap); repetir a chamada duplica os ouvintes. */
    public static function registrar(): void
    {
        foreach (Events::CONHECIDOS as $evento) {
            Events::ouvir($evento, static fn (array $payload) => self::tratar($payload));
        }
    }

    /** Trata um evento: enfileira agentes e squads assinantes. */
    public static function tratar(array $payload): void
    {
        $evento = (string) ($payload['evento'] ?? '');
        $origem = (string) ($payload['origem'] ?? 'sistema');
        if ($evento === '') {
            return;
        }
        if ($evento === 'formulario.submetido') {
            self::squadDoFormulario($payload);
        }

        foreach ((new AgenteRepository())->todos(true) as $agente) {
            $g = (array) ($agente['def']['gatilho'] ?? []);
            if (($g['tipo'] ?? '') !== 'evento' || ($g['evento'] ?? '') !== $evento || $origem === 'agente:' . $agente['slug']) {
                continue;
            }
            $alvo = self::alvo($payload, (string) $agente['def']['entrada']);
            if ($alvo !== false) {
                self::enfileirar('agente', $agente, $alvo, null, $origem, 'evento:' . $evento);
            }
        }

        foreach ((new SquadRepository())->todos(true) as $squad) {
            $g = (array) ($squad['def']['gatilho'] ?? []);
            if (($g['tipo'] ?? '') !== 'evento' || ($g['evento'] ?? '') !== $evento || self::origemDoSquad($origem, $squad)) {
                continue;
            }
            $alvo = self::alvo($payload, (string) $squad['def']['entrada']);
            if ($alvo !== false) {
                self::enfileirar('squad', $squad, $alvo, null, $origem, 'evento:' . $evento);
            }
        }
    }

    /** Squad escolhido no próprio formulário (`squad_disparado`): entra na fila a cada envio, além dos gatilhos por evento. */
    private static function squadDoFormulario(array $payload): void
    {
        $slug = (string) ($payload['squad'] ?? '');
        $squad = $slug !== '' ? (new SquadRepository())->porSlug($slug) : null;
        if ($squad === null || (int) $squad['ativo'] !== 1) {
            return;
        }
        $alvo = self::alvo($payload, (string) $squad['def']['entrada']);
        if ($alvo !== false) {
            self::enfileirar('squad', $squad, $alvo, null, (string) ($payload['origem'] ?? 'sistema'), 'formulario:' . ($payload['formulario_id'] ?? ''));
        }
    }

    /**
     * Põe um agente ou squad na fila (com deduplicação e teto por hora). Devolve o id da execução ou null se foi barrado.
     * @param array $dono linha de AgenteRepository ou SquadRepository
     */
    public static function enfileirar(string $tipo, array $dono, ?int $registroId, ?string $texto, string $origem, string $gatilho): ?int
    {
        $execucoes = new ExecucaoRepository();
        $entidade = $dono['def']['entrada'] === 'nenhuma' ? null : (string) $dono['def']['entrada'];
        $donoId = (int) $dono['id'];

        if ($execucoes->emAndamento($tipo, $donoId, $entidade, $registroId)) {
            return null;
        }
        $desde = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        if ($execucoes->iniciadasDesde($tipo, $donoId, $desde) >= self::LIMITE_POR_HORA) {
            error_log("Gatilho barrado: {$tipo} \"{$dono['slug']}\" chegou ao teto de " . self::LIMITE_POR_HORA . ' execuções por hora.');
            return null;
        }

        if ($tipo === 'squad') {
            return (new SquadRunner())->enfileirar($dono, $registroId, $texto, $origem);
        }
        return $execucoes->enfileirar([
            'agente_id' => $donoId, 'entidade' => $entidade, 'registro_id' => $registroId, 'entrada' => $texto,
        ]);
    }

    /**
     * Registro-alvo de um agente/squad a partir do payload do evento.
     * @return int|null|false id do registro; null se a entrada é "nenhuma"; false se o evento não tem esse registro (não dispara)
     */
    public static function alvo(array $payload, string $entrada): int|null|false
    {
        if ($entrada === 'nenhuma') {
            return null;
        }
        $entidade = (string) ($payload['entidade'] ?? '');
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        if ($id <= 0) {
            return false;
        }
        if ($entidade === $entrada) {
            return $id;
        }
        // Registros que apontam para o alvo (tarefa, atividade, contato, negócio, proposta, contrato…).
        $campo = ['empresas' => 'empresa_id', 'contatos' => 'contato_id', 'negocios' => 'negocio_id', 'propostas' => 'proposta_id', 'contratos' => 'contrato_id'][$entrada] ?? null;
        $registro = (array) ($payload['registro'] ?? []);
        if ($campo !== null && !empty($registro[$campo])) {
            return (int) $registro[$campo];
        }
        $cadeia = AcaoAgente::cadeia($entidade !== '' ? $entidade : null, $id);
        return $cadeia[$entrada] ?? false;
    }

    /** O evento foi causado pelo próprio squad (etapa de ação fixa) ou por um agente dele? */
    private static function origemDoSquad(string $origem, array $squad): bool
    {
        if (!str_starts_with($origem, 'agente:')) {
            return false;
        }
        $slug = substr($origem, 7);
        if ($slug === $squad['slug']) {
            return true;
        }
        foreach ((array) $squad['def']['etapas'] as $etapa) {
            if (($etapa['agente'] ?? null) === $slug) {
                return true;
            }
        }
        return false;
    }
}
