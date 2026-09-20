<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\ConfiguracaoRepository;
use App\Services\Events;
use App\Services\Schema;

/**
 * Validação do `.agent.json` (SPEC §6.1). Devolve a definição normalizada (com padrões) ou a lista de erros em
 * português. Chaves desconhecidas são erro (evita typos silenciosos como "acoes_permitida").
 */
final class AgenteDefinicao
{
    /** Padrão só usado se `ia.modelo_agente` não estiver em `configuracoes` e o JSON não trouxer o modelo. */
    public const MODELO_PADRAO = 'claude-sonnet-5';

    public const ENTRADAS = ['empresas', 'contatos', 'negocios', 'propostas', 'contratos', 'nenhuma'];
    public const APROVACOES = ['sempre', 'escritas', 'nunca'];

    /** Chave de contexto_relacionado => limite de N (empresa é 0/1). */
    public const RELACIONADOS = [
        'atividades' => 30, 'tarefas' => 30, 'contatos' => 10, 'negocios' => 10, 'propostas' => 5, 'contratos' => 5,
        'servicos' => 60, 'modelos_proposta' => 5, 'modelos_contrato' => 5, 'modelos_mensagem' => 5, 'empresa' => 1,
        'negocios_parados' => 50, 'previsoes_vencidas' => 50, 'tarefas_atrasadas' => 50,
    ];

    private const CHAVES = [
        'slug', 'nome', 'descricao', 'versao', 'modelo', 'max_tokens', 'web_search', 'entrada', 'contexto', 'contexto_relacionado',
        'prompt', 'acoes_permitidas', 'campos_gravaveis', 'aprovacao', 'gatilho',
    ];

    /** Nunca vão para a IA: tokens de links públicos e IP de quem aceitou/assinou. */
    public const CAMPOS_SENSIVEIS = ['token_publico', 'aceite_ip', 'assinatura_ip'];

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

        $modelo = $entrada['modelo'] ?? ((new ConfiguracaoRepository())->obter('ia.modelo_agente') ?: self::MODELO_PADRAO);
        if (!is_string($modelo) || preg_match('/^claude-[a-z0-9.-]{2,60}$/', $modelo) !== 1) {
            $erros[] = '"modelo" deve ser o identificador de um modelo Claude (ex.: claude-haiku-4-5-20251001).';
        }
        $maxTokens = $entrada['max_tokens'] ?? 1000;
        if (!is_int($maxTokens) || $maxTokens < 100 || $maxTokens > 8000) {
            $erros[] = '"max_tokens" deve ser um inteiro entre 100 e 8000.';
        }
        $webSearch = $entrada['web_search'] ?? false;
        if (!is_bool($webSearch)) {
            $erros[] = '"web_search" deve ser true ou false.';
        }

        $alvo = $entrada['entrada'] ?? null;
        if (!is_string($alvo) || !in_array($alvo, self::ENTRADAS, true)) {
            $erros[] = '"entrada" deve ser um de: ' . implode(', ', self::ENTRADAS) . '.';
            $alvo = null;
        }

        $contexto = self::listaDeTextos($entrada['contexto'] ?? [], 'contexto', $erros);
        if ($alvo !== null && $alvo !== 'nenhuma') {
            $campos = Schema::entidade($alvo)['campos'] ?? [];
            foreach ($contexto as $campo) {
                if (!isset($campos[$campo]) || in_array($campo, self::CAMPOS_SENSIVEIS, true)) {
                    $erros[] = "\"contexto\": o campo \"{$campo}\" não existe em {$alvo} (ou não pode ser enviado à IA).";
                }
            }
        } elseif ($alvo === 'nenhuma' && $contexto !== []) {
            $erros[] = '"contexto" deve ficar vazio quando "entrada" é "nenhuma".';
        }

        $relacionado = $entrada['contexto_relacionado'] ?? [];
        if (!is_array($relacionado) || ($relacionado !== [] && array_is_list($relacionado))) {
            $erros[] = '"contexto_relacionado" deve ser um objeto (ex.: {"atividades": 5}).';
            $relacionado = [];
        }
        foreach ($relacionado as $chave => $n) {
            $max = self::RELACIONADOS[$chave] ?? null;
            if ($max === null) {
                $erros[] = "\"contexto_relacionado\": chave desconhecida \"{$chave}\" (use: " . implode(', ', array_keys(self::RELACIONADOS)) . ').';
            } elseif (!is_int($n) || $n < 0 || $n > $max) {
                $erros[] = "\"contexto_relacionado.{$chave}\" deve ser um inteiro entre 0 e {$max}.";
            }
        }

        $prompt = $entrada['prompt'] ?? null;
        if (!is_string($prompt) || mb_strlen(trim($prompt)) < 20 || mb_strlen($prompt) > 8000) {
            $erros[] = '"prompt" é obrigatório (20 a 8000 caracteres).';
        }

        $acoes = self::listaDeTextos($entrada['acoes_permitidas'] ?? [], 'acoes_permitidas', $erros);
        foreach ($acoes as $a) {
            if (!in_array($a, AcaoAgente::ACOES, true)) {
                $erros[] = "\"acoes_permitidas\": ação inválida \"{$a}\" (permitidas: " . implode(', ', AcaoAgente::ACOES) . '; agentes não arquivam nem desfazem).';
            }
        }
        $gravaveis = self::listaDeTextos($entrada['campos_gravaveis'] ?? [], 'campos_gravaveis', $erros);
        $conhecidos = self::camposGravaveisConhecidos();
        foreach ($gravaveis as $campo) {
            if (!isset($conhecidos[$campo])) {
                $erros[] = "\"campos_gravaveis\": \"{$campo}\" não é um campo gravável (nem um dos nomes especiais: " . implode(', ', AcaoAgente::PSEUDOS) . ').';
            }
        }
        if (array_intersect($acoes, ['atualizar', 'criar', 'mover_etapa']) !== [] && $gravaveis === []) {
            $erros[] = '"campos_gravaveis" é obrigatório quando as ações incluem atualizar, criar ou mover_etapa.';
        }
        if (in_array('mover_etapa', $acoes, true) && !in_array('etapa', $gravaveis, true)) {
            $erros[] = '"mover_etapa" exige "etapa" em "campos_gravaveis".';
        }

        $aprovacao = $entrada['aprovacao'] ?? 'escritas';
        if (!is_string($aprovacao) || !in_array($aprovacao, self::APROVACOES, true)) {
            $erros[] = '"aprovacao" deve ser um de: ' . implode(', ', self::APROVACOES) . '.';
        }

        $gatilho = self::gatilho($entrada['gatilho'] ?? ['tipo' => 'manual'], $erros);

        if ($erros !== []) {
            return self::falha($erros);
        }
        return ['ok' => true, 'erros' => [], 'definicao' => [
            'slug' => $slug, 'nome' => trim($nome), 'descricao' => trim($descricao), 'versao' => $versao, 'modelo' => $modelo,
            'max_tokens' => $maxTokens, 'web_search' => $webSearch, 'entrada' => $alvo, 'contexto' => $contexto,
            'contexto_relacionado' => $relacionado, 'prompt' => trim($prompt),
            'acoes_permitidas' => $acoes, 'campos_gravaveis' => $gravaveis, 'aprovacao' => $aprovacao, 'gatilho' => $gatilho,
        ]];
    }

    /** Nomes de campo que um agente pode listar em `campos_gravaveis` (campos do Schema sem fk + nomes especiais). */
    public static function camposGravaveisConhecidos(): array
    {
        $nomes = array_fill_keys(AcaoAgente::PSEUDOS, true);
        foreach (array_unique([...AcaoAgente::ATUALIZAVEIS, ...AcaoAgente::CRIAVEIS]) as $entidade) {
            foreach (Schema::gravaveis($entidade) as $campo => $def) {
                if ($def['t'] !== 'fk') {
                    $nomes[$campo] = true;
                }
            }
        }
        return $nomes;
    }

    /** @param list<string> $erros */
    private static function listaDeTextos(mixed $valor, string $chave, array &$erros): array
    {
        if (!is_array($valor) || ($valor !== [] && !array_is_list($valor))) {
            $erros[] = "\"{$chave}\" deve ser uma lista de textos.";
            return [];
        }
        foreach ($valor as $item) {
            if (!is_string($item) || $item === '') {
                $erros[] = "\"{$chave}\" deve conter só textos não vazios.";
                return [];
            }
        }
        return array_values(array_unique($valor));
    }

    /** @param list<string> $erros */
    private static function gatilho(mixed $g, array &$erros): array
    {
        if (!is_array($g) || array_is_list($g) || !is_string($g['tipo'] ?? null)) {
            $erros[] = '"gatilho" deve ser {"tipo":"manual"}, {"tipo":"evento","evento":"..."} ou {"tipo":"agendado","cron":"..."}.';
            return ['tipo' => 'manual'];
        }
        switch ($g['tipo']) {
            case 'manual':
                return ['tipo' => 'manual'];
            case 'evento':
                if (!is_string($g['evento'] ?? null) || !in_array($g['evento'], Events::CONHECIDOS, true)) {
                    $erros[] = '"gatilho.evento" deve ser um evento conhecido: ' . implode(', ', Events::CONHECIDOS) . '.';
                    return ['tipo' => 'manual'];
                }
                return ['tipo' => 'evento', 'evento' => $g['evento']];
            case 'agendado':
                $cron = is_string($g['cron'] ?? null) ? trim($g['cron']) : '';
                if (preg_match('/^\S+ \S+ \S+ \S+ \S+$/', $cron) !== 1 || preg_match('/^[0-9*\/,\-]+( [0-9*\/,\-]+){4}$/', $cron) !== 1) {
                    $erros[] = '"gatilho.cron" deve ter 5 campos (minuto hora dia mês dia-da-semana), ex.: "0 8 * * 1".';
                    return ['tipo' => 'manual'];
                }
                return ['tipo' => 'agendado', 'cron' => $cron];
        }
        $erros[] = '"gatilho.tipo" deve ser manual, evento ou agendado.';
        return ['tipo' => 'manual'];
    }

    private static function falha(array $erros): array
    {
        return ['ok' => false, 'definicao' => null, 'erros' => $erros];
    }
}
