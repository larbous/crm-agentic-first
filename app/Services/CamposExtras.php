<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Repositorios;

/**
 * Campos extras (SPEC §4.12): campos definidos pelo operador em Configurações, guardados como JSON na coluna
 * `campos_extras` de empresas, contatos e negócios. Aqui ficam a validação, a exibição e os filtros; a gravação
 * passa pelo ActionExecutor como qualquer outro campo (chave `campos_extras`).
 */
final class CamposExtras
{
    public const ENTIDADES = ['empresas', 'contatos', 'negocios'];

    /** Prefixo dos filtros de lista (x_<chave>). */
    public const PREFIXO_FILTRO = 'x_';

    public const PADRAO_CHAVE = '/^[a-z][a-z0-9_]{0,39}$/';

    public static function suporta(string $entidade): bool
    {
        return in_array($entidade, self::ENTIDADES, true);
    }

    /** Definições ativas da entidade, na ordem de exibição. */
    public static function definicoes(string $entidade): array
    {
        return self::suporta($entidade) ? Repositorios::camposExtras()->daEntidade($entidade) : [];
    }

    /** @return array<string,array> definições ativas indexadas por chave */
    public static function porChave(string $entidade): array
    {
        return array_column(self::definicoes($entidade), null, 'chave');
    }

    /** Valores gravados (JSON ou array) como array chave => valor. */
    public static function valores(mixed $bruto): array
    {
        if (is_array($bruto)) {
            return $bruto;
        }
        if (!is_string($bruto) || $bruto === '') {
            return [];
        }
        $d = json_decode($bruto, true);
        return is_array($d) ? $d : [];
    }

    // =====================================================================================
    // Validação (usada pelo ActionExecutor)
    // =====================================================================================

    /**
     * Valida a entrada (chave => valor) e a mescla sobre os valores atuais: valor vazio remove a chave; chaves que
     * não vieram permanecem. Devolve o JSON a gravar (null = nenhum valor). Erros vão para $erros com a chave
     * "campos_extras.<chave>". Com $exigir, campos obrigatórios sem valor viram erro.
     * @param array<string,string> $erros
     */
    public static function normalizar(string $entidade, mixed $entrada, ?string $atualJson, bool $exigir, array &$erros): ?string
    {
        $entrada = $entrada ?? [];
        if (!is_array($entrada) || ($entrada !== [] && array_is_list($entrada))) {
            $erros['campos_extras'] = 'Os campos extras têm um formato inválido.';
            return $atualJson;
        }

        $defs = self::porChave($entidade);
        $valores = self::valores($atualJson);
        foreach ($entrada as $chave => $bruto) {
            $def = $defs[(string) $chave] ?? null;
            if ($def === null) {
                $erros['campos_extras.' . $chave] = 'Campo extra desconhecido ou inativo: ' . $chave . '.';
                continue;
            }
            $erro = null;
            $valor = self::converter($def, $bruto, $erro);
            if ($erro !== null) {
                $erros['campos_extras.' . $chave] = $erro;
            } elseif ($valor === null) {
                unset($valores[$chave]);
            } else {
                $valores[$chave] = $valor;
            }
        }

        if ($exigir) {
            foreach ($defs as $chave => $def) {
                if ((int) $def['obrigatorio'] === 1 && !isset($erros['campos_extras.' . $chave]) && !array_key_exists($chave, $valores)) {
                    $erros['campos_extras.' . $chave] = $def['tipo'] === 'checkbox'
                        ? "É preciso marcar {$def['rotulo']}."
                        : "O campo {$def['rotulo']} é obrigatório.";
                }
            }
        }

        if ($valores === []) {
            return null;
        }
        ksort($valores);
        return json_encode($valores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Converte um valor bruto para o tipo do campo. Vazio = null (remove o valor). */
    private static function converter(array $def, mixed $bruto, ?string &$erro): string|int|float|null
    {
        $rotulo = $def['rotulo'];
        if (is_string($bruto)) {
            if (!mb_check_encoding($bruto, 'UTF-8')) {
                $erro = "O campo {$rotulo} contém caracteres inválidos (esperado UTF-8).";
                return null;
            }
            $bruto = trim($bruto);
        }

        if ($def['tipo'] === 'checkbox') {
            if ($bruto === null || $bruto === '' || in_array($bruto, [0, '0', false, 'false', 'off', 'nao', 'não'], true)) {
                return null;
            }
            if (in_array($bruto, [1, '1', true, 'true', 'on', 'sim'], true)) {
                return 1;
            }
            $erro = "O campo {$rotulo} deve ser sim ou não.";
            return null;
        }

        if ($bruto === null || $bruto === '') {
            return null;
        }
        if (!is_scalar($bruto)) {
            $erro = "O campo {$rotulo} tem um valor inválido.";
            return null;
        }

        $texto = (string) $bruto;
        switch ($def['tipo']) {
            case 'texto':
                return mb_strlen($texto) > 255 ? self::falhar($erro, "O campo {$rotulo} deve ter no máximo 255 caracteres.") : $texto;
            case 'textarea':
                return mb_strlen($texto) > 5000 ? self::falhar($erro, "O campo {$rotulo} deve ter no máximo 5000 caracteres.") : $texto;
            case 'url':
                if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $texto)) {
                    $texto = 'https://' . $texto;
                }
                $partes = parse_url($texto);
                $ok = mb_strlen($texto) <= 500 && filter_var($texto, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) ($partes['scheme'] ?? '')), ['http', 'https'], true);
                return $ok ? $texto : self::falhar($erro, "O campo {$rotulo} deve ser um link válido.");
            case 'numero':
                // Texto digitado em pt-BR: "1.250,5" e "1.250" (milhar) valem 1250,5 e 1250; "12.5" continua 12,5.
                if (is_string($bruto) && (str_contains($texto, ',') || preg_match('/^-?\d{1,3}(\.\d{3})+$/', $texto))) {
                    $texto = str_replace(',', '.', str_replace('.', '', $texto));
                }
                if (!is_numeric($texto) || !is_finite((float) $texto) || abs((float) $texto) > 1e12) {
                    return self::falhar($erro, "O campo {$rotulo} deve ser um número.");
                }
                $n = round((float) $texto, 6);
                return floor($n) === $n ? (int) $n : $n;
            case 'data':
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return $texto;
                }
                return data_iso($texto) ?? self::falhar($erro, "O campo {$rotulo} deve ser uma data válida.");
            case 'select':
                return in_array($texto, $def['opcoes'], true) ? $texto : self::falhar($erro, "O campo {$rotulo} tem uma opção inválida.");
        }
        return self::falhar($erro, "Tipo de campo extra não suportado: {$def['tipo']}.");
    }

    private static function falhar(?string &$erro, string $mensagem): null
    {
        $erro = $mensagem;
        return null;
    }

    // =====================================================================================
    // Exibição
    // =====================================================================================

    /** Valor legível em texto puro (colunas, modelos, contexto de agentes). */
    public static function texto(array $def, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        return match ($def['tipo']) {
            'checkbox' => (int) $valor === 1 ? 'Sim' : 'Não',
            'data'     => data_br((string) $valor),
            'numero'   => self::numeroBr($valor),
            default    => (string) $valor,
        };
    }

    private static function numeroBr(mixed $valor): string
    {
        $n = (float) $valor;
        return floor($n) === $n ? number_format($n, 0, ',', '.') : rtrim(rtrim(number_format($n, 6, ',', '.'), '0'), ',');
    }

    /** Pares rótulo => valor para a ficha do detalhe (links e quebras de linha já em HTML). Só campos preenchidos. */
    public static function paraFicha(string $entidade, array $registro): array
    {
        $valores = self::valores($registro['campos_extras'] ?? null);
        $pares = [];
        foreach (self::definicoes($entidade) as $def) {
            $valor = $valores[$def['chave']] ?? null;
            if ($valor === null || $valor === '') {
                continue;
            }
            $texto = self::texto($def, $valor);
            $pares[$def['rotulo']] = match ($def['tipo']) {
                'url'      => ['html' => '<a class="underline underline-offset-4" href="' . e($texto) . '" target="_blank" rel="noopener noreferrer">' . e($texto) . '</a>'],
                'textarea' => ['html' => nl2br(e($texto))],
                default    => $texto,
            };
        }
        return $pares;
    }

    /** Valores preenchidos como rótulo => texto legível (para o contexto enviado a agentes). */
    public static function paraContexto(string $entidade, array $registro, string $chave): ?string
    {
        $def = self::porChave($entidade)[$chave] ?? null;
        if ($def === null) {
            return null;
        }
        $valor = self::valores($registro['campos_extras'] ?? null)[$chave] ?? null;
        $texto = self::texto($def, $valor);
        return $texto === '' ? null : $texto;
    }

    // =====================================================================================
    // Filtros e colunas de lista
    // =====================================================================================

    /** Filtros de lista dos campos de lista de opções e sim/não: "x_<chave>" => ['rotulo', 'opcoes']. */
    public static function filtros(string $entidade): array
    {
        $filtros = [];
        foreach (self::definicoes($entidade) as $def) {
            if ($def['tipo'] === 'select') {
                $filtros[self::PREFIXO_FILTRO . $def['chave']] = ['rotulo' => $def['rotulo'], 'opcoes' => array_combine($def['opcoes'], $def['opcoes'])];
            } elseif ($def['tipo'] === 'checkbox') {
                $filtros[self::PREFIXO_FILTRO . $def['chave']] = ['rotulo' => $def['rotulo'], 'opcoes' => ['sim' => 'Sim', 'nao' => 'Não']];
            }
        }
        return $filtros;
    }

    /** Colunas opcionais da lista (todas as definições ativas), no formato de CrudController::colunas(). */
    public static function colunas(string $entidade): array
    {
        $colunas = [];
        foreach (self::definicoes($entidade) as $def) {
            $colunas[self::PREFIXO_FILTRO . $def['chave']] = [
                'rotulo' => $def['rotulo'],
                'render' => static fn (array $linha): string => self::texto($def, self::valores($linha['campos_extras'] ?? null)[$def['chave']] ?? null),
            ];
        }
        return $colunas;
    }
}
