<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roteador GET/POST/PUT/DELETE com parâmetros ({id}, {id:\d+}), grupos com prefixo
 * (ex.: /api) e middlewares. Um middleware é um callable(array $contexto): ?Response;
 * devolver Response interrompe o despacho.
 */
final class Router
{
    /** @var list<array{metodo:string,padrao:string,regex:string,handler:mixed,middleware:array,opcoes:array}> */
    private array $rotas = [];
    private array $prefixos = [''];
    private array $pilhaMiddleware = [[]];
    /** @var list<callable> */
    private array $globais = [];

    public function get(string $caminho, mixed $handler, array $opcoes = []): void
    {
        $this->adicionar('GET', $caminho, $handler, $opcoes);
    }

    public function post(string $caminho, mixed $handler, array $opcoes = []): void
    {
        $this->adicionar('POST', $caminho, $handler, $opcoes);
    }

    public function put(string $caminho, mixed $handler, array $opcoes = []): void
    {
        $this->adicionar('PUT', $caminho, $handler, $opcoes);
    }

    public function delete(string $caminho, mixed $handler, array $opcoes = []): void
    {
        $this->adicionar('DELETE', $caminho, $handler, $opcoes);
    }

    /** Agrupa rotas sob um prefixo e/ou middlewares. */
    public function grupo(string $prefixo, callable $definicao, array $middleware = []): void
    {
        $this->prefixos[] = end($this->prefixos) . '/' . trim($prefixo, '/');
        $this->pilhaMiddleware[] = array_merge(end($this->pilhaMiddleware), $middleware);
        try {
            $definicao($this);
        } finally {
            array_pop($this->prefixos);
            array_pop($this->pilhaMiddleware);
        }
    }

    /** Middleware executado em toda rota encontrada, antes dos middlewares da rota. */
    public function global(callable $middleware): void
    {
        $this->globais[] = $middleware;
    }

    private function adicionar(string $metodo, string $caminho, mixed $handler, array $opcoes): void
    {
        $padrao = self::normalizar(end($this->prefixos) . '/' . ltrim($caminho, '/'));
        $this->rotas[] = [
            'metodo'     => $metodo,
            'padrao'     => $padrao,
            'regex'      => self::compilar($padrao),
            'handler'    => $handler,
            'middleware' => end($this->pilhaMiddleware),
            'opcoes'     => $opcoes,
        ];
    }

    public function despachar(string $metodo, string $caminho): Response
    {
        $metodo = strtoupper($metodo);
        $caminho = self::normalizar(rawurldecode($caminho));
        $caminhoExiste = false;

        foreach ($this->rotas as $rota) {
            if (!preg_match($rota['regex'], $caminho, $m)) {
                continue;
            }
            if ($rota['metodo'] !== $metodo) {
                $caminhoExiste = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $contexto = [
                'metodo'  => $metodo,
                'caminho' => $caminho,
                'padrao'  => $rota['padrao'],
                'params'  => $params,
                'opcoes'  => $rota['opcoes'],
            ];
            foreach (array_merge($this->globais, $rota['middleware']) as $mw) {
                $resposta = $mw($contexto);
                if ($resposta instanceof Response) {
                    return $resposta;
                }
            }
            return self::converter(self::chamar($rota['handler'], $params, $rota['opcoes']));
        }

        return $caminhoExiste
            ? Response::texto('Método não permitido', 405)
            : Response::texto('Página não encontrada', 404);
    }

    private static function chamar(mixed $handler, array $params, array $opcoes): mixed
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        return $handler($params, $opcoes);
    }

    private static function converter(mixed $retorno): Response
    {
        return match (true) {
            $retorno instanceof Response => $retorno,
            is_array($retorno)           => Response::json($retorno),
            default                      => Response::html((string) $retorno),
        };
    }

    private static function normalizar(string $caminho): string
    {
        return '/' . trim(preg_replace('#/+#', '/', $caminho) ?? '', '/');
    }

    /**
     * Converte "/x/{id:\d+}/{token:[a-f0-9]{40}}" em regex. Aceita chaves aninhadas dentro do regex
     * do parâmetro (quantificadores como {40}); as demais partes literais não são escapadas.
     */
    private static function compilar(string $padrao): string
    {
        $saida = '';
        $n = strlen($padrao);
        for ($i = 0; $i < $n; $i++) {
            if ($padrao[$i] !== '{') {
                $saida .= $padrao[$i];
                continue;
            }
            $profundidade = 1;
            $j = $i + 1;
            while ($j < $n && $profundidade > 0) {
                $profundidade += $padrao[$j] === '{' ? 1 : ($padrao[$j] === '}' ? -1 : 0);
                $j++;
            }
            $miolo = substr($padrao, $i + 1, $j - $i - 2);
            [$nome, $regex] = array_pad(explode(':', $miolo, 2), 2, null);
            $saida .= '(?P<' . $nome . '>' . ($regex ?? '[^/]+') . ')';
            $i = $j - 1;
        }
        return '#^' . $saida . '$#u';
    }
}
