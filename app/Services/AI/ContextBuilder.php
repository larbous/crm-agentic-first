<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;
use App\Repositories\Repositorios;

/**
 * Contexto enviado ao roteador (SPEC §3.3): registro aberto na tela, última entidade referenciada e a data de hoje.
 * Nunca há histórico de conversa. Nome e id vêm do banco, não do navegador.
 */
final class ContextBuilder
{
    private const ENTIDADES_DE_TELA = ['empresas', 'contatos', 'negocios'];

    /**
     * Registro aberto na tela a partir do caminho da URL (ex.: "/empresas/12").
     * @return array{entidade:string,id:int,nome:string}|null
     */
    public static function tela(?string $caminho): ?array
    {
        if ($caminho === null) {
            return null;
        }
        $base = rtrim((string) Config::obter('app.base_url', ''), '/');
        if ($base !== '' && str_starts_with($caminho, $base)) {
            $caminho = substr($caminho, strlen($base));
        }
        if (preg_match('#^/(empresas|contatos|negocios)/(\d+)(?:/.*)?$#', $caminho, $m) !== 1) {
            return null;
        }
        return self::ref($m[1], (int) $m[2]);
    }

    /** @return array{entidade:string,id:int,nome:string}|null */
    public static function ref(string $entidade, int $id): ?array
    {
        if (!in_array($entidade, self::ENTIDADES_DE_TELA, true)) {
            return null;
        }
        $registro = Repositorios::para($entidade)->encontrar($id);
        if ($registro === null) {
            return null;
        }
        $nome = (string) ($registro['nome_fantasia'] ?? $registro['titulo'] ?? trim($registro['nome'] . ' ' . ($registro['sobrenome'] ?? '')));
        return ['entidade' => $entidade, 'id' => $id, 'nome' => $nome];
    }

    /** Mensagem de usuário enviada ao roteador: JSON com tela, ultima_ref, hoje e mensagem. */
    public function montar(string $mensagem, ?array $tela, ?array $ultimaRef, ?string $hoje = null): string
    {
        return json_encode([
            'tela'       => $tela,
            'ultima_ref' => $ultimaRef,
            'hoje'       => $hoje ?? hoje(),
            'mensagem'   => $mensagem,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
