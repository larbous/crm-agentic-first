<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Busca global (empresas, contatos, negócios) sem acento/caixa. */
final class BuscaRepository
{
    /** @return array{empresas:list<array>,contatos:list<array>,negocios:list<array>} */
    public function buscar(string $termo, int $limite = 6): array
    {
        $termo = trim($termo);
        if ($termo === '') {
            return ['empresas' => [], 'contatos' => [], 'negocios' => []];
        }
        $like = '%' . addcslashes(normalizar_busca($termo), '%_\\') . '%';
        $limite = max(1, min(20, $limite));
        $pdo = DB::conexao();

        $consulta = static function (string $sql) use ($pdo, $like): array {
            $st = $pdo->prepare($sql);
            $st->execute(['q' => $like]);
            return $st->fetchAll();
        };

        return [
            'empresas' => $consulta(
                "SELECT id, nome_fantasia AS titulo, COALESCE(NULLIF(cidade, ''), segmento, '') AS subtitulo, status
                 FROM empresas WHERE arquivado_em IS NULL AND (
                    busca_norm(nome_fantasia) LIKE :q ESCAPE '\\' OR busca_norm(razao_social) LIKE :q ESCAPE '\\'
                    OR busca_norm(email_geral) LIKE :q ESCAPE '\\' OR busca_norm(telefone) LIKE :q ESCAPE '\\'
                    OR busca_norm(whatsapp) LIKE :q ESCAPE '\\' OR busca_norm(cnpj) LIKE :q ESCAPE '\\' OR busca_norm(dominio) LIKE :q ESCAPE '\\')
                 ORDER BY nome_fantasia COLLATE pt_br LIMIT {$limite}"
            ),
            'contatos' => $consulta(
                "SELECT c.id, TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS titulo, COALESCE(e.nome_fantasia, c.cargo, '') AS subtitulo
                 FROM contatos c LEFT JOIN empresas e ON e.id = c.empresa_id
                 WHERE c.arquivado_em IS NULL AND (
                    busca_norm(c.nome || ' ' || COALESCE(c.sobrenome, '')) LIKE :q ESCAPE '\\' OR busca_norm(c.apelido) LIKE :q ESCAPE '\\'
                    OR busca_norm(c.email) LIKE :q ESCAPE '\\' OR busca_norm(c.telefone) LIKE :q ESCAPE '\\' OR busca_norm(c.whatsapp) LIKE :q ESCAPE '\\')
                 ORDER BY c.nome COLLATE pt_br LIMIT {$limite}"
            ),
            'negocios' => $consulta(
                "SELECT n.id, n.titulo AS titulo, TRIM(COALESCE(n.codigo, '') || ' · ' || COALESCE(e.nome_fantasia, '')) AS subtitulo, n.status
                 FROM negocios n LEFT JOIN empresas e ON e.id = n.empresa_id
                 WHERE n.arquivado_em IS NULL AND (
                    busca_norm(n.titulo) LIKE :q ESCAPE '\\' OR busca_norm(n.codigo) LIKE :q ESCAPE '\\' OR busca_norm(e.nome_fantasia) LIKE :q ESCAPE '\\')
                 ORDER BY n.criado_em DESC LIMIT {$limite}"
            ),
        ];
    }
}
