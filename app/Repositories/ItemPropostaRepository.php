<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

/**
 * Itens de proposta (tabela proposta_itens). São parte da proposta: não têm soft delete e são regravados
 * junto com ela (a auditoria da proposta guarda o antes/depois dos itens).
 */
final class ItemPropostaRepository
{
    public function porProposta(int $propostaId): array
    {
        $st = DB::conexao()->prepare('SELECT * FROM proposta_itens WHERE proposta_id = :p ORDER BY ordem ASC, id ASC');
        $st->execute(['p' => $propostaId]);
        return $st->fetchAll();
    }

    /** Substitui todos os itens da proposta. $itens: linhas já validadas e com total calculado. */
    public function substituir(int $propostaId, array $itens): void
    {
        $pdo = DB::conexao();
        $pdo->prepare('DELETE FROM proposta_itens WHERE proposta_id = :p')->execute(['p' => $propostaId]);
        $ins = $pdo->prepare(
            'INSERT INTO proposta_itens (proposta_id, servico_id, descricao, quantidade, unidade, valor_unitario, desconto, total, recorrente, ordem, criado_em, atualizado_em)
             VALUES (:proposta_id, :servico_id, :descricao, :quantidade, :unidade, :valor_unitario, :desconto, :total, :recorrente, :ordem, :agora, :agora)'
        );
        foreach (array_values($itens) as $ordem => $i) {
            $ins->execute([
                'proposta_id'    => $propostaId,
                'servico_id'     => $i['servico_id'] ?? null,
                'descricao'      => $i['descricao'],
                'quantidade'     => $i['quantidade'],
                'unidade'        => $i['unidade'] ?? null,
                'valor_unitario' => $i['valor_unitario'],
                'desconto'       => $i['desconto'],
                'total'          => $i['total'],
                'recorrente'     => $i['recorrente'],
                'ordem'          => $ordem,
                'agora'          => agora(),
            ]);
        }
    }
}
