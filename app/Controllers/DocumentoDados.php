<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;
use App\Services\Variaveis;

/** Monta os dados que as views de documento (impressão e link público) precisam. */
final class DocumentoDados
{
    /** Dados da agência (configuracoes empresa.*). */
    public static function agencia(): array
    {
        $cfg = new ConfiguracaoRepository();
        $out = [];
        foreach (array_keys(Variaveis::CAMPOS_AGENCIA) as $campo) {
            $out[$campo] = (string) $cfg->obter('empresa.' . $campo, '');
        }
        return $out;
    }

    public static function proposta(array $proposta): array
    {
        return [
            'p'       => $proposta,
            'itens'   => (new ItemPropostaRepository())->porProposta((int) $proposta['id']),
            'empresa' => $proposta['empresa_id'] ? Repositorios::empresas()->encontrar((int) $proposta['empresa_id'], true) : null,
            'contato' => $proposta['contato_id'] ? Repositorios::contatos()->encontrar((int) $proposta['contato_id'], true) : null,
            'agencia' => self::agencia(),
        ];
    }

    public static function contrato(array $contrato): array
    {
        return [
            'c'       => $contrato,
            'empresa' => $contrato['empresa_id'] ? Repositorios::empresas()->encontrar((int) $contrato['empresa_id'], true) : null,
            'contato' => $contrato['contato_id'] ? Repositorios::contatos()->encontrar((int) $contrato['contato_id'], true) : null,
            'agencia' => self::agencia(),
        ];
    }
}
