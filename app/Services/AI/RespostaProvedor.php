<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Resultado de uma tentativa num provedor, já normalizado. `status` 0 = falha de rede/timeout; 200 = resposta lida.
 * `tecnico` é para log/execuções (nunca contém a chave); `paraOperador` só é usado quando a chamada inteira falha.
 */
final class RespostaProvedor
{
    public function __construct(
        public readonly int $status,
        public readonly string $texto = '',
        public readonly int $tokensEntrada = 0,
        public readonly int $tokensSaida = 0,
        public readonly ?string $parada = null,
        public readonly string $tecnico = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->status === 200;
    }

    /**
     * Créditos do provedor esgotados: 402 (Gemini/AI Studio), ou 400/429 com a mensagem de saldo ("credit balance is too low" da
     * Anthropic, "prepayment credits are depleted" do Gemini). Diferente de um limite de uso passageiro: repetir não adianta.
     */
    public function semCredito(): bool
    {
        return $this->status === 402
            || (in_array($this->status, [400, 429], true) && preg_match('/credit balance|prepayment credits|credits? (?:are |is )?(?:depleted|exhausted)|billing/i', $this->tecnico) === 1);
    }

    /** Falha que justifica tentar outro provedor: rede, timeout, créditos esgotados, limite de uso (429), sobrecarga (529) e 5xx. */
    public function instavel(): bool
    {
        return $this->status === 0 || $this->semCredito() || $this->status === 429 || $this->status === 529 || $this->status >= 500;
    }

    /** Vale uma nova tentativa no mesmo provedor (timeout e falta de crédito não: dobrariam a espera à toa). */
    public function repetivel(): bool
    {
        return !$this->semCredito() && ($this->status === 429 || $this->status === 529 || $this->status >= 500);
    }

    /** Mensagem segura para o operador quando a chamada falha de vez. */
    public function paraOperador(string $provedor): string
    {
        return match (true) {
            $this->semCredito() => "Os créditos da IA ({$provedor}) acabaram. Recarregue no painel do provedor.",
            $this->status === 0 => 'A IA não respondeu a tempo. Tente novamente ou use um comando com / (digite /ajuda).',
            in_array($this->status, [401, 403], true) => "A chave da API da IA ({$provedor}) foi recusada. Confira a chave em config.local.php.",
            $this->status === 429 => 'O limite de uso da IA foi atingido. Tente novamente em instantes.',
            default => 'A IA está indisponível no momento. Tente novamente ou use um comando com / (digite /ajuda).',
        };
    }
}
