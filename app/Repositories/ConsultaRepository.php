<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;
use InvalidArgumentException;

/**
 * Consultas somente-leitura pedidas pelo chat (ação "consultar"). O SQL é montado aqui a partir de uma
 * whitelist de campos por entidade; valores sempre vão como parâmetros. A IA nunca vê nem escreve SQL.
 *
 * Campo: expr = expressão SQL, t = tipo (texto|int|money|data|enum), r = rótulo, op = opções do enum (Schema::opcoes).
 */
final class ConsultaRepository
{
    public const OPERADORES = ['=', '!=', '>', '>=', '<', '<=', 'contem', 'entre', 'vazio', 'nao_vazio'];

    /** Entidades consultáveis pelo chat. */
    public function entidades(): array
    {
        return array_keys($this->definicoes());
    }

    /** @return array{campos:array<string,array>,exibir:list<string>,ordem:string,link:string,rotulo:string}|null */
    public function definicao(string $entidade): ?array
    {
        $d = $this->definicoes()[$entidade] ?? null;
        return $d === null ? null : array_diff_key($d, ['from' => 1, 'onde' => 1]);
    }

    /**
     * @param list<array{0:string,1:string,2:mixed}> $filtros já validados: [campo, operador, valor]
     * @param array{0:string,1:string}|null $ordem [campo, asc|desc]
     * @return array{linhas:list<array>,total:int}
     */
    public function consultar(string $entidade, array $filtros, ?array $ordem, int $limite): array
    {
        $d = $this->definicoes()[$entidade] ?? throw new InvalidArgumentException("Entidade não consultável: {$entidade}");
        $campos = $d['campos'];

        $onde = [$d['onde']];
        $params = [];
        foreach (array_values($filtros) as $i => [$campo, $op, $valor]) {
            $def = $campos[$campo] ?? throw new InvalidArgumentException("Campo não consultável: {$campo}");
            $onde[] = $this->condicao($def, $op, $valor, $i, $params);
        }

        $selects = ['a.id AS id'];
        foreach ($d['exibir'] as $chave) {
            $selects[] = $campos[$chave]['expr'] . ' AS ' . $chave;
        }

        $orderBy = $d['ordem'];
        if ($ordem !== null) {
            $campo = $campos[$ordem[0]] ?? throw new InvalidArgumentException("Campo de ordem inválido: {$ordem[0]}");
            $expr = $campo['t'] === 'texto' ? $campo['expr'] . ' COLLATE pt_br' : $campo['expr'];
            $orderBy = $expr . ($ordem[1] === 'desc' ? ' DESC' : ' ASC') . ', a.id DESC';
        }

        $limite = max(1, min(50, $limite));
        $base = $d['from'] . ' WHERE ' . implode(' AND ', $onde);
        $st = DB::conexao()->prepare('SELECT COUNT(*) ' . $base);
        $st->execute($params);
        $total = (int) $st->fetchColumn();

        $st = DB::conexao()->prepare('SELECT ' . implode(', ', $selects) . ' ' . $base . " ORDER BY {$orderBy} LIMIT {$limite}");
        $st->execute($params);
        return ['linhas' => $st->fetchAll(), 'total' => $total];
    }

    /** @param array<string,mixed> $params */
    private function condicao(array $def, string $op, mixed $valor, int $i, array &$params): string
    {
        $expr = $def['expr'];
        if ($op === 'vazio') {
            return "({$expr} IS NULL OR {$expr} = '')";
        }
        if ($op === 'nao_vazio') {
            return "({$expr} IS NOT NULL AND {$expr} <> '')";
        }

        // Datas são comparadas só pela parte YYYY-MM-DD; textos, sem acento/caixa.
        $lado = match ($def['t']) {
            'data'  => "substr({$expr}, 1, 10)",
            'texto' => "busca_norm({$expr})",
            default => $expr,
        };

        if ($op === 'entre') {
            if (!is_array($valor) || count($valor) !== 2) {
                throw new InvalidArgumentException('O operador entre exige dois valores.');
            }
            $params["f{$i}a"] = $valor[0];
            $params["f{$i}b"] = $valor[1];
            return "{$lado} BETWEEN :f{$i}a AND :f{$i}b";
        }
        if (is_array($valor)) {
            throw new InvalidArgumentException('Valor inválido para o operador.');
        }
        if ($op === 'contem') {
            $params["f{$i}"] = '%' . addcslashes(normalizar_busca((string) $valor), '%_\\') . '%';
            return "{$lado} LIKE :f{$i} ESCAPE '\\'";
        }
        $sql = match ($op) {
            '='  => "{$lado} = :f{$i}",
            '!=' => "({$expr} IS NULL OR {$lado} <> :f{$i})",
            '>'  => "{$lado} > :f{$i}",
            '>=' => "{$lado} >= :f{$i}",
            '<'  => "{$lado} < :f{$i}",
            '<=' => "{$lado} <= :f{$i}",
            default => throw new InvalidArgumentException("Operador inválido: {$op}"),
        };
        $params["f{$i}"] = $def['t'] === 'texto' ? normalizar_busca((string) $valor) : $valor;
        return $sql;
    }

    private function definicoes(): array
    {
        $t = static fn (string $expr, string $r): array => ['expr' => $expr, 't' => 'texto', 'r' => $r];
        $n = static fn (string $expr, string $r): array => ['expr' => $expr, 't' => 'int', 'r' => $r];
        $m = static fn (string $expr, string $r): array => ['expr' => $expr, 't' => 'money', 'r' => $r];
        $d = static fn (string $expr, string $r): array => ['expr' => $expr, 't' => 'data', 'r' => $r];
        $e = static fn (string $expr, string $r, string $op): array => ['expr' => $expr, 't' => 'enum', 'r' => $r, 'op' => $op];
        $contato = "TRIM(c.nome || ' ' || COALESCE(c.sobrenome, ''))";

        return [
            'empresas' => [
                'rotulo' => 'Empresas', 'link' => '/empresas/', 'ordem' => 'a.nome_fantasia COLLATE pt_br ASC',
                'from' => 'FROM empresas a LEFT JOIN origens o ON o.id = a.origem_id',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['nome_fantasia', 'status', 'cidade', 'telefone', 'email_geral'],
                'campos' => [
                    'nome_fantasia' => $t('a.nome_fantasia', 'Empresa'),
                    'razao_social' => $t('a.razao_social', 'Razão social'),
                    'status' => $e('a.status', 'Status', 'status_empresa'),
                    'classificacao' => $e('a.classificacao', 'Classificação', 'classificacao'),
                    'segmento' => $t('a.segmento', 'Segmento'),
                    'cidade' => $t('a.cidade', 'Cidade'),
                    'uf' => $t('a.uf', 'UF'),
                    'origem' => $t('o.nome', 'Origem'),
                    'email_geral' => $t('a.email_geral', 'E-mail'),
                    'telefone' => $t('a.telefone', 'Telefone'),
                    'ticket_potencial' => $m('a.ticket_potencial', 'Ticket potencial'),
                    'cliente_desde' => $d('a.cliente_desde', 'Cliente desde'),
                    'criado_em' => $d('a.criado_em', 'Criada em'),
                    'ltv' => $m("(SELECT COALESCE(SUM(n.valor_fechado), 0) FROM negocios n WHERE n.empresa_id = a.id AND n.status = 'ganho' AND n.arquivado_em IS NULL)", 'LTV'),
                    'mrr' => $m("(SELECT COALESCE(SUM(k.valor_mensal), 0) FROM contratos k WHERE k.empresa_id = a.id AND k.status IN ('assinado', 'ativo') AND k.arquivado_em IS NULL)", 'MRR'),
                ],
            ],
            'contatos' => [
                'rotulo' => 'Contatos', 'link' => '/contatos/', 'ordem' => 'a.nome COLLATE pt_br ASC',
                'from' => 'FROM contatos a LEFT JOIN empresas e ON e.id = a.empresa_id',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['nome', 'empresa', 'cargo', 'email', 'telefone'],
                'campos' => [
                    'nome' => $t("TRIM(a.nome || ' ' || COALESCE(a.sobrenome, ''))", 'Contato'),
                    'empresa' => $t('e.nome_fantasia', 'Empresa'),
                    'cargo' => $t('a.cargo', 'Cargo'),
                    'email' => $t('a.email', 'E-mail'),
                    'telefone' => $t('a.telefone', 'Telefone'),
                    'whatsapp' => $t('a.whatsapp', 'WhatsApp'),
                    'status' => $e('a.status', 'Status', 'status_contato'),
                    'papel_decisao' => $e('a.papel_decisao', 'Papel na decisão', 'papel_decisao'),
                    'ultimo_contato_em' => $d('a.ultimo_contato_em', 'Último contato'),
                    'proximo_contato_em' => $d('a.proximo_contato_em', 'Próximo contato'),
                    'criado_em' => $d('a.criado_em', 'Criado em'),
                ],
            ],
            'negocios' => [
                'rotulo' => 'Negócios', 'link' => '/negocios/', 'ordem' => 'a.criado_em DESC, a.id DESC',
                'from' => 'FROM negocios a LEFT JOIN empresas e ON e.id = a.empresa_id LEFT JOIN etapas et ON et.id = a.etapa_id
                           LEFT JOIN contatos c ON c.id = a.contato_principal_id LEFT JOIN origens o ON o.id = a.origem_id',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['titulo', 'empresa', 'etapa', 'valor_estimado', 'previsao_fechamento'],
                'campos' => [
                    'titulo' => $t('a.titulo', 'Negócio'),
                    'codigo' => $t('a.codigo', 'Código'),
                    'empresa' => $t('e.nome_fantasia', 'Empresa'),
                    'contato' => $t($contato, 'Contato'),
                    'etapa' => $t('et.nome', 'Etapa'),
                    'status' => $e('a.status', 'Status', 'status_negocio'),
                    'valor_estimado' => $m('a.valor_estimado', 'Valor estimado'),
                    'valor_fechado' => $m('a.valor_fechado', 'Valor fechado'),
                    'probabilidade' => $n('a.probabilidade', 'Probabilidade (%)'),
                    'temperatura' => $e('a.temperatura', 'Temperatura', 'temperatura'),
                    'prioridade' => $e('a.prioridade', 'Prioridade', 'prioridade_neg'),
                    'origem' => $t('o.nome', 'Origem'),
                    'previsao_fechamento' => $d('a.previsao_fechamento', 'Previsão'),
                    'data_fechamento' => $d('a.data_fechamento', 'Fechamento'),
                    'proximo_passo_em' => $d('a.proximo_passo_em', 'Próximo passo em'),
                    'criado_em' => $d('a.criado_em', 'Criado em'),
                ],
            ],
            'tarefas' => [
                'rotulo' => 'Tarefas', 'link' => '/tarefas', 'ordem' => 'a.vencimento IS NULL, a.vencimento ASC, a.id DESC',
                'from' => 'FROM tarefas a LEFT JOIN empresas e ON e.id = a.empresa_id LEFT JOIN contatos c ON c.id = a.contato_id
                           LEFT JOIN negocios n ON n.id = a.negocio_id',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['titulo', 'status', 'prioridade', 'vencimento', 'empresa'],
                'campos' => [
                    'titulo' => $t('a.titulo', 'Tarefa'),
                    'tipo' => $e('a.tipo', 'Tipo', 'tipo_tarefa'),
                    'prioridade' => $e('a.prioridade', 'Prioridade', 'prioridade_tar'),
                    'status' => $e('a.status', 'Status', 'status_tarefa'),
                    'vencimento' => $d('a.vencimento', 'Vencimento'),
                    'empresa' => $t('e.nome_fantasia', 'Empresa'),
                    'contato' => $t($contato, 'Contato'),
                    'negocio' => $t('n.titulo', 'Negócio'),
                ],
            ],
            'atividades' => [
                'rotulo' => 'Atividades', 'link' => '','ordem' => 'a.data_hora DESC, a.id DESC',
                'from' => 'FROM atividades a LEFT JOIN empresas e ON e.id = a.empresa_id LEFT JOIN contatos c ON c.id = a.contato_id
                           LEFT JOIN negocios n ON n.id = a.negocio_id',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['tipo', 'assunto', 'data_hora', 'empresa', 'contato'],
                'campos' => [
                    'tipo' => $e('a.tipo', 'Tipo', 'tipo_atividade'),
                    'assunto' => $t('a.assunto', 'Assunto'),
                    'descricao' => $t('a.descricao', 'Descrição'),
                    'data_hora' => $d('a.data_hora', 'Data'),
                    'empresa' => $t('e.nome_fantasia', 'Empresa'),
                    'contato' => $t($contato, 'Contato'),
                    'negocio' => $t('n.titulo', 'Negócio'),
                ],
            ],
            'servicos' => [
                'rotulo' => 'Serviços', 'link' => '/servicos/', 'ordem' => 'a.nome COLLATE pt_br ASC',
                'from' => 'FROM servicos a',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['nome', 'categoria', 'unidade', 'preco_base'],
                'campos' => [
                    'nome' => $t('a.nome', 'Serviço'),
                    'categoria' => $e('a.categoria', 'Categoria', 'categoria_servico'),
                    'unidade' => $e('a.unidade', 'Unidade', 'unidade_servico'),
                    'preco_base' => $m('a.preco_base', 'Preço base'),
                    'ativo' => $n('a.ativo', 'Ativo (1/0)'),
                ],
            ],
            'propostas' => [
                'rotulo' => 'Propostas', 'link' => '/propostas/', 'ordem' => 'a.criado_em DESC, a.id DESC',
                'from' => 'FROM propostas a LEFT JOIN empresas e ON e.id = a.empresa_id LEFT JOIN negocios n ON n.id = a.negocio_id',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['numero', 'titulo', 'empresa', 'status', 'total'],
                'campos' => [
                    'numero' => $t('a.numero', 'Número'),
                    'titulo' => $t('a.titulo', 'Título'),
                    'empresa' => $t('e.nome_fantasia', 'Empresa'),
                    'negocio' => $t('n.titulo', 'Negócio'),
                    'status' => $e('a.status', 'Status', 'status_proposta'),
                    'total' => $m('a.total', 'Total'),
                    'validade' => $d('a.validade', 'Validade'),
                    'criado_em' => $d('a.criado_em', 'Criada em'),
                ],
            ],
            'contratos' => [
                'rotulo' => 'Contratos', 'link' => '/contratos/', 'ordem' => 'a.criado_em DESC, a.id DESC',
                'from' => 'FROM contratos a LEFT JOIN empresas e ON e.id = a.empresa_id LEFT JOIN contrato_tipos ct ON ct.id = a.tipo_id',
                'onde' => 'a.arquivado_em IS NULL',
                'exibir' => ['numero', 'titulo', 'empresa', 'status', 'data_fim'],
                'campos' => [
                    'numero' => $t('a.numero', 'Número'),
                    'titulo' => $t('a.titulo', 'Título'),
                    'empresa' => $t('e.nome_fantasia', 'Empresa'),
                    'tipo' => $t('ct.nome', 'Tipo'),
                    'status' => $e('a.status', 'Status', 'status_contrato'),
                    'valor_total' => $m('a.valor_total', 'Valor total'),
                    'valor_mensal' => $m('a.valor_mensal', 'Valor mensal'),
                    'data_inicio' => $d('a.data_inicio', 'Início'),
                    'data_fim' => $d('a.data_fim', 'Fim'),
                ],
            ],
        ];
    }
}
