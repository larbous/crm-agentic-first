<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/**
 * Tabelas formularios e formulario_campos (SPEC §4.10). São configuração do sistema (como agentes e squads): a escrita
 * passa pelo ActionExecutor, sem log_auditoria. Formulário é arquivado, nunca apagado (as submissões apontam para ele).
 */
final class FormularioRepository
{
    /** @return list<array> formulários não arquivados, com a contagem de submissões e de campos */
    public function todos(): array
    {
        return DB::conexao()->query(
            'SELECT f.*, (SELECT COUNT(*) FROM formulario_submissoes s WHERE s.formulario_id = f.id) AS submissoes,
                    (SELECT COUNT(*) FROM formulario_campos c WHERE c.formulario_id = f.id) AS campos
               FROM formularios f WHERE f.arquivado_em IS NULL ORDER BY f.nome COLLATE pt_br'
        )->fetchAll();
    }

    public function encontrar(int $id): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM formularios WHERE id = :id AND arquivado_em IS NULL');
        $st->execute(['id' => $id]);
        return $st->fetch() ?: null;
    }

    /** Formulário pela chave pública (arquivado não aparece). */
    public function porChave(string $chave): ?array
    {
        $st = DB::conexao()->prepare('SELECT * FROM formularios WHERE chave = :c AND arquivado_em IS NULL');
        $st->execute(['c' => $chave]);
        return $st->fetch() ?: null;
    }

    /** id => nome, incluindo arquivados (as submissões antigas continuam apontando para eles). */
    public function nomes(): array
    {
        return array_column(DB::conexao()->query('SELECT id, nome FROM formularios ORDER BY nome COLLATE pt_br')->fetchAll(), 'nome', 'id');
    }

    /** Campos na ordem de exibição, com `opcoes` decodificado (lista de textos). */
    public function campos(int $formularioId): array
    {
        $st = DB::conexao()->prepare('SELECT * FROM formulario_campos WHERE formulario_id = :f ORDER BY ordem ASC, id ASC');
        $st->execute(['f' => $formularioId]);
        return array_map(static function (array $c): array {
            $c['opcoes'] = $c['opcoes'] !== null && $c['opcoes'] !== '' ? (array) json_decode((string) $c['opcoes'], true) : [];
            return $c;
        }, $st->fetchAll());
    }

    public function inserir(array $dados): int
    {
        $cols = array_keys($dados);
        DB::conexao()->prepare(
            'INSERT INTO formularios (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')'
        )->execute($dados);
        return (int) DB::conexao()->lastInsertId();
    }

    public function atualizar(int $id, array $dados): void
    {
        $sets = implode(', ', array_map(static fn (string $c) => "{$c} = :{$c}", array_keys($dados)));
        DB::conexao()->prepare("UPDATE formularios SET {$sets} WHERE id = :__id")->execute($dados + ['__id' => $id]);
    }

    /** Troca todos os campos do formulário (a ordem vem da posição na lista). */
    public function substituirCampos(int $formularioId, array $campos): void
    {
        $pdo = DB::conexao();
        $pdo->prepare('DELETE FROM formulario_campos WHERE formulario_id = :f')->execute(['f' => $formularioId]);
        $ins = $pdo->prepare(
            'INSERT INTO formulario_campos (formulario_id, campo_destino, rotulo, tipo, placeholder, ajuda, obrigatorio, opcoes, largura, ordem)
             VALUES (:f, :destino, :rotulo, :tipo, :placeholder, :ajuda, :obrigatorio, :opcoes, :largura, :ordem)'
        );
        foreach (array_values($campos) as $i => $c) {
            $ins->execute([
                'f' => $formularioId, 'destino' => $c['campo_destino'], 'rotulo' => $c['rotulo'], 'tipo' => $c['tipo'],
                'placeholder' => $c['placeholder'] ?? null, 'ajuda' => $c['ajuda'] ?? null, 'obrigatorio' => (int) ($c['obrigatorio'] ?? 0),
                'opcoes' => !empty($c['opcoes']) ? json_encode(array_values($c['opcoes']), JSON_UNESCAPED_UNICODE) : null,
                'largura' => (int) $c['largura'], 'ordem' => ($i + 1) * 10,
            ]);
        }
    }

    public function chaveExiste(string $chave): bool
    {
        $st = DB::conexao()->prepare('SELECT 1 FROM formularios WHERE chave = :c');
        $st->execute(['c' => $chave]);
        return $st->fetchColumn() !== false;
    }
}
