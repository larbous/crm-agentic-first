<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\IaSaudeRepository;

/**
 * Disjuntor por provedor. FALHAS_PARA_ABRIR falhas de instabilidade seguidas dentro de JANELA_SEG abrem o disjuntor por
 * ABERTURA_SEG: nesse período as chamadas vão direto ao outro provedor, sem pagar a espera do timeout. Passado o prazo,
 * a próxima chamada testa o provedor de novo ("meio aberto"): se falhar, reabre na hora; se der certo, zera o estado.
 */
final class Disjuntor
{
    public const FALHAS_PARA_ABRIR = 3;
    public const JANELA_SEG = 300;
    public const ABERTURA_SEG = 300;

    public function __construct(private readonly IaSaudeRepository $repo = new IaSaudeRepository())
    {
    }

    public function aberto(string $provedor): bool
    {
        $s = $this->repo->obter($provedor);
        return $s !== null && $s['aberto_ate'] !== null && strtotime($s['aberto_ate']) > time();
    }

    public function registrarFalha(string $provedor, string $erro): void
    {
        $s = $this->repo->obter($provedor);
        $agora = time();
        $iso = static fn (int $t): string => date('Y-m-d H:i:s', $t);

        if ($s !== null && $s['aberto_ate'] !== null) {
            // Já abriu antes (ainda aberto ou meio aberto): a nova falha reabre.
            $this->repo->gravar($provedor, $s['falhas_seguidas'] + 1, $s['primeira_falha_em'], $iso($agora + self::ABERTURA_SEG), $erro);
            return;
        }
        $foraDaJanela = $s === null || $s['primeira_falha_em'] === null || strtotime($s['primeira_falha_em']) < $agora - self::JANELA_SEG;
        $falhas = $foraDaJanela ? 1 : $s['falhas_seguidas'] + 1;
        $primeira = $foraDaJanela ? $iso($agora) : (string) $s['primeira_falha_em'];
        $this->repo->gravar($provedor, $falhas, $primeira, $falhas >= self::FALHAS_PARA_ABRIR ? $iso($agora + self::ABERTURA_SEG) : null, $erro);
    }

    public function registrarSucesso(string $provedor): void
    {
        $s = $this->repo->obter($provedor);
        if ($s !== null && ($s['falhas_seguidas'] > 0 || $s['aberto_ate'] !== null)) {
            $this->repo->gravar($provedor, 0, null, null, null);
        }
    }
}
