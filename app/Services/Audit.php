<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditoriaRepository;

/** Registro em log_auditoria: quem, o quê, antes e depois (JSON). */
final class Audit
{
    /**
     * @param array<string,mixed>|null $antes  valores anteriores (só campos alterados; null em criação)
     * @param array<string,mixed>|null $depois valores novos
     * @return int id do registro de auditoria
     */
    public static function registrar(
        string $origem,
        string $entidade,
        ?int $registroId,
        string $acao,
        ?array $antes,
        ?array $depois,
        ?int $execucaoId = null,
    ): int {
        $json = static fn (?array $d): ?string => $d === null
            ? null
            : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return (new AuditoriaRepository())->inserir([
            'data'        => agora(),
            'origem'      => $origem,
            'entidade'    => $entidade,
            'registro_id' => $registroId,
            'acao'        => $acao,
            'antes'       => $json($antes),
            'depois'      => $json($depois),
            'execucao_id' => $execucaoId,
        ]);
    }
}
