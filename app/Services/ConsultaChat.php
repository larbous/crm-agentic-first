<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConsultaRepository;

/**
 * Ação "consultar" do chat: valida filtro/ordem/limite contra a whitelist de campos e operadores
 * (ConsultaRepository) e monta a tabela exibida no chat. Não há SQL aqui.
 */
final class ConsultaChat
{
    private const OPERADORES_POR_TIPO = [
        'texto' => ['=', '!=', 'contem', 'vazio', 'nao_vazio'],
        'enum'  => ['=', '!=', 'vazio', 'nao_vazio'],
        'int'   => ['=', '!=', '>', '>=', '<', '<=', 'entre', 'vazio', 'nao_vazio'],
        'money' => ['=', '!=', '>', '>=', '<', '<=', 'entre', 'vazio', 'nao_vazio'],
        'data'  => ['=', '!=', '>', '>=', '<', '<=', 'entre', 'vazio', 'nao_vazio'],
    ];

    /**
     * @param array $saida objeto do roteador (filtro, ordem, limite)
     * @return array{filtro:list<array>,ordem:?array,limite:int}|string plano normalizado ou mensagem de erro
     */
    public static function validar(string $entidade, array $saida): array|string
    {
        $def = (new ConsultaRepository())->definicao($entidade);
        if ($def === null) {
            return "entidade não consultável: {$entidade}";
        }
        $campos = $def['campos'];

        $filtro = [];
        $bruto = $saida['filtro'] ?? [];
        if (!is_array($bruto) || count($bruto) > 6) {
            return 'filtro inválido';
        }
        foreach ($bruto as $item) {
            if (!is_array($item) || count($item) < 2 || count($item) > 3 || !array_is_list($item)) {
                return 'condição de filtro inválida';
            }
            [$campo, $op] = $item;
            $valor = $item[2] ?? null;
            if (!is_string($campo) || !is_string($op) || !isset($campos[$campo])) {
                return 'campo de filtro fora da whitelist: ' . json_encode($campo);
            }
            $op = strtolower($op);
            $tipo = $campos[$campo]['t'];
            if (!in_array($op, ConsultaRepository::OPERADORES, true) || !in_array($op, self::OPERADORES_POR_TIPO[$tipo], true)) {
                return "operador {$op} não permitido para {$campo}";
            }
            if (in_array($op, ['vazio', 'nao_vazio'], true)) {
                $filtro[] = [$campo, $op, null];
                continue;
            }
            if ($op === 'entre') {
                if (!is_array($valor) || count($valor) !== 2) {
                    return "entre exige dois valores em {$campo}";
                }
                $a = self::valor($campos[$campo], $valor[0]);
                $b = self::valor($campos[$campo], $valor[1]);
                if ($a === null || $b === null) {
                    return "valor inválido em {$campo}";
                }
                $filtro[] = [$campo, $op, [$a, $b]];
                continue;
            }
            $v = self::valor($campos[$campo], $valor);
            if ($v === null) {
                return "valor inválido em {$campo}";
            }
            $filtro[] = [$campo, $op, $v];
        }

        $ordem = null;
        $ordemBruta = $saida['ordem'] ?? null;
        if (is_string($ordemBruta) && trim($ordemBruta) !== '') {
            $partes = preg_split('/\s+/', trim($ordemBruta));
            $campo = $partes[0];
            $dir = strtolower($partes[1] ?? 'asc');
            if (!isset($campos[$campo]) || !in_array($dir, ['asc', 'desc'], true) || count($partes) > 2) {
                return 'ordem fora da whitelist';
            }
            $ordem = [$campo, $dir];
        } elseif ($ordemBruta !== null && $ordemBruta !== '') {
            return 'ordem inválida';
        }

        $limite = $saida['limite'] ?? 20;
        if (!is_int($limite) && !(is_string($limite) && ctype_digit($limite))) {
            return 'limite inválido';
        }

        return ['filtro' => $filtro, 'ordem' => $ordem, 'limite' => max(1, min(50, (int) $limite))];
    }

    /** Converte o valor para a forma usada na consulta (dinheiro em centavos, datas ISO, enums por chave). */
    private static function valor(array $def, mixed $v): int|string|null
    {
        if (!is_scalar($v) || $v === '') {
            return null;
        }
        switch ($def['t']) {
            case 'texto':
                return mb_substr(trim((string) $v), 0, 160);
            case 'int':
                return preg_match('/^-?\d+$/', (string) $v) ? (int) $v : null;
            case 'money':
                return reais_para_centavos(is_bool($v) ? null : $v);
            case 'data':
                $texto = trim((string) $v);
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m)) {
                    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? substr($texto, 0, 10) : null;
                }
                return data_iso($texto);
            case 'enum':
                return Schema::chaveDoEnum($def['op'], (string) $v);
        }
        return null;
    }

    /**
     * Executa a consulta validada e devolve o payload da mensagem (tabela já formatada para exibição).
     * @param array{filtro:list<array>,ordem:?array,limite:int} $plano
     */
    public static function executar(string $entidade, array $plano): array
    {
        $repo = new ConsultaRepository();
        $def = $repo->definicao($entidade);
        $r = $repo->consultar($entidade, $plano['filtro'], $plano['ordem'], $plano['limite']);

        $linhas = [];
        foreach ($r['linhas'] as $linha) {
            $celulas = [];
            foreach ($def['exibir'] as $chave) {
                $celulas[] = self::formatar($def['campos'][$chave], $linha[$chave]);
            }
            $linhas[] = ['id' => (int) $linha['id'], 'celulas' => $celulas];
        }

        return [
            'tipo'    => 'consulta',
            'entidade' => $entidade,
            'rotulo'  => $def['rotulo'],
            'link'    => $def['link'],
            'total'   => $r['total'],
            'colunas' => array_map(static fn (string $c): string => $def['campos'][$c]['r'], $def['exibir']),
            'linhas'  => $linhas,
        ];
    }

    private static function formatar(array $def, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        return match ($def['t']) {
            'money' => moeda((int) $valor),
            'data'  => strlen((string) $valor) > 10 ? datahora_br((string) $valor) : data_br((string) $valor),
            'enum'  => Schema::opcoes($def['op'])[$valor] ?? (string) $valor,
            default => (string) $valor,
        };
    }
}
