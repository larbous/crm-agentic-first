<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Renderização de templates PHP em /app/Views, com layout e dados compartilhados. */
final class View
{
    private static array $compartilhado = [];

    public static function compartilhar(string $chave, mixed $valor): void
    {
        self::$compartilhado[$chave] = $valor;
    }

    /** Renderiza a view dentro do layout (null = sem layout). */
    public static function renderizar(string $view, array $dados = [], ?string $layout = 'layouts/app'): string
    {
        $conteudo = self::partial($view, $dados);
        if ($layout === null) {
            return $conteudo;
        }
        return self::partial($layout, array_merge($dados, ['conteudo' => $conteudo]));
    }

    public static function partial(string $view, array $dados = []): string
    {
        $arquivo = dirname(__DIR__) . '/Views/' . $view . '.php';
        if (!is_file($arquivo)) {
            throw new RuntimeException("View não encontrada: {$view}");
        }
        extract(array_merge(self::$compartilhado, $dados), EXTR_SKIP);
        ob_start();
        try {
            require $arquivo;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public static function pagina(string $view, array $dados = [], int $status = 200, ?string $layout = 'layouts/app'): Response
    {
        return Response::html(self::renderizar($view, $dados, $layout), $status);
    }
}
