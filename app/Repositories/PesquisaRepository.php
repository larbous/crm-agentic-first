<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/**
 * Tabela pesquisas (Fase 12): um envio de pesquisa de satisfação a um cliente, com link individual e a resposta.
 * Só SQL; as escritas de negócio passam pelo ActionExecutor (AcoesPesquisas).
 */
final class PesquisaRepository
{
    private const SELECT = "SELECT p.*, f.nome AS formulario_nome, f.titulo AS formulario_titulo, e.nome_fantasia AS empresa_nome,
            TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome, c.email AS contato_email, c.whatsapp AS contato_whatsapp,
            ct.numero AS contrato_numero
        FROM pesquisas p
        JOIN formularios f ON f.id = p.formulario_id
        JOIN empresas e ON e.id = p.empresa_id
        LEFT JOIN contatos c ON c.id = p.contato_id
        LEFT JOIN contratos ct ON ct.id = p.contrato_id";

    public function inserir(array $dados): int
    {
        $cols = array_keys($dados);
        DB::conexao()->prepare('INSERT INTO pesquisas (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($dados);
        return (int) DB::conexao()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($dados)));
        DB::conexao()->prepare("UPDATE pesquisas SET {$sets} WHERE id = :__id")->execute($dados + ['__id' => $id]);
    }

    /** Responde uma pesquisa pendente de forma atômica: false se outra requisição já a respondeu/cancelou/expirou. */
    public function registrarResposta(int $id, array $dados): bool
    {
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($dados)));
        $st = DB::conexao()->prepare("UPDATE pesquisas SET {$sets} WHERE id = :__id AND status = 'pendente'");
        $st->execute($dados + ['__id' => $id]);
        return $st->rowCount() === 1;
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare(self::SELECT . ' WHERE p.id = :id AND p.arquivado_em IS NULL');
        $st->execute(['id' => $id]);
        return $this->decodificar($st->fetch() ?: null);
    }

    public function porToken(string $token): ?array
    {
        $st = DB::conexao()->prepare(self::SELECT . ' WHERE p.token = :t AND p.arquivado_em IS NULL');
        $st->execute(['t' => $token]);
        return $this->decodificar($st->fetch() ?: null);
    }

    public function tokenExiste(string $token): bool
    {
        $st = DB::conexao()->prepare('SELECT 1 FROM pesquisas WHERE token = :t');
        $st->execute(['t' => $token]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Listagem paginada (mais recentes primeiro). Filtros: formulario_id, status, desde (data ISO), empresa_id.
     * @return array{linhas:list<array>,total:int,pagina:int,por_pagina:int,paginas:int}
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 25): array
    {
        [$onde, $params] = $this->condicoes($filtros);
        $st = DB::conexao()->prepare("SELECT COUNT(*) FROM pesquisas p WHERE {$onde}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($paginas, $pagina));
        $st = DB::conexao()->prepare(self::SELECT . " WHERE {$onde} ORDER BY COALESCE(p.respondida_em, p.criado_em) DESC, p.id DESC LIMIT " . (int) $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina));
        $st->execute($params);
        return ['linhas' => array_map($this->decodificar(...), $st->fetchAll()), 'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina, 'paginas' => $paginas];
    }

    /** @return list<array> últimas pesquisas da empresa (qualquer status) */
    public function daEmpresa(int $empresaId, int $limite = 5): array
    {
        $st = DB::conexao()->prepare(self::SELECT . ' WHERE p.empresa_id = :e AND p.arquivado_em IS NULL ORDER BY p.criado_em DESC, p.id DESC LIMIT ' . (int) $limite);
        $st->execute(['e' => $empresaId]);
        return array_map($this->decodificar(...), $st->fetchAll());
    }

    /** @return array<int,int> nota => quantidade (só respondidas) */
    public function contagemPorNota(array $filtros = []): array
    {
        [$onde, $params] = $this->condicoes(['status' => 'respondida'] + $filtros, 'respondida_em');
        $st = DB::conexao()->prepare("SELECT nota, COUNT(*) AS n FROM pesquisas p WHERE {$onde} GROUP BY nota");
        $st->execute($params);
        return array_map('intval', array_column($st->fetchAll(), 'n', 'nota'));
    }

    /** @return array<string,array<int,int>> 'AAAA-MM' => (nota => quantidade), meses em ordem cronológica */
    public function contagemPorMes(array $filtros = []): array
    {
        [$onde, $params] = $this->condicoes(['status' => 'respondida'] + $filtros, 'respondida_em');
        $st = DB::conexao()->prepare("SELECT substr(p.respondida_em, 1, 7) AS mes, p.nota, COUNT(*) AS n FROM pesquisas p WHERE {$onde} GROUP BY mes, p.nota ORDER BY mes");
        $st->execute($params);
        $saida = [];
        foreach ($st->fetchAll() as $l) {
            $saida[(string) $l['mes']][(int) $l['nota']] = (int) $l['n'];
        }
        return $saida;
    }

    /** @return array<string,int> status => quantidade, das pesquisas criadas no período */
    public function contagemPorStatus(array $filtros = []): array
    {
        [$onde, $params] = $this->condicoes($filtros);
        $st = DB::conexao()->prepare("SELECT p.status, COUNT(*) AS n FROM pesquisas p WHERE {$onde} GROUP BY p.status");
        $st->execute($params);
        return array_map('intval', array_column($st->fetchAll(), 'n', 'status'));
    }

    /** Pendentes cujo link ainda não foi marcado como entregue (fila de envio manual). */
    public function contarAEnviar(): int
    {
        return (int) DB::conexao()->query("SELECT COUNT(*) FROM pesquisas WHERE status = 'pendente' AND enviada_em IS NULL AND arquivado_em IS NULL")->fetchColumn();
    }

    /** Contato a quem enviar: o do contrato, senão um decisor e, por último, o primeiro ativo com e-mail ou WhatsApp. */
    public function contatoParaPesquisa(int $empresaId, ?int $contatoPreferido = null): ?int
    {
        if ($contatoPreferido !== null) {
            $st = DB::conexao()->prepare('SELECT id FROM contatos WHERE id = :c AND empresa_id = :e AND arquivado_em IS NULL');
            $st->execute(['c' => $contatoPreferido, 'e' => $empresaId]);
            if ($st->fetchColumn() !== false) {
                return $contatoPreferido;
            }
        }
        $st = DB::conexao()->prepare(
            "SELECT id FROM contatos WHERE empresa_id = :e AND arquivado_em IS NULL AND status = 'ativo'
               AND (COALESCE(email, '') <> '' OR COALESCE(whatsapp, '') <> '')
             ORDER BY (papel_decisao = 'decisor') DESC, id ASC LIMIT 1"
        );
        $st->execute(['e' => $empresaId]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Contratos assinados entre $de e $ate (datas ISO) que ainda não têm pesquisa deste formulário.
     * @return list<array{id:int,empresa_id:int,contato_id:?int}>
     */
    public function contratosParaPesquisar(int $formularioId, string $de, string $ate, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT c.id, c.empresa_id, c.contato_id FROM contratos c
              WHERE c.status IN ('assinado', 'ativo') AND c.arquivado_em IS NULL AND c.empresa_id IS NOT NULL
                AND c.assinado_em IS NOT NULL AND substr(c.assinado_em, 1, 10) BETWEEN :de AND :ate
                AND NOT EXISTS (SELECT 1 FROM pesquisas p WHERE p.formulario_id = :f AND p.contrato_id = c.id)
              ORDER BY c.assinado_em LIMIT " . (int) $limite
        );
        $st->execute(['de' => $de, 'ate' => $ate, 'f' => $formularioId]);
        return $st->fetchAll();
    }

    /**
     * Clientes ativos sem pesquisa deste formulário (não cancelada) criada desde $criadasDesde e que já eram clientes em $clienteAte.
     * @return list<array{id:int}>
     */
    public function clientesParaPesquisar(int $formularioId, string $criadasDesde, string $clienteAte, int $limite): array
    {
        $st = DB::conexao()->prepare(
            "SELECT e.id FROM empresas e
              WHERE e.status = 'cliente' AND e.arquivado_em IS NULL AND (e.cliente_desde IS NULL OR e.cliente_desde <= :ate)
                AND NOT EXISTS (SELECT 1 FROM pesquisas p WHERE p.formulario_id = :f AND p.empresa_id = e.id
                                  AND p.status <> 'cancelada' AND p.criado_em >= :desde)
              ORDER BY e.id LIMIT " . (int) $limite
        );
        $st->execute(['ate' => $clienteAte, 'f' => $formularioId, 'desde' => $criadasDesde]);
        return $st->fetchAll();
    }

    /** @return list<int> ids de pesquisas pendentes com o link vencido */
    public function vencidas(string $agora, int $limite): array
    {
        $st = DB::conexao()->prepare("SELECT id FROM pesquisas WHERE status = 'pendente' AND expira_em < :a ORDER BY id LIMIT " . (int) $limite);
        $st->execute(['a' => $agora]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array{0:string,1:array} */
    private function condicoes(array $filtros, string $colunaData = 'criado_em'): array
    {
        $onde = ['p.arquivado_em IS NULL'];
        $params = [];
        foreach (['formulario_id' => 'p.formulario_id', 'empresa_id' => 'p.empresa_id'] as $k => $col) {
            if (!empty($filtros[$k])) {
                $onde[] = "{$col} = :{$k}";
                $params[$k] = (int) $filtros[$k];
            }
        }
        if (($filtros['status'] ?? '') !== '') {
            $onde[] = 'p.status = :status';
            $params['status'] = $filtros['status'];
        }
        if (($filtros['desde'] ?? '') !== '') {
            $onde[] = "p.{$colunaData} >= :desde";
            $params['desde'] = $filtros['desde'];
        }
        return [implode(' AND ', $onde), $params];
    }

    private function decodificar(?array $linha): ?array
    {
        if ($linha === null) {
            return null;
        }
        $linha['respostas'] = $linha['respostas'] !== null && $linha['respostas'] !== '' ? (array) json_decode((string) $linha['respostas'], true) : [];
        return $linha;
    }
}
