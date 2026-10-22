<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Tradução das tags de mesclagem do Perfex CRM ({client_company}, {contract_datestart}…) para as variáveis
 * deste CRM ({empresa.nome}, {contrato.data_inicio}…). Usada ao atualizar contratos e modelos importados.
 */
final class TagsPerfex
{
    /** tag do Perfex (sem chaves) => variável nova (sem chaves) */
    public const MAPA = [
        // Contratada (a agência)
        'companyname'  => 'larbous.nome',
        'main_domain'  => 'empresa.site', // no Perfex era o domínio da agência, mas o texto fala do site da contratante
        'crm_url'      => 'larbous.crm_url',
        // Contratante
        'client_company'  => 'empresa.nome',
        'company_nome_fantasia' => 'empresa.razao',
        'client_vat_number' => 'empresa.cnpj',
        'client_address'  => 'empresa.endereco_linha',
        'client_city'     => 'empresa.cidade',
        'client_state'    => 'empresa.uf',
        'client_country'  => 'empresa.pais',
        'client_zip'      => 'empresa.cep',
        'company_responsavel_contrato' => 'empresa.responsavel_nome',
        'company_responsavel_cpf'      => 'empresa.responsavel_cpf',
        'company_responsavel_rg'       => 'empresa.responsavel_rg',
        'contact_firstname' => 'contato.nome',
        'contact_lastname'  => 'contato.sobrenome',
        // Contrato
        'contract_subject'    => 'contrato.titulo',
        'contract_datestart'  => 'contrato.data_inicio',
        'contract_dateend'    => 'contrato.data_fim',
        'contract_contract_value' => 'contrato.valor_total',
        'contract_link'       => 'contrato.link',
        'contracts_valor_extenso' => 'contrato.valor_total_extenso',
        'contracts_dia_de_pagamento' => 'contrato.dia_vencimento_pagamento',
        'contracts_representante_legal'  => 'contrato.representante_nome',
        'contracts_cpf_do_representante' => 'contrato.representante_cpf',
        'contracts_rg_do_representante'  => 'contrato.representante_rg',
        'contracts_valor_promocional'    => 'contrato.valor_promocional',
        'contracts_percentual_desconto'  => 'contrato.percentual_desconto',
        'contracts_percentual_deconto'   => 'contrato.percentual_desconto', // grafia errada do campo no Perfex
        'contracts_periodo_desconto'     => 'contrato.periodo_desconto',
    ];

    /** Variáveis que já saem com "R$": o "R$" digitado antes da tag no texto antigo é removido. */
    private const MONETARIAS = ['contrato.valor_total', 'contrato.valor_promocional'];

    /** Troca as tags do Perfex pelas variáveis novas. Tags sem equivalente ficam como estão. */
    public static function converter(string $texto): string
    {
        foreach (self::MONETARIAS as $nova) {
            foreach (array_keys(self::MAPA, $nova, true) as $antiga) {
                $texto = preg_replace('/R\$(?:\s|&nbsp;|&#160;|\x{00A0})*\{' . preg_quote($antiga, '/') . '\}/u', '{' . $nova . '}', $texto) ?? $texto;
            }
        }
        return preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', static function (array $m): string {
            return isset(self::MAPA[$m[1]]) ? '{' . self::MAPA[$m[1]] . '}' : $m[0];
        }, $texto) ?? $texto;
    }

    /** Tags no formato do Perfex ({algo_assim}, sem ponto) que continuam no texto, sem tradução. */
    public static function restantes(string $texto): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $texto, $m);
        return array_values(array_unique($m[0]));
    }
}
