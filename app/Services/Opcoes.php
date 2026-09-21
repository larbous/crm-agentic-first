<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Repositorios;

/** Opções (id => rótulo) para selects de chaves estrangeiras, com cache por requisição. */
final class Opcoes
{
    /** @var array<string,array<int|string,string>> */
    private static array $cache = [];

    public static function para(string $tabela): array
    {
        return self::$cache[$tabela] ??= match ($tabela) {
            'empresas'      => Repositorios::empresas()->opcoes(),
            'contatos'      => Repositorios::contatos()->opcoes(),
            'negocios'      => Repositorios::negocios()->opcoes(),
            'origens'       => Repositorios::para('origens')->opcoes(),
            'motivos_perda' => Repositorios::para('motivos_perda')->opcoes(),
            'pipelines'     => Repositorios::pipelines()->opcoes(),
            'etapas'        => Repositorios::etapas()->opcoes(),
            'tags'          => Repositorios::tags()->opcoes(),
            'servicos'      => Repositorios::servicos()->opcoes(),
            'contrato_tipos' => Repositorios::para('contrato_tipos')->opcoes(),
            'areas'         => Repositorios::para('areas')->opcoes(),
            'modelos_documento' => array_column(Repositorios::modelos()->todas(), 'nome', 'id'),
            'propostas'     => Repositorios::propostas()->opcoesAceitas(),
            'contratos'     => array_column(Repositorios::contratos()->todas(), 'numero', 'id'),
            default         => [],
        };
    }

    /** Descarta o cache (após escritas dentro da mesma requisição e em testes). */
    public static function limpar(): void
    {
        self::$cache = [];
    }
}
