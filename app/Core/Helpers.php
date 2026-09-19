<?php

declare(strict_types=1);

/**
 * Funções globais de apoio: escape, URLs, moeda, datas pt-BR e conversões.
 */

use App\Core\Config;
use App\Core\Csrf;

/** Escapa texto para saída HTML. */
function e(mixed $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL interna respeitando app.base_url. */
function url(string $caminho = '/'): string
{
    $base = rtrim((string) Config::obter('app.base_url', ''), '/');
    return $base . '/' . ltrim($caminho, '/');
}

/** URL de arquivo estático em /public/assets. */
function asset(string $caminho): string
{
    return url('assets/' . ltrim($caminho, '/'));
}

function csrf_token(): string
{
    return Csrf::token();
}

/** Campo hidden com o token CSRF para formulários. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

/** Agora, em ISO 8601 (fuso da aplicação). */
function agora(): string
{
    return date('Y-m-d H:i:s');
}

function hoje(): string
{
    return date('Y-m-d');
}

/** Formata centavos como moeda brasileira: 800000 → "R$ 8.000,00". */
function moeda(int|float|string|null $centavos): string
{
    if ($centavos === null || $centavos === '') {
        return 'R$ 0,00';
    }
    $c = (int) round((float) $centavos);
    $sinal = $c < 0 ? '-' : '';
    return $sinal . 'R$ ' . number_format(abs($c) / 100, 2, ',', '.');
}

/** Centavos → reais como float (para exibição em campos). */
function centavos_para_reais(?int $centavos): ?float
{
    return $centavos === null ? null : $centavos / 100;
}

/** Centavos → texto de entrada pt-BR sem símbolo: 800000 → "8.000,00". */
function centavos_para_texto(?int $centavos): string
{
    return $centavos === null ? '' : number_format($centavos / 100, 2, ',', '.');
}

/**
 * Reais → centavos. Aceita número (8000.5) ou texto ("R$ 8.000,50", "8000,5", "8000.50").
 * Retorna null para vazio ou valor não numérico.
 */
function reais_para_centavos(int|float|string|null $valor): ?int
{
    if ($valor === null) {
        return null;
    }
    if (is_int($valor) || is_float($valor)) {
        return (int) round($valor * 100);
    }
    $texto = trim(str_replace(['R$', ' ', "\u{00A0}"], '', $valor));
    if ($texto === '') {
        return null;
    }
    $negativo = str_starts_with($texto, '-');
    $texto = ltrim($texto, '-+');
    if (str_contains($texto, ',')) {
        $texto = str_replace('.', '', $texto);
        $texto = str_replace(',', '.', $texto);
    } elseif (substr_count($texto, '.') > 1 || preg_match('/\.\d{3}$/', $texto) === 1) {
        $texto = str_replace('.', '', $texto); // "8.000" ou "1.234.567" = milhar
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $texto)) {
        return null;
    }
    $centavos = (int) round(((float) $texto) * 100);
    return $negativo ? -$centavos : $centavos;
}

/** "2026-09-19" → "19/09/2026". Vazio/inválido → "". */
function data_br(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10));
    return $d && $d->format('Y-m-d') === substr($iso, 0, 10) ? $d->format('d/m/Y') : '';
}

/** "2026-09-19 14:30:00" → "19/09/2026 14:30". */
function datahora_br(?string $iso): string
{
    if ($iso === null || $iso === '') {
        return '';
    }
    $data = data_br($iso);
    if ($data === '') {
        return '';
    }
    return strlen($iso) >= 16 ? $data . ' ' . substr($iso, 11, 5) : $data;
}

/** "19/09/2026" → "2026-09-19". Inválido → null. */
function data_iso(?string $br): ?string
{
    if ($br === null) {
        return null;
    }
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($br), $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
        ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])
        : null;
}

/** Iniciais para avatar: "Ana Souza" → "AS". */
function iniciais(string $nome): string
{
    $partes = preg_split('/\s+/u', trim($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($partes === []) {
        return '?';
    }
    $primeira = mb_substr($partes[0], 0, 1);
    $ultima = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1) : '';
    return mb_strtoupper($primeira . $ultima);
}

/** Valor antigo de formulário (repopulado após erro de validação). */
function old(string $campo, mixed $padrao = ''): mixed
{
    return $_SESSION['_old'][$campo] ?? $padrao;
}

/** Minúsculas sem acentos, para buscas insensíveis a caixa e acento. */
function normalizar_busca(?string $texto): string
{
    $texto = mb_strtolower((string) $texto);
    return strtr($texto, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ]);
}

/** Somente dígitos. */
function so_digitos(?string $texto): string
{
    return preg_replace('/\D+/', '', (string) $texto) ?? '';
}

/** Valida CNPJ (14 dígitos, com dígitos verificadores). */
function cnpj_valido(string $cnpj): bool
{
    $d = so_digitos($cnpj);
    if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) {
        return false;
    }
    foreach ([12, 13] as $n) {
        $soma = 0;
        $peso = $n - 7;
        for ($i = 0; $i < $n; $i++) {
            $soma += (int) $d[$i] * $peso;
            $peso = $peso === 2 ? 9 : $peso - 1;
        }
        $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
        if ((int) $d[$n] !== $dv) {
            return false;
        }
    }
    return true;
}

/** Valida CPF (11 dígitos, com dígitos verificadores). */
function cpf_valido(string $cpf): bool
{
    $d = so_digitos($cpf);
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
        return false;
    }
    foreach ([9, 10] as $n) {
        $soma = 0;
        for ($i = 0; $i < $n; $i++) {
            $soma += (int) $d[$i] * ($n + 1 - $i);
        }
        $dv = ($soma * 10) % 11 % 10;
        if ((int) $d[$n] !== $dv) {
            return false;
        }
    }
    return true;
}

/** "12345678000195" → "12.345.678/0001-95" (devolve o original se não tiver 14 dígitos). */
function cnpj_formatado(?string $cnpj): string
{
    $d = so_digitos($cnpj);
    return strlen($d) === 14
        ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12)
        : (string) $cnpj;
}

/** "01234567" → "01234-567". */
function cep_formatado(?string $cep): string
{
    $d = so_digitos($cep);
    return strlen($d) === 8 ? substr($d, 0, 5) . '-' . substr($d, 5) : (string) $cep;
}

/** Aceita apenas caminhos internos (evita open redirect). Devolve $padrao se inválido. */
function caminho_seguro(?string $caminho, string $padrao = '/'): string
{
    if ($caminho !== null && preg_match('#^/(?!/)[^\r\n\\\\]*$#', $caminho) === 1) {
        return $caminho;
    }
    return $padrao;
}

/** Dias inteiros entre duas datas ISO (b - a); null se inválidas. */
function dias_entre(?string $a, ?string $b): ?int
{
    if (!$a || !$b) {
        return null;
    }
    try {
        return (int) (new DateTimeImmutable(substr($a, 0, 10)))->diff(new DateTimeImmutable(substr($b, 0, 10)))->format('%r%a');
    } catch (Throwable) {
        return null;
    }
}
