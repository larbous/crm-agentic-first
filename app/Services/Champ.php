<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Qualificação CHAMP de um negócio (Fase 11): Desafios, Autoridade, Dinheiro e Prioridade.
 * Cada dimensão vale 'confirmado' (2 pontos), 'parcial' (1) ou 'nao_identificado' (0); dimensão ainda não avaliada
 * (NULL) também soma 0. Pontuação de 0 a 8 → temperatura, calculada pelo servidor (a IA só informa as dimensões):
 *
 *   7–8 fervendo · 5–6 quente · 3–4 morno · 0–2 frio
 *
 * Trava: sem desafio confirmado ou parcial não há dor a resolver, então a temperatura nunca passa de "morno".
 */
final class Champ
{
    public const DIMENSOES = ['champ_desafios', 'champ_autoridade', 'champ_dinheiro', 'champ_prioridade'];
    public const PONTOS = ['confirmado' => 2, 'parcial' => 1, 'nao_identificado' => 0];
    public const MAXIMO = 8;

    /** Pontuação (0–8), ou null se nenhuma dimensão foi avaliada. */
    public static function pontuar(array $registro): ?int
    {
        $soma = 0;
        $avaliou = false;
        foreach (self::DIMENSOES as $d) {
            $valor = $registro[$d] ?? null;
            if ($valor !== null && $valor !== '') {
                $avaliou = true;
                $soma += self::PONTOS[$valor] ?? 0;
            }
        }
        return $avaliou ? $soma : null;
    }

    /** Temperatura correspondente ao registro, ou null se ainda não há avaliação. */
    public static function temperatura(array $registro): ?string
    {
        $pontos = self::pontuar($registro);
        if ($pontos === null) {
            return null;
        }
        $temperatura = match (true) {
            $pontos >= 7 => 'fervendo',
            $pontos >= 5 => 'quente',
            $pontos >= 3 => 'morno',
            default => 'frio',
        };
        $semDesafio = !in_array($registro['champ_desafios'] ?? null, ['confirmado', 'parcial'], true);
        if ($semDesafio && in_array($temperatura, ['fervendo', 'quente'], true)) {
            return 'morno';
        }
        return $temperatura;
    }

    /**
     * Campos derivados a gravar quando uma dimensão do CHAMP muda: pontuação, data da avaliação e, se a mesma gravação
     * não trouxe uma temperatura explícita, a temperatura. Sem mudança em nenhuma dimensão devolve [].
     * @param array $atual registro atual (vazio na criação)
     * @param array $novos campos que estão sendo gravados
     */
    public static function derivar(array $atual, array $novos): array
    {
        $mudou = false;
        foreach (self::DIMENSOES as $d) {
            if (array_key_exists($d, $novos) && ($novos[$d] ?? null) !== ($atual[$d] ?? null)) {
                $mudou = true;
            }
        }
        if (!$mudou) {
            return [];
        }
        $final = $novos + $atual;
        $derivados = ['champ_pontos' => self::pontuar($final), 'champ_avaliado_em' => agora()];
        $temperatura = self::temperatura($final);
        if ($temperatura !== null && !array_key_exists('temperatura', $novos)) {
            $derivados['temperatura'] = $temperatura;
        }
        return $derivados;
    }
}
