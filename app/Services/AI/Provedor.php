<?php

declare(strict_types=1);

namespace App\Services\AI;

/** Um provedor de LLM. Só o `Client` os usa; o resto do sistema continua falando com `Client::chamar`. */
interface Provedor
{
    /** Identificador estável ('anthropic', 'gemini'): vai para `execucoes.provedor` e para o disjuntor. */
    public function nome(): string;

    /** Há credencial (ou transporte de teste) para usar este provedor. */
    public function configurado(): bool;

    /** Ferramenta de busca na web (só a Anthropic tem hoje; o Gemini fica de fora nessas chamadas). */
    public function suportaBuscaWeb(): bool;

    /** Aceita áudio como anexo da requisição (só o Gemini hoje): usado na transcrição de áudios da caixa de entrada. */
    public function suportaAudio(): bool;

    /** Modelo deste provedor equivalente ao pedido (que vem do agente/configuração, em id Claude). */
    public function modeloPara(string $modeloPedido): string;

    /** Faz uma tentativa. Nunca lança por falha de rede ou HTTP: devolve o status. */
    public function enviar(RequisicaoIA $req): RespostaProvedor;
}
