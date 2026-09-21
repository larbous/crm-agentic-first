<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Barramento de eventos (SPEC §5). `Events::disparar(nome, payload)`; gatilhos de agentes/squads
 * (Fases 5–6) assinam com `Events::ouvir`. O ActionExecutor só dispara depois do commit.
 */
final class Events
{
    public const CONHECIDOS = [
        'empresa.criada', 'empresa.atualizada', 'empresa.convertida', 'contato.criado', 'negocio.criado',
        'negocio.etapa_mudou', 'negocio.ganho', 'negocio.perdido', 'atividade.criada', 'tarefa.vencida',
        'proposta.enviada', 'proposta.visualizada', 'proposta.aceita', 'proposta.recusada',
        'contrato.assinado', 'contrato.vencendo', 'contrato.vencido', 'formulario.submetido',
        'pesquisa.criada', 'pesquisa.respondida', 'mensagem.recebida',
    ];

    /** @var array<string,list<callable>> */
    private static array $ouvintes = [];

    public static function ouvir(string $evento, callable $ouvinte): void
    {
        self::$ouvintes[$evento][] = $ouvinte;
    }

    /**
     * Dispara o evento. Payload: entidade, id, origem, dados do registro. Falha em um ouvinte
     * não pode derrubar a operação que já foi gravada: é registrada no log de erros.
     */
    public static function disparar(string $evento, array $payload = []): void
    {
        foreach (self::$ouvintes[$evento] ?? [] as $ouvinte) {
            try {
                $ouvinte($payload + ['evento' => $evento]);
            } catch (\Throwable $e) {
                error_log("Erro no ouvinte de {$evento}: " . $e->getMessage());
            }
        }
    }

    /** Remove todos os ouvintes (testes). */
    public static function limpar(): void
    {
        self::$ouvintes = [];
    }
}
