<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Expressões simples de `condicao` e `parar_se` dos squads (SPEC §7.1), avaliadas pelo servidor, nunca pela IA.
 * Uma comparação por expressão: `<variável> <operador> <valor>`, com operadores `==`, `!=`, `in`, `>`, `<` (e `>=`, `<=`).
 * Valor: palavra, número ou texto entre aspas; com `in`, uma lista `[A, B, "C D"]`.
 * Texto compara sem diferenciar maiúsculas; `>`/`<` comparam números (ou datas ISO) e são falsos para o resto.
 * Variável ausente vale vazio: `==` e `in` dão falso, `!=` dá verdadeiro.
 */
final class Condicao
{
    private const PADRAO = '/^\s*([a-z0-9_.-]+)\s+(==|!=|>=|<=|>|<|in)\s+(.+?)\s*$/iu';

    /**
     * @return array{variavel:string,operador:string,valor:string|list<string>}|string a expressão analisada ou a mensagem de erro
     */
    public static function analisar(string $expressao): array|string
    {
        if (preg_match(self::PADRAO, $expressao, $m) !== 1) {
            return 'use "variável operador valor", por exemplo: empresa.classificacao in [A,B]';
        }
        $operador = strtolower($m[2]);
        $direita = $m[3];
        if ($operador === 'in') {
            if (preg_match('/^\[(.*)\]$/su', $direita, $l) !== 1) {
                return 'o operador "in" exige uma lista entre colchetes, por exemplo: [A,B]';
            }
            $itens = array_map(static fn (string $i): string => self::literal($i), array_filter(array_map('trim', explode(',', $l[1])), static fn (string $i): bool => $i !== ''));
            if ($itens === []) {
                return 'a lista do operador "in" está vazia';
            }
            return ['variavel' => strtolower($m[1]), 'operador' => 'in', 'valor' => array_values($itens)];
        }
        if (str_starts_with($direita, '[')) {
            return 'listas só podem ser usadas com o operador "in"';
        }
        return ['variavel' => strtolower($m[1]), 'operador' => $operador, 'valor' => self::literal($direita)];
    }

    /**
     * @param array{variavel:string,operador:string,valor:string|list<string>} $analisada
     * @param callable(string):(string|int|float|null) $resolver devolve o valor da variável (null se não existir)
     */
    public static function avaliar(array $analisada, callable $resolver): bool
    {
        $atual = $resolver($analisada['variavel']);
        $atual = $atual === null ? '' : trim((string) $atual);
        $valor = $analisada['valor'];

        switch ($analisada['operador']) {
            case 'in':
                foreach ((array) $valor as $item) {
                    if (self::igual($atual, (string) $item)) {
                        return true;
                    }
                }
                return false;
            case '==':
                return self::igual($atual, (string) $valor);
            case '!=':
                return !self::igual($atual, (string) $valor);
        }
        $ordem = self::comparar($atual, (string) $valor);
        return $ordem !== null && match ($analisada['operador']) {
            '>' => $ordem > 0, '<' => $ordem < 0, '>=' => $ordem >= 0, '<=' => $ordem <= 0,
        };
    }

    /** Analisa e avalia de uma vez. Expressão inválida = falso. */
    public static function verdadeira(string $expressao, callable $resolver): bool
    {
        $a = self::analisar($expressao);
        return is_array($a) && self::avaliar($a, $resolver);
    }

    /** Remove aspas simples/duplas ao redor do valor. */
    private static function literal(string $texto): string
    {
        $texto = trim($texto);
        if (preg_match('/^(["\'])(.*)\1$/su', $texto, $m) === 1) {
            return $m[2];
        }
        return $texto;
    }

    private static function igual(string $a, string $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return mb_strtolower($a) === mb_strtolower($b);
    }

    /** Ordem entre dois valores numéricos ou duas datas ISO; null se não forem comparáveis. */
    private static function comparar(string $a, string $b): ?int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }
        $data = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';
        if (preg_match($data, $a) === 1 && preg_match($data, $b) === 1) {
            return strcmp($a, $b) <=> 0;
        }
        return null;
    }
}
