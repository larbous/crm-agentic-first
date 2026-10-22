<?php

declare(strict_types=1);

namespace App\Services;

/** Utilidades da assinatura eletrônica de contratos: validação do desenho (PNG) e hash do texto assinado. */
final class Assinatura
{
    /** Tamanho máximo do PNG desenhado (bytes, já decodificado). */
    private const MAX_BYTES = 150000;
    /** Abaixo disso o PNG é praticamente uma tela em branco. */
    private const MIN_BYTES = 300;

    /**
     * Aceita só "data:image/png;base64,…" com um PNG de verdade e tamanho razoável; devolve o mesmo texto ou null.
     * O valor volta a ser exibido em <img src>, então nada além disso passa.
     */
    public static function imagemValida(mixed $valor): ?string
    {
        if (!is_string($valor) || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$#', $valor, $m)) {
            return null;
        }
        $bin = base64_decode($m[1], true);
        if ($bin === false || strlen($bin) < self::MIN_BYTES || strlen($bin) > self::MAX_BYTES || !str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        return $valor;
    }

    /** Código de verificação: SHA-256 do texto (já com as variáveis preenchidas) e do número do contrato. */
    public static function hash(string $numero, string $textoAssinado): string
    {
        return hash('sha256', $numero . "\n" . $textoAssinado);
    }

    /** O texto gravado ainda é o que foi assinado? */
    public static function integro(array $contrato): bool
    {
        return !empty($contrato['assinatura_hash'])
            && hash_equals((string) $contrato['assinatura_hash'], self::hash((string) $contrato['numero'], (string) $contrato['conteudo']));
    }

    /** Hash em blocos de 8 caracteres, para ler e conferir. */
    public static function hashLegivel(?string $hash): string
    {
        return $hash === null ? '' : trim(chunk_split(strtoupper($hash), 8, ' '));
    }
}
