<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Validação por regras textuais: required|email|min:3|max:80|integer|numeric|in:a,b|date|regex:/../.
 * Campos ausentes/vazios só falham em "required"; demais regras ignoram vazio.
 */
final class Validator
{
    private array $erros = [];
    private array $validados = [];

    /**
     * @param array<string,string|list<string>> $regras
     * @param array<string,string> $rotulos nome legível por campo (padrão: o próprio nome)
     */
    public function __construct(array $dados, array $regras, array $rotulos = [])
    {
        foreach ($regras as $campo => $definicao) {
            $lista = is_array($definicao) ? $definicao : explode('|', $definicao);
            $valor = $dados[$campo] ?? null;
            $rotulo = $rotulos[$campo] ?? $campo;
            $vazio = $valor === null || (is_string($valor) && trim($valor) === '');

            foreach ($lista as $regra) {
                [$nome, $arg] = array_pad(explode(':', $regra, 2), 2, null);
                if ($nome === 'required') {
                    if ($vazio) {
                        $this->erros[$campo] = "O campo {$rotulo} é obrigatório.";
                        break;
                    }
                    continue;
                }
                if ($vazio) {
                    continue;
                }
                $erro = $this->aplicar($nome, $arg, (string) $valor, $rotulo);
                if ($erro !== null) {
                    $this->erros[$campo] = $erro;
                    break;
                }
            }
            if (!isset($this->erros[$campo])) {
                $this->validados[$campo] = is_string($valor) ? trim($valor) : $valor;
            }
        }
    }

    private function aplicar(string $regra, ?string $arg, string $valor, string $rotulo): ?string
    {
        return match ($regra) {
            'email'   => filter_var($valor, FILTER_VALIDATE_EMAIL) ? null : "O campo {$rotulo} deve ser um e-mail válido.",
            'min'     => mb_strlen($valor) >= (int) $arg ? null : "O campo {$rotulo} deve ter ao menos {$arg} caracteres.",
            'max'     => mb_strlen($valor) <= (int) $arg ? null : "O campo {$rotulo} deve ter no máximo {$arg} caracteres.",
            'integer' => preg_match('/^-?\d+$/', $valor) ? null : "O campo {$rotulo} deve ser um número inteiro.",
            'numeric' => is_numeric($valor) ? null : "O campo {$rotulo} deve ser numérico.",
            'in'      => in_array($valor, explode(',', (string) $arg), true) ? null : "O campo {$rotulo} tem um valor inválido.",
            'date'    => $this->dataValida($valor) ? null : "O campo {$rotulo} deve ser uma data válida (AAAA-MM-DD).",
            'regex'   => preg_match((string) $arg, $valor) === 1 ? null : "O campo {$rotulo} tem formato inválido.",
            default   => throw new InvalidArgumentException("Regra de validação desconhecida: {$regra}"),
        };
    }

    private function dataValida(string $valor): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        return $d !== false && $d->format('Y-m-d') === $valor;
    }

    public function passa(): bool
    {
        return $this->erros === [];
    }

    public function falha(): bool
    {
        return $this->erros !== [];
    }

    /** @return array<string,string> */
    public function erros(): array
    {
        return $this->erros;
    }

    /** Somente os campos válidos, com strings aparadas. */
    public function validados(): array
    {
        return $this->validados;
    }
}
