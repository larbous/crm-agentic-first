<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\Config;
use App\Repositories\ContextoAgenteRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;
use App\Services\CamposExtras;
use App\Services\Schema;

/**
 * Contexto enviado à IA. Roteador (SPEC §3.3): registro aberto na tela, última entidade referenciada e a data de hoje,
 * sem histórico de conversa; nome e id vêm do banco, não do navegador. Agentes (SPEC §6.1): só os campos listados em
 * `contexto` do agente e os relacionados de `contexto_relacionado`, sem ids.
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

    // =====================================================================================
    // Agentes
    // =====================================================================================

    private const LIMITE_TEXTO = 1500;

    /**
     * Mensagem de usuário enviada a um agente: {hoje, registro, relacionado, entrada}. Só entram os campos do
     * `contexto` do agente (valores legíveis: dinheiro em reais, nomes no lugar de ids) e os relacionados pedidos.
     * @param array $def definição do agente
     */
    public function paraAgente(array $def, ?string $entidade, ?int $id, ?string $entrada = null, ?string $hoje = null): string
    {
        $hoje ??= hoje();
        $cadeia = AcaoAgente::cadeia($entidade, $id);
        $saida = ['hoje' => $hoje];

        if ($entidade !== null && $id !== null) {
            $registro = Repositorios::para($entidade)->encontrar($id);
            $saida['registro'] = $registro !== null ? $this->campos($entidade, $registro, (array) $def['contexto']) : new \stdClass();
        }
        $relacionado = $this->relacionados((array) $def['contexto_relacionado'], $cadeia, $hoje);
        if ($relacionado !== []) {
            $saida['relacionado'] = $relacionado;
        }
        if ($entrada !== null && trim($entrada) !== '') {
            $saida['entrada'] = trim($entrada);
        }
        return json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** @return array<string,mixed> campo => valor legível, sem vazios */
    private function campos(string $entidade, array $registro, array $campos): array
    {
        $schema = Schema::entidade($entidade)['campos'] ?? [];
        $saida = [];
        foreach ($campos as $campo) {
            if (str_starts_with($campo, 'extra.')) {
                // Campo extra: "extra.<chave>" entra como "<rótulo>" (só se ativo e preenchido).
                $valor = CamposExtras::paraContexto($entidade, $registro, substr($campo, 6));
                if ($valor !== null) {
                    $saida[CamposExtras::porChave($entidade)[substr($campo, 6)]['rotulo']] = $this->texto($valor);
                }
                continue;
            }
            $def = $schema[$campo] ?? null;
            $valor = $registro[$campo] ?? null;
            if ($def === null || $valor === null || $valor === '') {
                continue;
            }
            $chave = $campo;
            if ($def['t'] === 'fk') {
                $ref = Repositorios::para((string) $def['fk'])->encontrar((int) $valor, true);
                $valor = $ref !== null ? $this->rotulo($ref) : null;
                $chave = (string) preg_replace('/_id$/', '', $campo);
            } else {
                $valor = $this->valor($def, $valor);
            }
            if ($valor !== null && $valor !== '') {
                $saida[$chave] = $valor;
            }
        }
        return $saida;
    }

    private function valor(array $def, mixed $valor): mixed
    {
        return match ($def['t']) {
            'money' => (int) $valor / 100,
            'bool' => (int) $valor === 1,
            'int' => (int) $valor,
            'enum' => Schema::opcoes($def['op'])[$valor] ?? $valor,
            'html' => $this->texto(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>'], "\n", (string) $valor)))),
            default => $this->texto((string) $valor),
        };
    }

    private function texto(string $t, int $limite = self::LIMITE_TEXTO): string
    {
        $t = trim($t);
        return mb_strlen($t) > $limite ? mb_substr($t, 0, $limite) . '…' : $t;
    }

    private function rotulo(array $r): string
    {
        return (string) ($r['nome_fantasia'] ?? (isset($r['numero']) ? $r['numero'] . ' — ' . ($r['titulo'] ?? '') : ($r['titulo'] ?? trim(($r['nome'] ?? '') . ' ' . ($r['sobrenome'] ?? '')))));
    }

    /** @return array<string,mixed> */
    private function relacionados(array $pedidos, array $c, string $hoje): array
    {
        $ctx = new ContextoAgenteRepository();
        $saida = [];
        foreach ($pedidos as $chave => $n) {
            $n = (int) $n;
            if ($n <= 0) {
                continue;
            }
            $linhas = match ($chave) {
                'atividades' => $this->atividades($c, $n),
                'tarefas' => $this->tarefas($c, $n),
                'contatos' => $this->contatosDaEmpresa($c, $n),
                'negocios' => $this->negociosDaEmpresa($c, $n),
                'propostas' => $this->propostas($c, $n),
                'contratos' => $this->contratos($c, $n),
                'servicos' => $this->servicos($n),
                'modelos_proposta' => $this->modelos($ctx, ['proposta'], $n, 800),
                'modelos_contrato' => $this->modelos($ctx, ['contrato'], $n, 0),
                'modelos_mensagem' => $this->modelos($ctx, ['whatsapp', 'email'], $n, self::LIMITE_TEXTO),
                'empresa' => $this->empresa($c),
                'negocios_parados' => $this->negociosParados($ctx, $n, $hoje),
                'previsoes_vencidas' => $this->somenteValores($ctx->previsoesVencidas($hoje, $n), ['valor_estimado']),
                'tarefas_atrasadas' => $ctx->tarefasAtrasadas($hoje, $n),
                default => [],
            };
            if ($linhas !== []) {
                $saida[$chave] = $linhas;
            }
        }
        return $saida;
    }

    /** Campo de vínculo mais específico do alvo para timeline e tarefas. */
    private function vinculoDoAlvo(array $c): ?array
    {
        return match (true) {
            $c['negocios'] !== null => ['negocio_id', $c['negocios']],
            $c['alvo'] === 'contatos' && $c['contatos'] !== null => ['contato_id', $c['contatos']],
            $c['empresas'] !== null => ['empresa_id', $c['empresas']],
            default => null,
        };
    }

    private function atividades(array $c, int $n): array
    {
        $v = $this->vinculoDoAlvo($c);
        if ($v === null) {
            return [];
        }
        return array_map(fn (array $a): array => array_filter([
            'data' => $a['data_hora'], 'tipo' => Schema::opcoes('tipo_atividade')[$a['tipo']] ?? $a['tipo'],
            'direcao' => $a['direcao'], 'assunto' => $a['assunto'], 'descricao' => $this->texto((string) $a['descricao'], 500),
        ], static fn ($x): bool => $x !== null && $x !== ''), Repositorios::atividades()->timeline($v[0], (int) $v[1], $n));
    }

    private function tarefas(array $c, int $n): array
    {
        $v = $this->vinculoDoAlvo($c);
        if ($c['alvo'] === 'contratos' && $c['contratos'] !== null) {
            $v = ['contrato_id', $c['contratos']];
        }
        if ($v === null) {
            return [];
        }
        return array_map(static fn (array $t): array => array_filter([
            'titulo' => $t['titulo'], 'vencimento' => $t['vencimento'], 'status' => Schema::opcoes('status_tarefa')[$t['status']] ?? $t['status'],
            'prioridade' => Schema::opcoes('prioridade_tar')[$t['prioridade']] ?? $t['prioridade'],
        ], static fn ($x): bool => $x !== null && $x !== ''), Repositorios::tarefas()->porVinculo($v[0], (int) $v[1], $n));
    }

    private function contatosDaEmpresa(array $c, int $n): array
    {
        if ($c['empresas'] === null) {
            return [];
        }
        return array_map(static fn (array $x): array => array_filter([
            'nome' => trim($x['nome'] . ' ' . ($x['sobrenome'] ?? '')), 'cargo' => $x['cargo'],
            'papel_decisao' => Schema::opcoes('papel_decisao')[$x['papel_decisao'] ?? ''] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== ''), array_slice(Repositorios::contatos()->porEmpresa((int) $c['empresas']), 0, $n));
    }

    private function negociosDaEmpresa(array $c, int $n): array
    {
        if ($c['empresas'] === null) {
            return [];
        }
        return array_map(static fn (array $x): array => array_filter([
            'codigo' => $x['codigo'], 'titulo' => $x['titulo'], 'etapa' => $x['etapa_nome'] ?? null,
            'valor_estimado' => $x['valor_estimado'] !== null ? (int) $x['valor_estimado'] / 100 : null,
            'status' => Schema::opcoes('status_negocio')[$x['status']] ?? $x['status'],
        ], static fn ($v): bool => $v !== null && $v !== ''), array_slice(Repositorios::negocios()->porEmpresa((int) $c['empresas']), 0, $n));
    }

    /** Propostas do alvo: a "principal" (a do alvo ou a do negócio) primeiro, depois as últimas versões do negócio/empresa. */
    private function propostas(array $c, int $n): array
    {
        $linhas = [];
        if ($c['propostas'] !== null) {
            $principal = Repositorios::propostas()->encontrar((int) $c['propostas']);
            $linhas = $principal !== null ? [$principal] : [];
        }
        $outras = match (true) {
            $c['negocios'] !== null => Repositorios::propostas()->ultimasPor('negocio_id', (int) $c['negocios']),
            $c['empresas'] !== null => Repositorios::propostas()->ultimasPor('empresa_id', (int) $c['empresas']),
            default => [],
        };
        $ids = array_map(static fn (array $l): int => (int) $l['id'], $linhas);
        foreach ($outras as $p) {
            if (!in_array((int) $p['id'], $ids, true)) {
                $linhas[] = $p;
            }
        }

        $itens = new ItemPropostaRepository();
        $saida = [];
        foreach (array_slice($linhas, 0, $n) as $p) {
            $saida[] = array_filter([
                'numero' => $p['numero'], 'titulo' => $p['titulo'], 'status' => Schema::opcoes('status_proposta')[$p['status']] ?? $p['status'],
                'total' => (int) $p['total'] / 100, 'total_recorrente' => (int) $p['total_recorrente'] > 0 ? (int) $p['total_recorrente'] / 100 : null,
                'validade' => $p['validade'],
                'itens' => array_map(static fn (array $i): array => [
                    'descricao' => $i['descricao'], 'quantidade' => (float) $i['quantidade'], 'valor_unitario' => (int) $i['valor_unitario'] / 100,
                    'recorrente' => (int) $i['recorrente'] === 1,
                ], array_slice($itens->porProposta((int) $p['id']), 0, 20)),
            ], static fn ($x): bool => $x !== null && $x !== '' && $x !== []);
        }
        return $saida;
    }

    private function contratos(array $c, int $n): array
    {
        $lista = match (true) {
            $c['negocios'] !== null => Repositorios::contratos()->por('negocio_id', (int) $c['negocios']),
            $c['empresas'] !== null => Repositorios::contratos()->por('empresa_id', (int) $c['empresas']),
            default => [],
        };
        return array_map(static fn (array $x): array => array_filter([
            'numero' => $x['numero'], 'titulo' => $x['titulo'], 'status' => Schema::opcoes('status_contrato')[$x['status']] ?? $x['status'],
            'valor_total' => $x['valor_total'] !== null ? (int) $x['valor_total'] / 100 : null,
            'valor_mensal' => $x['valor_mensal'] !== null ? (int) $x['valor_mensal'] / 100 : null,
            'inicio' => $x['data_inicio'], 'fim' => $x['data_fim'],
        ], static fn ($v): bool => $v !== null && $v !== ''), array_slice($lista, 0, $n));
    }

    private function servicos(int $n): array
    {
        return array_map(fn (array $s): array => array_filter([
            'nome' => $s['nome'], 'descricao' => $this->texto((string) $s['descricao'], 200),
            'unidade' => Schema::opcoes('unidade_servico')[$s['unidade']] ?? $s['unidade'],
            'preco_base' => $s['preco_base'] !== null ? (int) $s['preco_base'] / 100 : null, 'recorrente' => (int) $s['recorrente'] === 1,
        ], static fn ($v): bool => $v !== null && $v !== ''), array_slice(Repositorios::servicos()->ativos(), 0, $n));
    }

    /** @param list<string> $tipos */
    private function modelos(ContextoAgenteRepository $ctx, array $tipos, int $n, int $limiteConteudo): array
    {
        $saida = [];
        foreach ($tipos as $tipo) {
            foreach ($ctx->modelos($tipo, $n) as $m) {
                $saida[] = array_filter([
                    'tipo' => $m['tipo'], 'nome' => $m['nome'], 'assunto' => $m['assunto'],
                    'conteudo' => $limiteConteudo > 0 ? $this->texto(html_entity_decode(strip_tags((string) $m['conteudo'])), $limiteConteudo) : null,
                ], static fn ($v): bool => $v !== null && $v !== '');
            }
        }
        return array_slice($saida, 0, $n * count($tipos));
    }

    private function empresa(array $c): array
    {
        if ($c['empresas'] === null) {
            return [];
        }
        $e = Repositorios::empresas()->encontrar((int) $c['empresas']);
        return $e === null ? [] : $this->campos('empresas', $e, [
            'nome_fantasia', 'segmento', 'porte', 'cidade', 'uf', 'site', 'instagram', 'status', 'classificacao',
            'faixa_faturamento', 'ticket_potencial', 'notas',
        ]);
    }

    private function negociosParados(ContextoAgenteRepository $ctx, int $n, string $hoje): array
    {
        $limite = date('Y-m-d H:i:s', strtotime($hoje . ' 00:00:00 -14 days'));
        return array_map(static fn (array $l): array => array_filter([
            'codigo' => $l['codigo'], 'titulo' => $l['titulo'], 'empresa' => $l['empresa'], 'etapa' => $l['etapa'],
            'dias_na_etapa' => (int) floor((strtotime($hoje) - strtotime((string) $l['entrou_etapa_em'])) / 86400),
            'valor_estimado' => $l['valor_estimado'] !== null ? (int) $l['valor_estimado'] / 100 : null,
        ], static fn ($v): bool => $v !== null && $v !== ''), $ctx->negociosParados($limite, $n));
    }

    /** Converte centavos em reais nas colunas indicadas e remove vazios. */
    private function somenteValores(array $linhas, array $dinheiro): array
    {
        return array_map(static function (array $l) use ($dinheiro): array {
            foreach ($dinheiro as $col) {
                if (isset($l[$col])) {
                    $l[$col] = (int) $l[$col] / 100;
                }
            }
            return array_filter($l, static fn ($v): bool => $v !== null && $v !== '');
        }, $linhas);
    }
}
