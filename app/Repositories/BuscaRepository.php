<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/** Busca global (empresas, contatos, negócios) sem acento/caixa. */
final class BuscaRepository
{
    /**
     * Resolução de referências do chat: registros cujo nome/e-mail/telefone contém o termo (sem acento/caixa),
     * do tipo empresa | contato | negocio | tarefa. $empresaId restringe contatos e negócios a uma empresa.
     * Cada linha traz "exato" (nome idêntico ao termo) para o servidor preferir o match perfeito.
     * @return list<array{id:int,titulo:string,subtitulo:string,exato:bool}>
     */
    public function resolver(string $tipo, string $termo, ?int $empresaId = null, int $limite = 6): array
    {
        $norm = normalizar_busca(trim($termo));
        if ($norm === '') {
            return [];
        }
        $params = ['q' => '%' . addcslashes($norm, '%_\\') . '%'];
        $porEmpresa = '';
        if ($empresaId !== null && in_array($tipo, ['contato', 'negocio'], true)) {
            $porEmpresa = $tipo === 'contato' ? ' AND c.empresa_id = :emp' : ' AND n.empresa_id = :emp';
            $params['emp'] = $empresaId;
        }
        $limite = max(1, min(20, $limite));

        $sql = match ($tipo) {
            'empresa' => "SELECT id, nome_fantasia AS titulo, COALESCE(NULLIF(cidade, ''), segmento, '') AS subtitulo
                          FROM empresas WHERE arquivado_em IS NULL AND (
                              busca_norm(nome_fantasia) LIKE :q ESCAPE '\\' OR busca_norm(razao_social) LIKE :q ESCAPE '\\'
                              OR busca_norm(email_geral) LIKE :q ESCAPE '\\' OR busca_norm(telefone) LIKE :q ESCAPE '\\'
                              OR busca_norm(whatsapp) LIKE :q ESCAPE '\\')
                          ORDER BY nome_fantasia COLLATE pt_br LIMIT {$limite}",
            'contato' => "SELECT c.id, TRIM(c.nome || ' ' || COALESCE(c.sobrenome, '')) AS titulo, COALESCE(e.nome_fantasia, c.cargo, '') AS subtitulo
                          FROM contatos c LEFT JOIN empresas e ON e.id = c.empresa_id
                          WHERE c.arquivado_em IS NULL{$porEmpresa} AND (
                              busca_norm(c.nome || ' ' || COALESCE(c.sobrenome, '')) LIKE :q ESCAPE '\\' OR busca_norm(c.apelido) LIKE :q ESCAPE '\\'
                              OR busca_norm(c.email) LIKE :q ESCAPE '\\' OR busca_norm(c.telefone) LIKE :q ESCAPE '\\'
                              OR busca_norm(c.whatsapp) LIKE :q ESCAPE '\\')
                          ORDER BY c.nome COLLATE pt_br LIMIT {$limite}",
            'negocio' => "SELECT n.id, n.titulo AS titulo, TRIM(COALESCE(n.codigo, '') || ' · ' || COALESCE(e.nome_fantasia, '')) AS subtitulo
                          FROM negocios n LEFT JOIN empresas e ON e.id = n.empresa_id
                          WHERE n.arquivado_em IS NULL{$porEmpresa} AND (
                              busca_norm(n.titulo) LIKE :q ESCAPE '\\' OR busca_norm(n.codigo) LIKE :q ESCAPE '\\'
                              OR busca_norm(e.nome_fantasia) LIKE :q ESCAPE '\\')
                          ORDER BY n.criado_em DESC LIMIT {$limite}",
            'tarefa'  => "SELECT id, titulo, COALESCE(vencimento, '') AS subtitulo
                          FROM tarefas WHERE arquivado_em IS NULL AND status IN ('pendente', 'andamento')
                              AND busca_norm(titulo) LIKE :q ESCAPE '\\'
                          ORDER BY vencimento IS NULL, vencimento ASC LIMIT {$limite}",
            default   => throw new \InvalidArgumentException("Tipo de referência inválido: {$tipo}"),
        };

        $st = DB::conexao()->prepare($sql);
        $st->execute($params);
        $linhas = [];
        foreach ($st->fetchAll() as $l) {
            $linhas[] = [
                'id' => (int) $l['id'], 'titulo' => (string) $l['titulo'], 'subtitulo' => (string) $l['subtitulo'],
                'exato' => normalizar_busca((string) $l['titulo']) === $norm,
            ];
        }
        return $linhas;
    }

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
