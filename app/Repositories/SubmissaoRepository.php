<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Tabela formulario_submissoes: o que chegou pelos formulários públicos (nunca é apagada). */
final class SubmissaoRepository
{
    public function inserir(array $dados): int
    {
        $cols = array_keys($dados);
        DB::conexao()->prepare(
            'INSERT INTO formulario_submissoes (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')'
        )->execute($dados);
        return (int) DB::conexao()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($dados)));
        DB::conexao()->prepare("UPDATE formulario_submissoes SET {$sets} WHERE id = :__id")->execute($dados + ['__id' => $id]);
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM formulario_submissoes WHERE id = :id');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** Submissões do IP desde o instante (limite de envios por hora; conta também as marcadas como spam). */
    public function contarDoIpDesde(string $ip, string $desde): int
    {
        $st = DB::conexao()->prepare('SELECT COUNT(*) FROM formulario_submissoes WHERE ip = :ip AND criado_em >= :d');
        $st->execute(['ip' => $ip, 'd' => $desde]);
        return (int) $st->fetchColumn();
    }

    /**
     * Lista paginada, mais recentes primeiro, com os nomes dos registros criados. Filtros: formulario_id, status.
     * @return array{linhas:list<array>,total:int,pagina:int,paginas:int}
     */
    public function listar(array $filtros = [], int $pagina = 1, int $porPagina = 25): array
    {
        $onde = ['1 = 1'];
        $params = [];
        if (!empty($filtros['formulario_id'])) {
            $onde[] = 's.formulario_id = :f';
            $params['f'] = (int) $filtros['formulario_id'];
        }
        if (!empty($filtros['status'])) {
            $onde[] = 's.status = :st';
            $params['st'] = (string) $filtros['status'];
        }
        $where = implode(' AND ', $onde);
        $pdo = DB::conexao();
        $st = $pdo->prepare("SELECT COUNT(*) FROM formulario_submissoes s WHERE {$where}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = max(1, min($paginas, $pagina));
        $st = $pdo->prepare(
            "SELECT s.*, f.nome AS formulario_nome, e.nome_fantasia AS empresa_nome,
                    TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS contato_nome, n.titulo AS negocio_titulo
               FROM formulario_submissoes s
               JOIN formularios f ON f.id = s.formulario_id
               LEFT JOIN empresas e ON e.id = s.empresa_id
               LEFT JOIN contatos c ON c.id = s.contato_id
               LEFT JOIN negocios n ON n.id = s.negocio_id
              WHERE {$where} ORDER BY s.id DESC LIMIT {$porPagina} OFFSET " . (($pagina - 1) * $porPagina)
        );
        $st->execute($params);
        return ['linhas' => $st->fetchAll(), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas];
    }
}
