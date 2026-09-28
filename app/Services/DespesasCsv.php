<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Repositories\Repositorios;
use Throwable;

/**
 * Importação de despesas a partir de CSV (ex.: planilha do Google exportada em Arquivo → Baixar → CSV).
 * Tudo ou nada: se qualquer linha tiver erro, nada é gravado (evita duplicar ao reenviar a planilha corrigida).
 * Toda gravação passa pelo ActionExecutor, dentro de uma transação que é desfeita em caso de erro ou de "só validar".
 */
final class DespesasCsv
{
    public const MAX_LINHAS = 2000;
    public const MAX_BYTES = 1048576;
    public const OBRIGATORIAS = ['descricao', 'categoria', 'valor', 'vencimento'];
    public const COLUNAS = ['descricao', 'categoria', 'fornecedor', 'valor', 'vencimento', 'situacao', 'data_pagamento', 'meio_pagamento', 'forma_pagamento', 'notas'];

    /** Cabeçalho normalizado (sem acento, minúsculo, "_" no lugar de espaço) => coluna. Além das próprias colunas. */
    private const APELIDOS_COLUNA = [
        'descricao' => 'descricao', 'despesa' => 'descricao', 'categoria' => 'categoria', 'fornecedor' => 'fornecedor',
        'quem_recebe' => 'fornecedor', 'valor' => 'valor', 'vencimento' => 'vencimento', 'data' => 'vencimento',
        'situacao' => 'situacao', 'status' => 'situacao', 'data_pagamento' => 'data_pagamento', 'data_de_pagamento' => 'data_pagamento',
        'pago_em' => 'data_pagamento', 'meio_pagamento' => 'meio_pagamento', 'meio_de_pagamento' => 'meio_pagamento', 'meio' => 'meio_pagamento',
        'forma_pagamento' => 'forma_pagamento', 'forma_de_pagamento' => 'forma_pagamento', 'forma' => 'forma_pagamento',
        'notas' => 'notas', 'observacoes' => 'notas', 'observacao' => 'notas', 'obs' => 'notas',
    ];

    private const APELIDOS_SITUACAO = ['aberta' => 'pendente', 'aberto' => 'pendente', 'em aberto' => 'pendente', 'quitada' => 'pago', 'quitado' => 'pago'];
    private const APELIDOS_MEIO = ['ted' => 'transferencia', 'doc' => 'transferencia', 'tef' => 'transferencia', 'credito' => 'cartao_credito', 'debito' => 'cartao_debito', 'especie' => 'dinheiro'];
    private const APELIDOS_FORMA = ['avista' => 'a_vista', 'parcelada' => 'parcelado', 'recorrente' => 'recorrente', 'mensal' => 'recorrente', 'fixa' => 'recorrente'];

    /** Conteúdo do modelo de planilha (library/despesas-modelo.csv), com BOM para o Excel reconhecer os acentos. */
    public static function modelo(): string
    {
        $csv = (string) file_get_contents(dirname(__DIR__, 2) . '/library/despesas-modelo.csv');
        return str_starts_with($csv, "\xEF\xBB\xBF") ? $csv : "\xEF\xBB\xBF" . $csv;
    }

    /**
     * Lê o CSV. Detecta UTF-8 ou Windows-1252 e o separador (vírgula, ponto e vírgula ou tab).
     * @return array{linhas:list<array<string,string>>,erros:list<string>} cada linha traz `_linha` (número na planilha, cabeçalho = 1)
     */
    public static function ler(string $conteudo): array
    {
        $conteudo = str_starts_with($conteudo, "\xEF\xBB\xBF") ? substr($conteudo, 3) : $conteudo;
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $primeira = strtok(ltrim($conteudo), "\n") ?: '';
        $separador = ',';
        $melhor = substr_count($primeira, ',');
        foreach ([';' => ';', "\t" => "\t"] as $candidato) {
            if (substr_count($primeira, $candidato) > $melhor) {
                $separador = $candidato;
                $melhor = substr_count($primeira, $candidato);
            }
        }

        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $conteudo);
        rewind($fp);

        $mapa = null;
        $linhas = [];
        $numero = 0;
        while (($registro = fgetcsv($fp, 0, $separador, '"', '')) !== false) {
            $numero++;
            $registro = array_map(static fn (?string $c): string => trim(str_replace("\u{00A0}", ' ', (string) $c)), $registro);
            if (implode('', $registro) === '') {
                continue;
            }
            if ($mapa === null) {
                $mapa = [];
                foreach ($registro as $i => $cabecalho) {
                    $chave = preg_replace('/[\s\-]+/', '_', normalizar_busca($cabecalho)) ?? '';
                    if (isset(self::APELIDOS_COLUNA[$chave]) && !in_array(self::APELIDOS_COLUNA[$chave], $mapa, true)) {
                        $mapa[$i] = self::APELIDOS_COLUNA[$chave];
                    }
                }
                $faltam = array_diff(self::OBRIGATORIAS, $mapa);
                if ($faltam !== []) {
                    fclose($fp);
                    return ['linhas' => [], 'erros' => ['Cabeçalho inválido: faltam as colunas ' . implode(', ', $faltam) . '. Use o modelo de planilha e não renomeie as colunas.']];
                }
                continue;
            }
            if (count($linhas) >= self::MAX_LINHAS) {
                fclose($fp);
                return ['linhas' => [], 'erros' => ['A planilha tem mais de ' . self::MAX_LINHAS . ' linhas; divida em arquivos menores.']];
            }
            $linha = array_fill_keys(self::COLUNAS, '') + ['_linha' => (string) $numero];
            foreach ($mapa as $i => $coluna) {
                $linha[$coluna] = $registro[$i] ?? '';
            }
            $linhas[] = $linha;
        }
        fclose($fp);

        return $linhas === [] ? ['linhas' => [], 'erros' => ['A planilha não tem nenhuma despesa abaixo do cabeçalho.']] : ['linhas' => $linhas, 'erros' => []];
    }

    /**
     * Valida (e, se $gravar, grava) as linhas lidas. Sem nenhum erro e com $gravar, confirma tudo; caso contrário desfaz tudo.
     * @param list<array<string,string>> $linhas saída de ler()
     * @return array{ok:bool,gravadas:bool,total:int,erros:list<string>,categorias_novas:list<string>}
     */
    public function importar(array $linhas, bool $criarCategorias, bool $gravar): array
    {
        $pdo = DB::conexao();
        $pdo->beginTransaction();
        $erros = [];
        $novas = [];
        try {
            $x = new ActionExecutor();
            $categorias = [];
            foreach (Repositorios::para('categorias_despesa')->todas() as $c) {
                $categorias[normalizar_busca((string) $c['nome'])] = (int) $c['id'];
            }

            foreach ($linhas as $l) {
                $n = (int) $l['_linha'];
                $nomeCategoria = trim($l['categoria']);
                $categoriaId = $categorias[normalizar_busca($nomeCategoria)] ?? null;
                if ($categoriaId === null && $nomeCategoria !== '' && $criarCategorias) {
                    $r = $x->criar('categorias_despesa', ['nome' => $nomeCategoria], 'humano');
                    if ($r->ok) {
                        $categoriaId = $categorias[normalizar_busca($nomeCategoria)] = (int) $r->id;
                        $novas[] = $nomeCategoria;
                    } else {
                        $erros[] = "Linha {$n}: não foi possível criar a categoria \"{$nomeCategoria}\": " . implode(' ', $r->erros ?: [$r->mensagem]);
                        continue;
                    }
                }
                [$dados, $errosLinha] = $this->converter($l, $categoriaId);
                if ($errosLinha === []) {
                    $r = $x->criar('despesas', $dados, 'humano');
                    if (!$r->ok) {
                        $errosLinha = $r->erros !== [] ? array_values($r->erros) : [$r->mensagem];
                    }
                }
                foreach ($errosLinha as $e) {
                    $erros[] = "Linha {$n}: {$e}";
                }
            }

            if ($erros === [] && $gravar) {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            Opcoes::limpar();
        }

        return ['ok' => $erros === [], 'gravadas' => $erros === [] && $gravar, 'total' => count($linhas), 'erros' => $erros, 'categorias_novas' => array_values(array_unique($novas))];
    }

    /** @return array{0:array<string,mixed>,1:list<string>} campos para `criar('despesas')` e mensagens de erro da linha */
    private function converter(array $l, ?int $categoriaId): array
    {
        $erros = [];
        if ($categoriaId === null) {
            $erros[] = $l['categoria'] === ''
                ? 'informe a categoria.'
                : "a categoria \"{$l['categoria']}\" não existe (ajuste o nome ou marque \"Criar as categorias que não existirem\").";
        }

        $data = static fn (string $v): string => $v === '' ? '' : (data_iso($v) ?? $v);
        $pagamento = $data($l['data_pagamento']);

        $situacao = null;
        if ($l['situacao'] === '') {
            $situacao = $pagamento !== '' ? 'pago' : 'pendente';
        } else {
            $situacao = self::chave('status_despesa', $l['situacao'], self::APELIDOS_SITUACAO);
            if ($situacao === null) {
                $erros[] = "situação \"{$l['situacao']}\" não reconhecida (use A pagar, Paga ou Cancelada).";
            }
        }

        $meio = null;
        if ($l['meio_pagamento'] !== '') {
            $meio = self::chave('meio_pagamento', $l['meio_pagamento'], self::APELIDOS_MEIO);
            if ($meio === null) {
                $erros[] = "meio de pagamento \"{$l['meio_pagamento']}\" não reconhecido (use " . implode(', ', Schema::opcoes('meio_pagamento')) . ').';
            }
        }

        $forma = 'a_vista';
        if ($l['forma_pagamento'] !== '') {
            $forma = self::chave('forma_pagamento_despesa', $l['forma_pagamento'], self::APELIDOS_FORMA);
            if ($forma === null) {
                $erros[] = "forma de pagamento \"{$l['forma_pagamento']}\" não reconhecida (use À vista, Parcelado ou Recorrente).";
            }
        }

        return [[
            'descricao' => $l['descricao'], 'categoria_id' => $categoriaId, 'fornecedor' => $l['fornecedor'] !== '' ? $l['fornecedor'] : null,
            'valor' => $l['valor'], 'vencimento' => $data($l['vencimento']), 'status' => $situacao,
            'data_pagamento' => $pagamento !== '' ? $pagamento : null, 'meio_pagamento' => $meio, 'forma_pagamento' => $forma,
            'notas' => $l['notas'] !== '' ? $l['notas'] : null,
        ], $erros];
    }

    /** Chave do enum a partir do texto digitado (chave ou rótulo, sem acento/caixa) ou de um apelido. */
    private static function chave(string $opcoes, string $texto, array $apelidos): ?string
    {
        return $apelidos[normalizar_busca($texto)] ?? Schema::chaveDoEnum($opcoes, $texto);
    }
}
