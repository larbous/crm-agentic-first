<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;

/**
 * Motor de variáveis {entidade.campo} dos modelos de documento (SPEC §4.9).
 * Valores monetários, datas e opções saem formatados em pt-BR. Variáveis desconhecidas ficam como estão
 * no texto e são devolvidas em $naoResolvidas, para o operador ver o erro na pré-visualização.
 */
final class Variaveis
{
    /** Dados da agência lidos de configuracoes (chaves empresa.*). */
    public const CAMPOS_AGENCIA = [
        'nome' => 'Nome da agência', 'cnpj' => 'CNPJ da agência', 'endereco' => 'Endereço da agência', 'cidade' => 'Cidade (foro)',
        'email' => 'E-mail da agência', 'telefone' => 'Telefone da agência', 'site' => 'Site da agência', 'responsavel' => 'Responsável / representante legal',
    ];

    private const ENTIDADES = ['empresa' => 'empresas', 'contato' => 'contatos', 'negocio' => 'negocios', 'proposta' => 'propostas', 'contrato' => 'contratos'];

    /** Catálogo para a tela de modelos: grupo => [token => descrição]. */
    public static function catalogo(): array
    {
        $grupos = ['Data' => [
            '{hoje}' => 'Data de hoje (20/09/2026)', '{hoje.extenso}' => 'Data de hoje por extenso', '{hoje.iso}' => 'Data de hoje (AAAA-MM-DD)',
        ]];
        foreach (self::ENTIDADES as $ns => $entidade) {
            $schema = Schema::entidade($entidade);
            $itens = [];
            foreach ($schema['campos'] as $campo => $def) {
                if (in_array($campo, ['token_publico', 'aceite_ip', 'assinatura_ip', 'campos_extras'], true)) {
                    continue;
                }
                $itens['{' . $ns . '.' . $campo . '}'] = $def['r'];
            }
            foreach (self::calculadas($ns) as $campo => $descricao) {
                $itens['{' . $ns . '.' . $campo . '}'] = $descricao;
            }
            foreach (CamposExtras::definicoes($entidade) as $extra) {
                $itens['{' . $ns . '.extra.' . $extra['chave'] . '}'] = $extra['rotulo'] . ' (campo extra)';
            }
            $grupos[ucfirst($schema['singular'])] = $itens;
        }
        $agencia = [];
        foreach (self::CAMPOS_AGENCIA as $campo => $descricao) {
            $agencia['{larbous.' . $campo . '}'] = $descricao;
        }
        $grupos['Agência (Lárbous)'] = $agencia;
        return $grupos;
    }

    /** Variáveis calculadas por namespace: campo => descrição. */
    private static function calculadas(string $ns): array
    {
        return match ($ns) {
            'empresa'  => ['nome' => 'Nome fantasia', 'razao' => 'Razão social (ou nome fantasia)', 'endereco_completo' => 'Endereço completo'],
            'contato'  => ['nome_completo' => 'Nome e sobrenome', 'primeiro_nome' => 'Primeiro nome'],
            'negocio'  => ['valor_extenso' => 'Valor (fechado ou estimado) por extenso'],
            'proposta' => ['itens' => 'Tabela de itens', 'desconto' => 'Desconto formatado', 'total_extenso' => 'Total por extenso', 'numero_versao' => 'Número e versão (PROP-2026-0001 v2)'],
            'contrato' => ['valor_total_extenso' => 'Valor total por extenso', 'valor_mensal_extenso' => 'Valor mensal por extenso', 'vigencia' => 'Vigência (de … a …)'],
            default    => [],
        };
    }

    /**
     * Substitui as variáveis. $contexto: ['empresa' => linha, 'contato' => linha, 'negocio' => …, 'proposta' => linha (com 'itens'), 'contrato' => …].
     * Em modo HTML os valores são escapados; em modo texto saem crus.
     * @param list<string> $naoResolvidas variáveis desconhecidas encontradas
     */
    public static function renderizar(string $modelo, array $contexto, bool $html, array &$naoResolvidas = []): string
    {
        return preg_replace_callback('/\{([a-z_]+(?:\.[a-z_]+(?:\.[a-z][a-z0-9_]*)?)?)\}/', function (array $m) use ($contexto, $html, &$naoResolvidas): string {
            $valor = self::resolver($m[1], $contexto, $html);
            if ($valor === null) {
                $naoResolvidas[] = $m[0];
                return $m[0];
            }
            return $valor;
        }, $modelo) ?? $modelo;
    }

    /** @return string|null texto pronto para o modo pedido; null se a variável não existe */
    private static function resolver(string $chave, array $ctx, bool $html): ?string
    {
        [$ns, $campo] = array_pad(explode('.', $chave, 2), 2, null);
        $esc = static fn (string $t): string => $html ? e($t) : $t;

        if ($ns === 'hoje') {
            return match ($campo) {
                null     => data_br(hoje()),
                'extenso' => data_por_extenso(hoje()),
                'iso'    => hoje(),
                default  => null,
            };
        }

        if ($ns === 'larbous') {
            return $campo !== null && isset(self::CAMPOS_AGENCIA[$campo])
                ? $esc((string) (new ConfiguracaoRepository())->obter('empresa.' . $campo, ''))
                : null;
        }

        if (!isset(self::ENTIDADES[$ns]) || $campo === null) {
            return null;
        }
        $entidade = self::ENTIDADES[$ns];
        $linha = $ctx[$ns] ?? null;

        if (str_starts_with($campo, 'extra.')) {
            $chaveExtra = substr($campo, 6);
            $def = CamposExtras::porChave($entidade)[$chaveExtra] ?? null;
            if ($def === null) {
                return null;
            }
            return $esc(CamposExtras::texto($def, CamposExtras::valores($linha['campos_extras'] ?? null)[$chaveExtra] ?? null));
        }
        if (isset(self::calculadas($ns)[$campo])) {
            return $linha === null ? '' : self::calculada($ns, $campo, $linha, $html);
        }
        if (!isset(Schema::entidade($entidade)['campos'][$campo])) {
            return null;
        }
        if ($linha === null || ($linha[$campo] ?? null) === null || $linha[$campo] === '') {
            return '';
        }
        return $esc(self::formatar($entidade, $campo, $linha[$campo]));
    }

    private static function formatar(string $entidade, string $campo, mixed $valor): string
    {
        $def = Schema::entidade($entidade)['campos'][$campo];
        if (!empty($def['pct'])) {
            return percentual_br((int) $valor);
        }
        if ($def['t'] === 'fk') {
            return self::nomeDoFk($def['fk'], (int) $valor);
        }
        if ($def['t'] === 'html') {
            return (string) $valor;
        }
        return formatar_valor_campo($entidade, $campo, $valor);
    }

    private static function nomeDoFk(string $tabela, int $id): string
    {
        $r = Repositorios::para($tabela)->encontrar($id, true);
        return (string) ($r['nome_fantasia'] ?? $r['nome_completo'] ?? $r['nome'] ?? $r['titulo'] ?? $r['numero'] ?? '');
    }

    private static function calculada(string $ns, string $campo, array $l, bool $html): string
    {
        $esc = static fn (string $t): string => $html ? e($t) : $t;
        switch ($ns . '.' . $campo) {
            case 'empresa.nome':
                return $esc((string) ($l['nome_fantasia'] ?? ''));
            case 'empresa.razao':
                return $esc((string) (($l['razao_social'] ?? '') !== '' ? $l['razao_social'] : ($l['nome_fantasia'] ?? '')));
            case 'empresa.endereco_completo':
                $rua = trim(implode(', ', array_filter([$l['logradouro'] ?? '', $l['numero'] ?? '', $l['complemento'] ?? '', $l['bairro'] ?? ''])));
                $cid = trim(implode('/', array_filter([$l['cidade'] ?? '', $l['uf'] ?? ''])));
                $cep = ($l['cep'] ?? '') !== '' ? 'CEP ' . cep_formatado($l['cep']) : '';
                return $esc(implode(', ', array_filter([$rua, $cid, $cep])));
            case 'contato.nome_completo':
                return $esc(trim(($l['nome'] ?? '') . ' ' . ($l['sobrenome'] ?? '')));
            case 'contato.primeiro_nome':
                return $esc((string) ($l['nome'] ?? ''));
            case 'negocio.valor_extenso':
                return $esc(valor_por_extenso(isset($l['valor_fechado']) ? (int) $l['valor_fechado'] : (isset($l['valor_estimado']) ? (int) $l['valor_estimado'] : null)));
            case 'proposta.itens':
                return self::tabelaItens($l['itens'] ?? [], $html);
            case 'proposta.desconto':
                if ((int) ($l['desconto_valor'] ?? 0) === 0) {
                    return '';
                }
                return $esc(($l['desconto_tipo'] ?? 'valor') === 'percentual' ? percentual_br((int) $l['desconto_valor']) : moeda((int) $l['desconto_valor']));
            case 'proposta.total_extenso':
                return $esc(valor_por_extenso(isset($l['total']) ? (int) $l['total'] : null));
            case 'proposta.numero_versao':
                return $esc(($l['numero'] ?? '') . ' v' . ($l['versao'] ?? 1));
            case 'contrato.valor_total_extenso':
                return $esc(valor_por_extenso(isset($l['valor_total']) ? (int) $l['valor_total'] : null));
            case 'contrato.valor_mensal_extenso':
                return $esc(valor_por_extenso(isset($l['valor_mensal']) ? (int) $l['valor_mensal'] : null));
            case 'contrato.vigencia':
                $de = data_br($l['data_inicio'] ?? null);
                $ate = data_br($l['data_fim'] ?? null);
                return $esc($de !== '' && $ate !== '' ? "de {$de} a {$ate}" : trim($de . ' ' . $ate));
        }
        return '';
    }

    /** Tabela de itens da proposta (HTML no modo HTML; lista de linhas no modo texto). */
    public static function tabelaItens(array $itens, bool $html): string
    {
        if ($itens === []) {
            return '';
        }
        if (!$html) {
            return implode("\n", array_map(static fn (array $i): string => '- ' . $i['descricao'] . ' — '
                . rtrim(rtrim(number_format((float) $i['quantidade'], 3, ',', '.'), '0'), ',') . ' × ' . moeda((int) $i['valor_unitario'])
                . ' = ' . moeda((int) $i['total']) . ((int) $i['recorrente'] === 1 ? ' (recorrente)' : ''), $itens));
        }
        $linhas = '';
        foreach ($itens as $i) {
            $qtd = rtrim(rtrim(number_format((float) $i['quantidade'], 3, ',', '.'), '0'), ',');
            $linhas .= '<tr><td>' . e($i['descricao']) . ((int) $i['recorrente'] === 1 ? ' <em>(recorrente)</em>' : '') . '</td>'
                . '<td align="right">' . e($qtd . ($i['unidade'] ? ' ' . $i['unidade'] : '')) . '</td>'
                . '<td align="right">' . e(moeda((int) $i['valor_unitario'])) . '</td>'
                . '<td align="right">' . e(moeda((int) $i['total'])) . '</td></tr>';
        }
        return '<table><thead><tr><th>Descrição</th><th align="right">Qtd.</th><th align="right">Unitário</th><th align="right">Total</th></tr></thead><tbody>'
            . $linhas . '</tbody></table>';
    }

    /**
     * Monta o contexto de variáveis a partir de ids (os que faltarem são deduzidos: negócio → empresa/contato).
     * @param array{negocio_id?:?int,empresa_id?:?int,contato_id?:?int,proposta_id?:?int,contrato_id?:?int} $ids
     */
    public static function contexto(array $ids): array
    {
        $ctx = [];
        if (!empty($ids['contrato_id'])) {
            $ctx['contrato'] = Repositorios::para('contratos')->encontrar((int) $ids['contrato_id'], true);
            $ids += ['proposta_id' => $ctx['contrato']['proposta_id'] ?? null, 'negocio_id' => $ctx['contrato']['negocio_id'] ?? null,
                'empresa_id' => $ctx['contrato']['empresa_id'] ?? null, 'contato_id' => $ctx['contrato']['contato_id'] ?? null];
        }
        if (!empty($ids['proposta_id'])) {
            $p = Repositorios::para('propostas')->encontrar((int) $ids['proposta_id'], true);
            if ($p !== null) {
                $p['itens'] = (new ItemPropostaRepository())->porProposta((int) $p['id']);
                $ctx['proposta'] = $p;
                $ids += ['negocio_id' => $p['negocio_id'], 'empresa_id' => $p['empresa_id'], 'contato_id' => $p['contato_id']];
            }
        }
        if (!empty($ids['negocio_id'])) {
            $n = Repositorios::negocios()->encontrar((int) $ids['negocio_id'], true);
            if ($n !== null) {
                $ctx['negocio'] = $n;
                $ids += ['empresa_id' => $n['empresa_id'], 'contato_id' => $n['contato_principal_id']];
                $ids['empresa_id'] ??= $n['empresa_id'];
                $ids['contato_id'] ??= $n['contato_principal_id'];
            }
        }
        if (!empty($ids['empresa_id'])) {
            $ctx['empresa'] = Repositorios::empresas()->encontrar((int) $ids['empresa_id'], true);
        }
        if (!empty($ids['contato_id'])) {
            $ctx['contato'] = Repositorios::contatos()->encontrar((int) $ids['contato_id'], true);
        }
        return array_filter($ctx, static fn ($v) => $v !== null);
    }

    /** Registro de exemplo para pré-visualizar modelos quando não há dados reais. */
    public static function exemplo(): array
    {
        $itens = [
            ['descricao' => 'Site institucional (até 8 páginas)', 'quantidade' => 1.0, 'unidade' => 'projeto', 'valor_unitario' => 650000, 'desconto' => 0, 'total' => 650000, 'recorrente' => 0],
            ['descricao' => 'Manutenção e hospedagem', 'quantidade' => 12.0, 'unidade' => 'mês', 'valor_unitario' => 12000, 'desconto' => 0, 'total' => 144000, 'recorrente' => 1],
        ];
        return [
            'empresa'  => ['nome_fantasia' => 'Padaria Exemplo', 'razao_social' => 'Padaria Exemplo Ltda', 'cnpj' => '11222333000181', 'logradouro' => 'Rua das Flores', 'numero' => '123',
                'bairro' => 'Centro', 'cidade' => 'São Paulo', 'uf' => 'SP', 'cep' => '01001000', 'email_geral' => 'contato@padariaexemplo.com.br', 'telefone' => '(11) 3333-4444', 'status' => 'prospect'],
            'contato'  => ['nome' => 'Ana', 'sobrenome' => 'Souza', 'email' => 'ana@padariaexemplo.com.br', 'whatsapp' => '(11) 99999-0000', 'cargo' => 'Sócia'],
            'negocio'  => ['titulo' => 'Site Padaria Exemplo', 'codigo' => 'NEG-2026-0001', 'valor_estimado' => 794000, 'valor_fechado' => 794000, 'status' => 'ganho'],
            'proposta' => ['numero' => 'PROP-2026-0001', 'versao' => 1, 'titulo' => 'Proposta — Site Padaria Exemplo', 'data_emissao' => hoje(), 'validade' => date('Y-m-d', strtotime('+15 days')),
                'subtotal' => 794000, 'desconto_tipo' => 'valor', 'desconto_valor' => 0, 'total' => 794000, 'total_recorrente' => 144000, 'forma_pagamento' => 'PIX', 'itens' => $itens],
            'contrato' => ['numero' => 'CT-2026-0001', 'titulo' => 'Contrato — Site Padaria Exemplo', 'valor_total' => 794000, 'valor_mensal' => 12000, 'recorrencia' => 'mensal',
                'data_inicio' => hoje(), 'data_fim' => date('Y-m-d', strtotime('+1 year')), 'dia_vencimento_pagamento' => 10, 'status' => 'rascunho'],
        ];
    }
}
