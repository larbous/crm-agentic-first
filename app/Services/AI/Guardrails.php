<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ExecucaoRepository;
use InvalidArgumentException;

/**
 * Freios de segurança da IA, valendo para qualquer origem (evento, agenda, botão, chat, worker):
 * - teto de execuções por hora de cada agente e de cada squad (`ia.limite_hora`, padrão 30);
 * - limiar de confiança: ação de agente com confiança abaixo do corte vai para a fila de aprovação, mesmo que o agente
 *   esteja configurado para rodar sem aprovação (`ia.confianca_minima`, padrão 0,7; o agente pode ter o seu em `confianca_minima`).
 */
final class Guardrails
{
    public const LIMITE_POR_HORA_PADRAO = 30;
    public const CONFIANCA_MINIMA_PADRAO = 0.7;

    public static function limitePorHora(): int
    {
        $v = (int) ((new ConfiguracaoRepository())->obter('ia.limite_hora') ?: self::LIMITE_POR_HORA_PADRAO);
        return max(1, $v);
    }

    /** Corte de confiança do agente (0–1): o do JSON do agente, senão o global, senão o padrão. */
    public static function confiancaMinima(array $def): float
    {
        if (isset($def['confianca_minima']) && is_numeric($def['confianca_minima'])) {
            return max(0.0, min(1.0, (float) $def['confianca_minima']));
        }
        $global = (new ConfiguracaoRepository())->obter('ia.confianca_minima');
        return $global !== null && is_numeric($global) ? max(0.0, min(1.0, (float) $global)) : self::CONFIANCA_MINIMA_PADRAO;
    }

    /** Confiança declarada pela IA (0–1). Ausente ou inválida = 0 (tratada como baixa). */
    public static function lerConfianca(mixed $valor): float
    {
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }
        return is_numeric($valor) ? max(0.0, min(1.0, (float) $valor)) : 0.0;
    }

    /**
     * Barra a execução se o agente/squad já começou o teto de execuções na última hora.
     * @param array $dono linha de AgenteRepository ou SquadRepository
     * @param int|null $exceto execução que está sendo processada agora (já está contada; não deve contar contra si mesma)
     * @throws InvalidArgumentException
     */
    public static function verificarLimite(string $tipo, array $dono, ?int $exceto = null): void
    {
        $limite = self::limitePorHora();
        $desde = date('Y-m-d H:i:s', strtotime('-1 hour'));
        if ((new ExecucaoRepository())->iniciadasDesde($tipo, (int) $dono['id'], $desde, $exceto) >= $limite) {
            throw new InvalidArgumentException(
                'O ' . ($tipo === 'agente' ? 'agente' : 'squad') . ' "' . $dono['nome'] . '" chegou ao limite de ' . $limite . ' execuções por hora. Tente novamente mais tarde.'
            );
        }
    }
}
