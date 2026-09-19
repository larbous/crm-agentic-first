<?php

declare(strict_types=1);

namespace App\Core;

/** Acesso à configuração carregada em config.php por chave com pontos ("db.caminho"). */
final class Config
{
    private static array $dados = [];

    public static function definir(array $dados): void
    {
        self::$dados = $dados;
    }

    public static function obter(string $chave, mixed $padrao = null): mixed
    {
        $atual = self::$dados;
        foreach (explode('.', $chave) as $parte) {
            if (!is_array($atual) || !array_key_exists($parte, $atual)) {
                return $padrao;
            }
            $atual = $atual[$parte];
        }
        return $atual;
    }
}
