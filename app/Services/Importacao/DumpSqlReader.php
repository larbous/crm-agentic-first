<?php

declare(strict_types=1);

namespace App\Services\Importacao;

/**
 * Leitor de dump mysqldump (formato texto), sem depender de pdo_mysql/mysqli (não garantidos na
 * hospedagem compartilhada — SPEC/CLAUDE.md). Extrai as tuplas de cada `INSERT INTO` diretamente do
 * texto do arquivo, tokenizando aspas/escapes manualmente (não dá pra confiar em split por vírgula:
 * campos de texto longo — proposta, contrato, tarefa — têm parênteses e vírgulas dentro das aspas).
 * Usado só pelo importador (`scripts/importar-perfex.php`), fora do fluxo normal da aplicação.
 */
final class DumpSqlReader
{
    private string $conteudo;

    public function __construct(string $caminho)
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) {
            throw new \RuntimeException("Não foi possível ler o dump: {$caminho}");
        }
        $this->conteudo = $conteudo;
    }

    /**
     * Todas as linhas de dado de uma tabela, como array associativo coluna => valor (string|int|float|null).
     * Junta várias instruções INSERT da mesma tabela, cada uma com sua própria lista de colunas.
     * @return list<array<string, string|int|float|null>>
     */
    public function linhas(string $tabela): array
    {
        $linhas = [];
        $offset = 0;
        $agulha = 'INSERT INTO `' . $tabela . '`';
        while (($pos = strpos($this->conteudo, $agulha, $offset)) !== false) {
            $inicioColunas = strpos($this->conteudo, '(', $pos + strlen($agulha));
            $fimColunas = strpos($this->conteudo, ')', $inicioColunas);
            if ($inicioColunas === false || $fimColunas === false) {
                break;
            }
            $colunasBrutas = substr($this->conteudo, $inicioColunas + 1, $fimColunas - $inicioColunas - 1);
            $colunas = array_map(
                static fn (string $c): string => trim(trim($c), '`'),
                explode(',', $colunasBrutas)
            );

            $posValues = stripos($this->conteudo, 'VALUES', $fimColunas);
            if ($posValues === false) {
                break;
            }
            $inicioTuplas = $posValues + strlen('VALUES');
            [$tuplas, $fimTuplas] = $this->tokenizarTuplas($inicioTuplas);

            foreach ($tuplas as $tupla) {
                if (count($tupla) !== count($colunas)) {
                    throw new \RuntimeException(sprintf(
                        'Tabela %s: tupla com %d valores mas %d colunas.',
                        $tabela,
                        count($tupla),
                        count($colunas)
                    ));
                }
                $linhas[] = array_combine($colunas, $tupla);
            }

            $offset = $fimTuplas;
        }
        return $linhas;
    }

    /**
     * A partir de um offset logo após "VALUES", lê tuplas (...),(...),... até o ";" que fecha a instrução.
     * @return array{0: list<list<string|int|float|null>>, 1: int} tuplas e offset após o ";"
     */
    private function tokenizarTuplas(int $offset): array
    {
        $len = strlen($this->conteudo);
        $tuplas = [];
        $tuplaAtual = [];
        $campoAtual = '';
        $campoEmAspas = false;
        $campoTemAspas = false;
        $dentroString = false;
        $escapando = false;
        $profundidade = 0;
        $i = $offset;

        for (; $i < $len; $i++) {
            $c = $this->conteudo[$i];

            if ($dentroString) {
                if ($escapando) {
                    $campoAtual .= match ($c) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0",
                        'Z' => "\x1a", '\\' => '\\', "'" => "'", '"' => '"',
                        default => $c,
                    };
                    $escapando = false;
                    continue;
                }
                if ($c === '\\') {
                    $escapando = true;
                    continue;
                }
                if ($c === "'") {
                    // aspa dupla ('') também é escape de aspa simples (convenção SQL padrão)
                    if ($i + 1 < $len && $this->conteudo[$i + 1] === "'") {
                        $campoAtual .= "'";
                        $i++;
                        continue;
                    }
                    $dentroString = false;
                    continue;
                }
                $campoAtual .= $c;
                continue;
            }

            switch ($c) {
                case "'":
                    if (!$campoEmAspas) {
                        $campoAtual = ''; // descarta espaço/formatação antes da aspa de abertura
                    }
                    $dentroString = true;
                    $campoEmAspas = true;
                    $campoTemAspas = true;
                    break;
                case '(':
                    $profundidade++;
                    if ($profundidade === 1) {
                        $tuplaAtual = [];
                        $campoAtual = '';
                        $campoEmAspas = false;
                        $campoTemAspas = false;
                    }
                    break;
                case ')':
                    $profundidade--;
                    if ($profundidade === 0) {
                        $tuplaAtual[] = $this->valorFinal($campoAtual, $campoTemAspas);
                        $tuplas[] = $tuplaAtual;
                        $campoAtual = '';
                        $campoEmAspas = false;
                        $campoTemAspas = false;
                    }
                    break;
                case ',':
                    if ($profundidade === 1) {
                        $tuplaAtual[] = $this->valorFinal($campoAtual, $campoTemAspas);
                        $campoAtual = '';
                        $campoEmAspas = false;
                        $campoTemAspas = false;
                    } elseif ($profundidade === 0) {
                        // vírgula entre tuplas: nada a fazer
                    }
                    break;
                case ';':
                    if ($profundidade === 0) {
                        return [$tuplas, $i + 1];
                    }
                    break;
                default:
                    if ($profundidade === 1) {
                        $campoAtual .= $c;
                    }
            }
        }
        return [$tuplas, $len];
    }

    private function valorFinal(string $bruto, bool $tinhaAspas): string|int|float|null
    {
        if ($tinhaAspas) {
            return $bruto;
        }
        $bruto = trim($bruto);
        if ($bruto === '' || strcasecmp($bruto, 'NULL') === 0) {
            return null;
        }
        if (preg_match('/^-?\d+$/', $bruto)) {
            return (int) $bruto;
        }
        if (is_numeric($bruto)) {
            return (float) $bruto;
        }
        return $bruto; // ex.: 0x... ou outra constante não tratada — devolve como veio
    }
}
