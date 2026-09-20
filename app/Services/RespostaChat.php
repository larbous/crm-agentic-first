<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;

/**
 * Respostas do chat por template do servidor (a IA nunca redige a resposta ao operador).
 * Toda resposta é ['conteudo' => texto puro, 'payload' => array, 'ultima_ref' => ?array].
 * O HTML é gerado na renderização (components/chat.php), sempre escapado.
 */
final class RespostaChat
{
    public static function texto(string $conteudo, ?array $ultimaRef = null): array
    {
        return ['conteudo' => $conteudo, 'payload' => ['tipo' => 'texto'], 'ultima_ref' => $ultimaRef];
    }

    /** Desfazer concluído: texto simples, mas dados mudaram (a tela aberta precisa atualizar). */
    public static function desfeito(string $mensagem): array
    {
        return ['conteudo' => '✓ ' . $mensagem, 'payload' => ['tipo' => 'texto', 'alterou' => true], 'ultima_ref' => null];
    }

    public static function erro(string $conteudo): array
    {
        return ['conteudo' => '✗ ' . $conteudo, 'payload' => ['tipo' => 'erro'], 'ultima_ref' => null];
    }

    /** Roteador não entendeu (JSON inválido ou fora da whitelist). */
    public static function naoEntendi(): array
    {
        return self::texto('Não entendi. Tente reformular ou use /ajuda.');
    }

    /**
     * Sucesso de uma ação de escrita: "✓ Negócio "Site" criado (R$ 8.000,00) → abrir" + botão Desfazer.
     * @param array $ctx etapa (mover_etapa), pai (registro em que a nota/tarefa foi anotada: ['entidade','id','nome'])
     */
    public static function acao(string $acao, string $entidade, Resultado $r, array $ctx = []): array
    {
        $schema = Schema::entidade($entidade);
        $registro = $r->registro ?? [];
        $nome = self::nome($registro);
        $f = ($schema['genero'] ?? 'm') === 'f';
        $singular = $schema['singular'] ?? $entidade;
        $pai = $ctx['pai'] ?? null;

        $texto = match ($acao) {
            'criar' => match ($entidade) {
                'negocios' => "✓ {$singular} \"{$nome}\" criado" . (isset($registro['valor_estimado']) ? ' (' . moeda((int) $registro['valor_estimado']) . ')' : ''),
                'tarefas'  => "✓ Tarefa \"{$nome}\" criada" . (self::vencimento($registro) !== '' ? ' (vence ' . self::vencimento($registro) . ')' : ''),
                'atividades' => '✓ ' . self::tipoAtividade($registro) . ' registrada' . ($pai ? " em \"{$pai['nome']}\"" : ''),
                default    => "✓ {$singular} \"{$nome}\" " . ($f ? 'criada' : 'criado'),
            },
            'nota' => '✓ Nota registrada' . ($pai ? " em \"{$pai['nome']}\"" : ''),
            'tarefa' => "✓ Tarefa \"{$nome}\" criada" . (self::vencimento($registro) !== '' ? ' (vence ' . self::vencimento($registro) . ')' : ''),
            'atualizar' => "✓ {$singular} \"{$nome}\" " . ($f ? 'atualizada' : 'atualizado') . self::camposAlterados($r),
            'arquivar' => "✓ {$singular} \"{$nome}\" " . ($f ? 'arquivada' : 'arquivado'),
            'concluir' => "✓ Tarefa \"{$nome}\" concluída",
            'mover_etapa' => "✓ Negócio \"{$nome}\" movido para \"" . ($ctx['etapa'] ?? '?') . '"'
                . (($registro['status'] ?? '') === 'ganho' ? ' (ganho: ' . moeda((int) ($registro['valor_fechado'] ?? 0)) . ')' : ''),
            'converter_cliente' => "✓ Empresa \"{$nome}\" convertida em cliente",
            default => '✓ ' . $r->mensagem,
        };

        return [
            'conteudo'   => $texto,
            'payload'    => [
                'tipo' => 'acao', 'ok' => true, 'link' => self::link($entidade, $registro, $pai, $acao),
                'log_id' => $r->logId, 'desfeito' => false, 'alterou' => true,
            ],
            'ultima_ref' => null,
        ];
    }

    /**
     * Resultado de um agente: resumo (saída do agente), contagem de ações aplicadas/pendentes/recusadas e links.
     * @param array $r retorno de AgentRunner::executar()
     * @param array{entidade:string,id:int,nome:string}|null $ref registro-alvo (empresas, contatos, negocios)
     */
    public static function agente(string $nome, array $r, ?string $entidade, ?int $registroId, ?array $ref): array
    {
        if (!$r['ok']) {
            return self::erro("O agente {$nome} não concluiu: " . $r['erro']);
        }
        $alvo = $ref['nome'] ?? null;
        $linhas = ['✓ ' . $nome . ' executado' . ($alvo !== null ? " em \"{$alvo}\"" : '')];
        if ($r['resumo'] !== '') {
            $linhas[] = $r['resumo'];
        }
        $aplicadas = count($r['aplicadas']);
        $pendentes = count($r['pendentes']);
        if ($aplicadas > 0) {
            $linhas[] = "• {$aplicadas} alteração(ões) aplicada(s)";
        }
        if ($pendentes > 0) {
            $linhas[] = "• {$pendentes} aguardando sua aprovação";
        }
        if ($r['recusadas'] !== []) {
            $linhas[] = '• ' . count($r['recusadas']) . ' ação(ões) recusada(s) pela validação (detalhes em Execuções)';
        }
        if ($aplicadas === 0 && $pendentes === 0 && $r['recusadas'] === []) {
            $linhas[] = '• nenhuma alteração proposta';
        }
        if ($r['texto'] !== '' && $entidade === null) {
            $linhas[] = 'O relatório completo está em Execuções.';
        }
        return [
            'conteudo'   => implode("\n", $linhas),
            'payload'    => [
                'tipo' => 'agente', 'ok' => true, 'execucao_id' => $r['execucao_id'], 'pendentes' => $pendentes,
                'link' => in_array($entidade, ['empresas', 'contatos', 'negocios', 'propostas', 'contratos'], true) && $registroId !== null ? "/{$entidade}/{$registroId}" : null,
                'alterou' => $aplicadas > 0 || $pendentes > 0,
            ],
            'ultima_ref' => $ref,
        ];
    }

    /** Squad enfileirado pelo chat: o worker executa; o operador acompanha na tela do squad. */
    public static function squad(string $nome, int $execucaoId, ?array $ref): array
    {
        $alvo = $ref['nome'] ?? null;
        return [
            'conteudo'   => "✓ Squad {$nome} na fila" . ($alvo !== null ? " para \"{$alvo}\"" : '') . ".\nO worker o executa em até 1 minuto; acompanhe o andamento pelo link.",
            'payload'    => ['tipo' => 'squad', 'ok' => true, 'execucao_id' => $execucaoId, 'link' => '/squads/execucoes/' . $execucaoId],
            'ultima_ref' => $ref,
        ];
    }

    /** Tabela de resultado de "consultar". */
    public static function consulta(array $payload): array
    {
        $n = count($payload['linhas']);
        $total = $payload['total'];
        $conteudo = $total === 0
            ? "Nenhum resultado em {$payload['rotulo']}."
            : ($total > $n ? "{$n} de {$total} resultados em {$payload['rotulo']}" : "{$total} resultado(s) em {$payload['rotulo']}");
        return ['conteudo' => $conteudo, 'payload' => $payload, 'ultima_ref' => null];
    }

    public static function link(string $entidade, array $registro, ?array $pai, string $acao): ?string
    {
        if ($acao === 'arquivar') {
            return null;
        }
        $id = (int) ($registro['id'] ?? 0);
        return match ($entidade) {
            'empresas', 'contatos', 'negocios' => $id > 0 ? "/{$entidade}/{$id}" : null,
            'tarefas' => '/tarefas',
            'atividades' => $pai !== null ? "/{$pai['entidade']}/{$pai['id']}" : null,
            default => null,
        };
    }

    public static function nome(array $registro): string
    {
        return (string) ($registro['nome_fantasia'] ?? $registro['titulo'] ?? trim(($registro['nome'] ?? '') . ' ' . ($registro['sobrenome'] ?? '')) ?: ('#' . ($registro['id'] ?? '')));
    }

    private static function vencimento(array $registro): string
    {
        $v = (string) ($registro['vencimento'] ?? '');
        if ($v === '') {
            return '';
        }
        return strlen($v) > 10 && !str_ends_with($v, '00:00:00') ? datahora_br($v) : data_br($v);
    }

    private static function tipoAtividade(array $registro): string
    {
        return Schema::opcoes('tipo_atividade')[$registro['tipo'] ?? ''] ?? 'Atividade';
    }

    /** ": nome, status" com os rótulos dos campos que mudaram (lidos do log de auditoria). */
    private static function camposAlterados(Resultado $r): string
    {
        if ($r->logId === null) {
            return '';
        }
        $log = (new AuditoriaRepository())->encontrar($r->logId);
        $depois = $log && $log['depois'] !== null ? (array) json_decode((string) $log['depois'], true) : [];
        unset($depois['_itens']);
        $schema = Schema::entidade((string) ($log['entidade'] ?? ''));
        $rotulos = [];
        foreach (array_keys($depois) as $campo) {
            $rotulos[] = mb_strtolower($schema['campos'][$campo]['r'] ?? $campo);
        }
        return $rotulos === [] ? '' : ': ' . implode(', ', array_slice($rotulos, 0, 5));
    }
}
