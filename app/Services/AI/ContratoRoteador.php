<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\ConsultaChat;
use App\Services\Schema;

/**
 * Validação do JSON devolvido pelo roteador (SPEC §3.4) contra a whitelist de ações, entidades, campos e
 * operadores. Nada que não passe aqui chega ao ActionExecutor. Devolve um plano normalizado ou o motivo da recusa.
 */
final class ContratoRoteador
{
    public const ACOES = ['criar', 'atualizar', 'arquivar', 'nota', 'tarefa', 'concluir', 'mover_etapa', 'consultar', 'desfazer', 'converter_cliente'];

    /** Entidades que o chat pode gravar (SPEC §3.4). */
    public const GRAVAVEIS = ['empresas', 'contatos', 'negocios', 'atividades', 'tarefas'];

    /** Chaves aceitas em "ref"/"alvo" (nome ou id do registro citado). */
    public const REFS = ['empresa', 'contato', 'negocio', 'tarefa'];

    /** Entidade fixa de cada ação especial (a ação manda; a entidade que a IA informar é ignorada). */
    private const ENTIDADE_DA_ACAO = [
        'nota' => 'atividades', 'tarefa' => 'tarefas', 'concluir' => 'tarefas',
        'mover_etapa' => 'negocios', 'converter_cliente' => 'empresas',
    ];

    /**
     * @return array{ok:true,plano:array}|array{ok:false,motivo:string}
     */
    public static function validar(mixed $saida): array
    {
        if (!is_array($saida) || array_is_list($saida)) {
            return self::recusa('a saída não é um objeto JSON');
        }
        $tipo = $saida['tipo'] ?? null;

        switch ($tipo) {
            case 'indefinido':
                $pergunta = $saida['pergunta'] ?? null;
                if (!is_string($pergunta) || trim($pergunta) === '' || mb_strlen($pergunta) > 300) {
                    return self::recusa('pergunta inválida');
                }
                return ['ok' => true, 'plano' => ['tipo' => 'indefinido', 'pergunta' => trim($pergunta)]];

            case 'agente':
            case 'squad':
                $slug = $saida['slug'] ?? null;
                if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) !== 1) {
                    return self::recusa('slug inválido');
                }
                $alvo = self::refs($saida['alvo'] ?? [], 'alvo');
                if (is_string($alvo)) {
                    return self::recusa($alvo);
                }
                $entrada = $saida['entrada'] ?? null;
                if ($entrada !== null && (!is_string($entrada) || mb_strlen($entrada) > 2000)) {
                    return self::recusa('entrada inválida');
                }
                return ['ok' => true, 'plano' => ['tipo' => $tipo, 'slug' => $slug, 'alvo' => $alvo, 'entrada' => $entrada]];

            case 'acao':
                return self::validarAcao($saida);
        }
        return self::recusa('tipo desconhecido');
    }

    private static function validarAcao(array $saida): array
    {
        $acao = $saida['acao'] ?? null;
        if (!is_string($acao) || !in_array($acao, self::ACOES, true)) {
            return self::recusa('ação fora da whitelist');
        }

        $entidade = self::ENTIDADE_DA_ACAO[$acao] ?? ($saida['entidade'] ?? null);
        if ($acao === 'desfazer') {
            return ['ok' => true, 'plano' => ['tipo' => 'acao', 'acao' => 'desfazer', 'entidade' => null, 'dados' => [], 'ref' => []]];
        }
        if (!is_string($entidade)) {
            return self::recusa('entidade ausente');
        }

        if ($acao === 'consultar') {
            $consulta = ConsultaChat::validar($entidade, $saida);
            if (is_string($consulta)) {
                return self::recusa($consulta);
            }
            return ['ok' => true, 'plano' => ['tipo' => 'acao', 'acao' => 'consultar', 'entidade' => $entidade, 'dados' => [], 'ref' => []] + $consulta];
        }

        if (!in_array($entidade, self::GRAVAVEIS, true)) {
            return self::recusa("entidade não gravável pelo chat: {$entidade}");
        }
        $ref = self::refs($saida['ref'] ?? [], 'ref');
        if (is_string($ref)) {
            return self::recusa($ref);
        }

        $dados = [];
        if (in_array($acao, ['criar', 'atualizar', 'nota', 'tarefa', 'mover_etapa'], true)) {
            $dados = self::dados($acao, $entidade, $saida['dados'] ?? []);
            if (is_string($dados)) {
                return self::recusa($dados);
            }
        }

        return ['ok' => true, 'plano' => ['tipo' => 'acao', 'acao' => $acao, 'entidade' => $entidade, 'dados' => $dados, 'ref' => $ref]];
    }

    /** @return array<string,int|string>|string refs normalizadas ou mensagem de erro */
    private static function refs(mixed $bruto, string $rotulo): array|string
    {
        if ($bruto === null || $bruto === []) {
            return [];
        }
        if (!is_array($bruto) || array_is_list($bruto)) {
            return "{$rotulo} inválido";
        }
        $refs = [];
        foreach ($bruto as $chave => $valor) {
            if (!in_array($chave, self::REFS, true)) {
                return "{$rotulo} fora da whitelist: {$chave}";
            }
            if ($valor === null || $valor === '') {
                continue;
            }
            if (is_int($valor) && $valor > 0) {
                $refs[$chave] = $valor;
            } elseif (is_string($valor) && mb_strlen(trim($valor)) <= 160) {
                $refs[$chave] = trim($valor);
            } else {
                return "{$rotulo}.{$chave} inválido";
            }
        }
        return $refs;
    }

    /** @return array<string,mixed>|string dados normalizados ou mensagem de erro */
    private static function dados(string $acao, string $entidade, mixed $bruto): array|string
    {
        // {} chega do JSON como [] (lista vazia): equivale a "sem dados".
        if ($bruto === null || $bruto === []) {
            $bruto = [];
        } elseif (!is_array($bruto) || array_is_list($bruto)) {
            return 'dados inválidos';
        }

        // Ids nunca vêm da IA: relações entram por "ref" (ou por nome: origem, etapa, motivo_perda).
        $campos = Schema::gravaveis($entidade);
        $permitidos = array_filter($campos, static fn (array $c): bool => $c['t'] !== 'fk');
        if ($acao === 'nota') {
            unset($permitidos['tipo']);
            if (array_key_exists('texto', $bruto)) {
                $bruto['descricao'] ??= $bruto['texto'];
                unset($bruto['texto']);
            }
        }
        if ($acao === 'mover_etapa') {
            $permitidos = ['valor_fechado' => $campos['valor_fechado'], 'detalhe_perda' => $campos['detalhe_perda']];
        }
        $porNome = match (true) {
            $acao === 'mover_etapa' => ['etapa', 'motivo_perda'],
            isset($campos['origem_id']) => ['origem'],
            default => [],
        };

        $saida = [];
        foreach ($bruto as $chave => $valor) {
            if (!is_string($chave)) {
                return 'dados inválidos';
            }
            if (in_array($chave, $porNome, true)) {
                if (!is_string($valor) || trim($valor) === '' || mb_strlen($valor) > 120) {
                    return "dados.{$chave} inválido";
                }
                $saida[$chave] = trim($valor);
                continue;
            }
            if (!isset($permitidos[$chave])) {
                return "campo fora da whitelist: {$entidade}.{$chave}";
            }
            if ($valor !== null && !is_scalar($valor)) {
                return "valor não escalar em dados.{$chave}";
            }
            if (is_string($valor) && mb_strlen($valor) > 5000) {
                return "valor grande demais em dados.{$chave}";
            }
            if ($permitidos[$chave]['t'] === 'enum' && $valor !== null && $valor !== '') {
                $chaveEnum = Schema::chaveDoEnum($permitidos[$chave]['op'], (string) $valor);
                if ($chaveEnum === null) {
                    return "valor inválido em dados.{$chave}";
                }
                $valor = $chaveEnum;
            }
            $saida[$chave] = $valor;
        }

        if ($acao === 'mover_etapa' && !isset($saida['etapa'])) {
            return 'mover_etapa exige dados.etapa';
        }
        return $saida;
    }

    private static function recusa(string $motivo): array
    {
        return ['ok' => false, 'motivo' => $motivo];
    }
}
