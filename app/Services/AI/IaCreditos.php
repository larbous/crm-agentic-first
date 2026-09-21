<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\ConfiguracaoRepository;

/**
 * Créditos esgotados por provedor (Fase 15). O `Client` marca o provedor quando ele responde "sem crédito" e desmarca no
 * primeiro sucesso. Enquanto marcado, as tarefas que dependem só dele (a transcrição de áudios, que é do Gemini) ficam
 * suspensas e o layout mostra um alerta no topo. Passada a janela de espera, uma nova tentativa serve de sondagem: se o
 * provedor voltou, o estado se limpa sozinho; se não, a janela recomeça. O operador também pode limpar à mão ("já recarreguei").
 * O estado fica em `configuracoes` (`ia.sem_credito.<provedor>`, JSON com `desde` e `ate`).
 */
final class IaCreditos
{
    /** Minutos sem tentar de novo o provedor sem crédito. */
    public const ESPERA_MIN = 30;

    public const ROTULOS = ['gemini' => 'Google Gemini', 'anthropic' => 'Anthropic'];

    public static function marcar(string $provedor): void
    {
        $config = new ConfiguracaoRepository();
        $atual = self::estado($provedor);
        $config->definir(self::chave($provedor), json_encode([
            'desde' => $atual['desde'] ?? agora(),
            'ate' => date('Y-m-d H:i:s', time() + self::ESPERA_MIN * 60),
        ], JSON_THROW_ON_ERROR));
    }

    public static function limpar(string $provedor): void
    {
        if (self::estado($provedor) !== null) {
            (new ConfiguracaoRepository())->definir(self::chave($provedor), null);
        }
    }

    /** Sem crédito e ainda dentro da janela de espera: não vale chamar. */
    public static function bloqueado(string $provedor): bool
    {
        $e = self::estado($provedor);
        return $e !== null && strtotime((string) $e['ate']) > time();
    }

    /**
     * Provedores marcados como sem crédito (inclusive os que já podem ser sondados de novo), para o alerta do topo.
     * @return list<array{provedor:string,rotulo:string,desde:string}>
     */
    public static function alertas(): array
    {
        $saida = [];
        foreach (self::ROTULOS as $provedor => $rotulo) {
            $e = self::estado($provedor);
            if ($e !== null) {
                $saida[] = ['provedor' => $provedor, 'rotulo' => $rotulo, 'desde' => (string) $e['desde']];
            }
        }
        return $saida;
    }

    /** @return array{desde:string,ate:string}|null */
    private static function estado(string $provedor): ?array
    {
        $bruto = (new ConfiguracaoRepository())->obter(self::chave($provedor));
        $dados = $bruto !== null && $bruto !== '' ? json_decode($bruto, true) : null;
        return is_array($dados) && isset($dados['desde'], $dados['ate']) ? $dados : null;
    }

    private static function chave(string $provedor): string
    {
        return 'ia.sem_credito.' . $provedor;
    }
}
