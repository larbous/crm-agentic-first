<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Checklist de execução de um chamado (Fase 13): lista de itens {texto, feito} guardada como JSON na coluna `checklist`.
 * Na tela de edição o checklist vira texto (uma linha por item; "[x] " marca item feito) e volta pelo mesmo caminho.
 */
final class Checklist
{
    public const MAX_ITENS = 50;
    public const MAX_TEXTO = 200;

    /**
     * Valida e devolve o JSON canônico (null = checklist vazio). Aceita lista de itens (array com `texto`/`feito`) ou de textos.
     * @param string|list<mixed>|null $valor lista, JSON ou null
     * @return string|false|null JSON, null se vazio; false se inválido (mensagem em $erro)
     */
    public static function normalizar(mixed $valor, ?string &$erro): string|false|null
    {
        if ($valor === null || $valor === '' || $valor === []) {
            return null;
        }
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }
        if (!is_array($valor) || !array_is_list($valor)) {
            $erro = 'O checklist deve ser uma lista de itens.';
            return false;
        }
        if (count($valor) > self::MAX_ITENS) {
            $erro = 'O checklist aceita no máximo ' . self::MAX_ITENS . ' itens.';
            return false;
        }
        $itens = [];
        foreach ($valor as $item) {
            $texto = is_array($item) ? ($item['texto'] ?? '') : $item;
            $feito = is_array($item) && in_array($item['feito'] ?? 0, [1, '1', true, 'true', 'on'], true) ? 1 : 0;
            if (!is_string($texto)) {
                $erro = 'Cada item do checklist deve ser um texto.';
                return false;
            }
            $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
            if ($texto === '') {
                continue; // linha em branco: ignora
            }
            if (!mb_check_encoding($texto, 'UTF-8') || mb_strlen($texto) > self::MAX_TEXTO) {
                $erro = 'Cada item do checklist deve ter até ' . self::MAX_TEXTO . ' caracteres.';
                return false;
            }
            $itens[] = ['texto' => $texto, 'feito' => $feito];
        }
        return $itens === [] ? null : json_encode($itens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return list<array{texto:string,feito:int}> */
    public static function itens(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $lista = json_decode($json, true);
        if (!is_array($lista)) {
            return [];
        }
        return array_values(array_map(
            static fn (array $i): array => ['texto' => (string) ($i['texto'] ?? ''), 'feito' => (int) ($i['feito'] ?? 0) === 1 ? 1 : 0],
            array_filter($lista, 'is_array'),
        ));
    }

    /** Texto do formulário → itens. Cada linha é um item; "[x] " (ou "[X] ") no início marca como feito, "[ ] " é opcional. */
    public static function deTexto(string $texto): array
    {
        $itens = [];
        foreach (preg_split('/\R/u', $texto) ?: [] as $linha) {
            $linha = trim($linha);
            $feito = 0;
            if (preg_match('/^\[([xX ])\]\s*(.*)$/u', $linha, $m) === 1) {
                $feito = strtolower($m[1]) === 'x' ? 1 : 0;
                $linha = $m[2];
            }
            if (trim($linha) !== '') {
                $itens[] = ['texto' => $linha, 'feito' => $feito];
            }
        }
        return $itens;
    }

    /** Itens → texto do formulário (inverso de deTexto). */
    public static function paraTexto(?string $json): string
    {
        return implode("\n", array_map(static fn (array $i): string => ($i['feito'] === 1 ? '[x] ' : '[ ] ') . $i['texto'], self::itens($json)));
    }

    /** @return array{feitos:int,total:int} */
    public static function progresso(?string $json): array
    {
        $itens = self::itens($json);
        return ['feitos' => count(array_filter($itens, static fn (array $i): bool => $i['feito'] === 1)), 'total' => count($itens)];
    }
}
