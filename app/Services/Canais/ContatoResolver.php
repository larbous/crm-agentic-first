<?php

declare(strict_types=1);

namespace App\Services\Canais;

use App\Repositories\Repositorios;

/** Descobre a quem pertence um remetente: contato (e a empresa dele) ou só a empresa. Instagram não casa sozinho (só há o id do usuário). */
final class ContatoResolver
{
    /** @return array{contato_id:?int,empresa_id:?int} */
    public static function resolver(string $canal, string $identificador): array
    {
        $vazio = ['contato_id' => null, 'empresa_id' => null];
        if ($canal === 'whatsapp') {
            foreach (Canais::variantesTelefone($identificador) as $numero) {
                $contato = Repositorios::contatos()->porTelefone($numero);
                if ($contato !== null) {
                    return ['contato_id' => (int) $contato['id'], 'empresa_id' => $contato['empresa_id'] !== null ? (int) $contato['empresa_id'] : null];
                }
            }
            foreach (Canais::variantesTelefone($identificador) as $numero) {
                $empresa = Repositorios::empresas()->porTelefone($numero);
                if ($empresa !== null) {
                    return ['contato_id' => null, 'empresa_id' => (int) $empresa['id']];
                }
            }
            return $vazio;
        }
        if ($canal === 'email') {
            $contato = Repositorios::contatos()->porEmail($identificador);
            if ($contato !== null) {
                return ['contato_id' => (int) $contato['id'], 'empresa_id' => $contato['empresa_id'] !== null ? (int) $contato['empresa_id'] : null];
            }
            $empresa = Repositorios::empresas()->porEmailGeral($identificador);
            return $empresa !== null ? ['contato_id' => null, 'empresa_id' => (int) $empresa['id']] : $vazio;
        }
        return $vazio;
    }
}
