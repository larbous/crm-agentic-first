<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\AgenteRepository;
use App\Services\Schema;

/**
 * Validação do `.squad.json` (SPEC §7.1). Devolve a definição normalizada ou a lista de erros em português.
 * Etapas: `{"agente": "slug"}` (chama um agente) ou `{"acao": "tarefa"|"converter_cliente"}` (ação fixa do servidor, sem IA),
 * ambas com `condicao` e `usa_saida_de` opcionais. Chaves desconhecidas são erro.
 */
final class SquadDefinicao
{
    public const MAX_ETAPAS = 12;

    /** Ações fixas que uma etapa pode executar sem IA. */
    public const ACOES = ['tarefa', 'converter_cliente'];

    /** Prefixo de variável de condição => entidade (Schema). */
    public const ENTIDADES_DE_CONDICAO = [
        'empresa' => 'empresas', 'contato' => 'contatos', 'negocio' => 'negocios', 'proposta' => 'propostas', 'contrato' => 'contratos',
    ];

    private const CHAVES = ['slug', 'nome', 'descricao', 'versao', 'entrada', 'etapas', 'parar_se', 'gatilho'];
    private const CHAVES_ETAPA = ['agente', 'acao', 'dados', 'condicao', 'usa_saida_de'];
    private const CAMPOS_TAREFA = ['titulo', 'descricao', 'prioridade', 'tipo', 'vencimento_em_dias'];

    /**
     * @param string|array $entrada JSON (texto) ou array já decodificado
     * @return array{ok:true,definicao:array,erros:list<string>}|array{ok:false,definicao:null,erros:list<string>}
     */
    public static function validar(string|array $entrada): array
    {
        if (is_string($entrada)) {
            $entrada = json_decode($entrada, true);
            if (!is_array($entrada) || array_is_list($entrada)) {
                return self::falha(['O conteúdo não é um objeto JSON válido (' . json_last_error_msg() . ').']);
            }
        } elseif (array_is_list($entrada)) {
            return self::falha(['O conteúdo deve ser um objeto JSON, não uma lista.']);
        }

        $erros = [];
        foreach (array_diff(array_keys($entrada), self::CHAVES) as $chave) {
            $erros[] = "Chave desconhecida: \"{$chave}\".";
        }

        $slug = $entrada['slug'] ?? null;
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) !== 1) {
            $erros[] = '"slug" é obrigatório: 2 a 63 caracteres, só minúsculas, números e hífen.';
        }
        $nome = $entrada['nome'] ?? null;
        if (!is_string($nome) || trim($nome) === '' || mb_strlen($nome) > 120) {
            $erros[] = '"nome" é obrigatório (até 120 caracteres).';
        }
        $descricao = $entrada['descricao'] ?? '';
        if (!is_string($descricao) || mb_strlen($descricao) > 300) {
            $erros[] = '"descricao" deve ser um texto de até 300 caracteres.';
        }
        $versao = $entrada['versao'] ?? 1;
        if (!is_int($versao) || $versao < 1) {
            $erros[] = '"versao" deve ser um inteiro a partir de 1.';
        }
        $alvo = $entrada['entrada'] ?? null;
        if (!is_string($alvo) || !in_array($alvo, AgenteDefinicao::ENTRADAS, true)) {
            $erros[] = '"entrada" deve ser um de: ' . implode(', ', AgenteDefinicao::ENTRADAS) . '.';
            $alvo = null;
        }

        $etapas = self::etapas($entrada['etapas'] ?? null, $alvo, $erros);
        $slugsDeAgente = array_values(array_filter(array_column($etapas, 'agente')));

        $pararSe = $entrada['parar_se'] ?? null;
        if ($pararSe !== null) {
            if (!is_string($pararSe) || trim($pararSe) === '') {
                $erros[] = '"parar_se" deve ser um texto com uma condição (ex.: "triagem-formulario.status == spam").';
                $pararSe = null;
            } else {
                $pararSe = trim($pararSe);
                if (($erro = self::erroDeCondicao($pararSe, $slugsDeAgente)) !== null) {
                    $erros[] = "\"parar_se\": {$erro}";
                }
            }
        }

        $gatilho = AgenteDefinicao::gatilho($entrada['gatilho'] ?? ['tipo' => 'manual'], $alvo, $erros);

        if ($erros !== []) {
            return self::falha($erros);
        }
        $definicao = [
            'slug' => $slug, 'nome' => trim($nome), 'descricao' => trim($descricao), 'versao' => $versao, 'entrada' => $alvo,
            'etapas' => $etapas,
        ];
        if ($pararSe !== null) {
            $definicao['parar_se'] = $pararSe;
        }
        $definicao['gatilho'] = $gatilho;
        return ['ok' => true, 'erros' => [], 'definicao' => $definicao];
    }

    /**
     * @param list<string> $erros
     * @return list<array> etapas normalizadas (só as chaves em uso)
     */
    private static function etapas(mixed $bruto, ?string $entradaSquad, array &$erros): array
    {
        if (!is_array($bruto) || $bruto === [] || !array_is_list($bruto)) {
            $erros[] = '"etapas" deve ser uma lista com ao menos uma etapa.';
            return [];
        }
        if (count($bruto) > self::MAX_ETAPAS) {
            $erros[] = '"etapas" aceita no máximo ' . self::MAX_ETAPAS . ' etapas.';
            return [];
        }
        $agentes = new AgenteRepository();
        $etapas = [];
        $anteriores = [];
        foreach ($bruto as $i => $e) {
            $n = $i + 1;
            if (!is_array($e) || array_is_list($e)) {
                $erros[] = "Etapa {$n}: deve ser um objeto.";
                continue;
            }
            foreach (array_diff(array_keys($e), self::CHAVES_ETAPA) as $chave) {
                $erros[] = "Etapa {$n}: chave desconhecida \"{$chave}\".";
            }
            $agente = $e['agente'] ?? null;
            $acao = $e['acao'] ?? null;
            if (($agente === null) === ($acao === null)) {
                $erros[] = "Etapa {$n}: informe \"agente\" (slug de um agente) ou \"acao\" (" . implode(', ', self::ACOES) . '), não os dois.';
                continue;
            }

            $dados = [];
            if ($agente !== null) {
                if (!is_string($agente) || preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $agente) !== 1) {
                    $erros[] = "Etapa {$n}: \"agente\" deve ser o slug de um agente.";
                    continue;
                }
                if ($agentes->porSlug($agente) === null) {
                    $erros[] = "Etapa {$n}: o agente \"{$agente}\" não existe. Cadastre ou importe o agente antes.";
                }
                if (isset($e['dados'])) {
                    $erros[] = "Etapa {$n}: \"dados\" só vale para etapas de \"acao\".";
                }
            } else {
                if (!is_string($acao) || !in_array($acao, self::ACOES, true)) {
                    $erros[] = "Etapa {$n}: \"acao\" deve ser uma de: " . implode(', ', self::ACOES) . '.';
                    continue;
                }
                $dados = self::dadosDaAcao($acao, $e['dados'] ?? [], $entradaSquad, $n, $erros);
            }

            $usa = $e['usa_saida_de'] ?? [];
            if (!is_array($usa) || ($usa !== [] && !array_is_list($usa)) || array_filter($usa, static fn ($s): bool => !is_string($s)) !== []) {
                $erros[] = "Etapa {$n}: \"usa_saida_de\" deve ser uma lista de slugs de agentes de etapas anteriores.";
                $usa = [];
            }
            foreach ($usa as $slugAnterior) {
                if (!in_array($slugAnterior, $anteriores, true)) {
                    $erros[] = "Etapa {$n}: \"usa_saida_de\" cita \"{$slugAnterior}\", que não é uma etapa anterior.";
                }
            }

            $condicao = $e['condicao'] ?? null;
            if ($condicao !== null) {
                if (!is_string($condicao) || trim($condicao) === '') {
                    $erros[] = "Etapa {$n}: \"condicao\" deve ser um texto (ex.: \"empresa.classificacao in [A,B]\").";
                    $condicao = null;
                } else {
                    $condicao = trim($condicao);
                    if (($erro = self::erroDeCondicao($condicao, $anteriores)) !== null) {
                        $erros[] = "Etapa {$n}: \"condicao\": {$erro}";
                    }
                }
            }

            // Só as chaves em uso: a definição exportada fica enxuta e pode ser importada de volta.
            $etapa = $agente !== null ? ['agente' => $agente] : ['acao' => $acao];
            if ($dados !== []) {
                $etapa['dados'] = $dados;
            }
            if ($condicao !== null) {
                $etapa['condicao'] = $condicao;
            }
            if ($usa !== []) {
                $etapa['usa_saida_de'] = array_values(array_unique($usa));
            }
            $etapas[] = $etapa;
            if ($agente !== null) {
                $anteriores[] = $agente;
            }
        }
        return $etapas;
    }

    /** @param list<string> $erros */
    private static function dadosDaAcao(string $acao, mixed $dados, ?string $entradaSquad, int $n, array &$erros): array
    {
        if ($acao === 'converter_cliente') {
            if ($dados !== [] && $dados !== null) {
                $erros[] = "Etapa {$n}: \"converter_cliente\" não tem \"dados\".";
            }
            if ($entradaSquad === 'nenhuma') {
                $erros[] = "Etapa {$n}: \"converter_cliente\" precisa de um registro (\"entrada\" não pode ser \"nenhuma\").";
            }
            return [];
        }

        if (!is_array($dados) || ($dados !== [] && array_is_list($dados))) {
            $erros[] = "Etapa {$n}: \"dados\" da tarefa deve ser um objeto com ao menos \"titulo\".";
            return [];
        }
        foreach (array_diff(array_keys($dados), self::CAMPOS_TAREFA) as $chave) {
            $erros[] = "Etapa {$n}: campo desconhecido em \"dados\": \"{$chave}\" (use: " . implode(', ', self::CAMPOS_TAREFA) . ').';
        }
        $titulo = $dados['titulo'] ?? null;
        if (!is_string($titulo) || trim($titulo) === '' || mb_strlen($titulo) > 200) {
            $erros[] = "Etapa {$n}: \"dados.titulo\" é obrigatório (até 200 caracteres).";
        }
        $saida = ['titulo' => is_string($titulo) ? trim($titulo) : ''];
        if (isset($dados['descricao'])) {
            if (!is_string($dados['descricao']) || mb_strlen($dados['descricao']) > 2000) {
                $erros[] = "Etapa {$n}: \"dados.descricao\" deve ser um texto de até 2000 caracteres.";
            } else {
                $saida['descricao'] = trim($dados['descricao']);
            }
        }
        foreach (['prioridade' => 'prioridade_tar', 'tipo' => 'tipo_tarefa'] as $campo => $opcoes) {
            if (isset($dados[$campo])) {
                if (!is_string($dados[$campo]) || !array_key_exists($dados[$campo], Schema::opcoes($opcoes))) {
                    $erros[] = "Etapa {$n}: \"dados.{$campo}\" deve ser um de: " . implode(', ', array_keys(Schema::opcoes($opcoes))) . '.';
                } else {
                    $saida[$campo] = $dados[$campo];
                }
            }
        }
        if (isset($dados['vencimento_em_dias'])) {
            if (!is_int($dados['vencimento_em_dias']) || $dados['vencimento_em_dias'] < 0 || $dados['vencimento_em_dias'] > 365) {
                $erros[] = "Etapa {$n}: \"dados.vencimento_em_dias\" deve ser um inteiro entre 0 e 365.";
            } else {
                $saida['vencimento_em_dias'] = $dados['vencimento_em_dias'];
            }
        }
        return $saida;
    }

    /**
     * Mensagem de erro de uma condição (sintaxe e variável), ou null se estiver correta.
     * @param list<string> $slugsDeAgente agentes cujas saídas a condição pode citar
     */
    private static function erroDeCondicao(string $expressao, array $slugsDeAgente): ?string
    {
        $a = Condicao::analisar($expressao);
        if (is_string($a)) {
            return $a . '.';
        }
        $variavel = $a['variavel'];
        if ($variavel === 'origem') {
            return null;
        }
        if (preg_match('/^([a-z0-9-]+)\.([a-z0-9_]+)$/', $variavel, $m) !== 1) {
            return "variável desconhecida \"{$variavel}\".";
        }
        [, $prefixo, $campo] = $m;
        if (isset(self::ENTIDADES_DE_CONDICAO[$prefixo])) {
            $campos = Schema::entidade(self::ENTIDADES_DE_CONDICAO[$prefixo])['campos'] ?? [];
            if (!isset($campos[$campo]) || in_array($campo, AgenteDefinicao::CAMPOS_SENSIVEIS, true)) {
                return "o campo \"{$campo}\" não existe em {$prefixo}.";
            }
            return null;
        }
        if (in_array($prefixo, $slugsDeAgente, true)) {
            return in_array($campo, ['status', 'resumo'], true) ? null : "de um agente só é possível usar \"{$prefixo}.status\" ou \"{$prefixo}.resumo\".";
        }
        return "\"{$prefixo}\" não é uma etapa anterior nem um dos registros (" . implode(', ', array_keys(self::ENTIDADES_DE_CONDICAO)) . ').';
    }

    private static function falha(array $erros): array
    {
        return ['ok' => false, 'definicao' => null, 'erros' => $erros];
    }
}
