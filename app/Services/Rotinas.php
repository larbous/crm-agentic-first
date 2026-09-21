<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgenteRepository;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ConversaRepository;
use App\Repositories\FormularioRepository;
use App\Repositories\MensagemRepository;
use App\Repositories\PesquisaRepository;
use App\Repositories\WorkerRepository;
use App\Services\AI\Client;
use App\Services\AI\IaCreditos;
use App\Services\AI\IaErro;
use App\Services\AI\ResumidorConversa;
use App\Services\AI\Transcritor;
use DateTimeImmutable;
use Throwable;

/**
 * Rotinas de manutenção do worker (SPEC §11, itens 3 e 4): próximas ocorrências de tarefas recorrentes, tarefas vencidas,
 * propostas expiradas e contratos vencendo/vencidos. Cada aviso é emitido uma única vez (`worker_marcas`); mudanças de
 * estado passam pelo ActionExecutor com origem "sistema"; eventos disparam ao final de cada mudança/aviso.
 */
final class Rotinas
{
    private const LIMITE = 100;

    public function __construct(
        private readonly WorkerRepository $repo = new WorkerRepository(),
        private readonly ActionExecutor $executor = new ActionExecutor(),
    ) {
    }

    /** @return array<string,int> quantas ocorrências de cada tipo foram tratadas nesta rodada */
    public function executar(): array
    {
        return [
            'tarefas_recorrentes' => $this->tarefasRecorrentes(),
            'tarefas_vencidas'    => $this->tarefasVencidas(),
            'propostas_expiradas' => $this->propostasExpiradas(),
            'contratos_vencendo'  => $this->contratosVencendo(),
            'contratos_vencidos'  => $this->contratosVencidos(),
            'pesquisas_criadas'   => $this->pesquisasAutomaticas(),
            'pesquisas_expiradas' => $this->pesquisasExpiradas(),
            'emails_recebidos'    => \App\Services\Canais\Email::coletar($this->executor),
            'audios_transcritos'  => $this->transcreverAudios(),
            'conversas_resumidas' => $this->resumirConversas(),
            'followups'           => $this->followups(),
        ];
    }

    /** Cada rotina de IA desta fase para de abrir chamadas novas depois deste tempo (o worker tem 240 s por rodada, com a fila de agentes ainda por rodar). */
    private const ORCAMENTO_IA_SEGUNDOS = 90;
    private const LIMITE_AUDIOS = 3;
    private const LIMITE_RESUMOS = 3;
    private const LIMITE_FOLLOWUPS = 5;
    /** Silêncio (dias sem interação) que dispara cada passo da cadência de follow-up; `followup.cadencia_dias` em `configuracoes` sobrescreve. */
    public const CADENCIA_PADRAO = '3,7,14';
    public const AGENTE_FOLLOWUP = 'redator-followup';
    /** Negócio parado além do último passo da cadência mais isto é tratado como abandonado (ativar a cadência não ressuscita negócios antigos). */
    private const ABANDONO_DIAS = 30;

    /**
     * Transcreve os áudios recebidos (Gemini). Sem Gemini configurado ou com o crédito esgotado, nada é tentado e os áudios
     * continuam na fila: voltam a ser processados sozinhos quando o crédito volta (o alerta no topo das telas avisa o operador).
     * Uma falta de crédito descoberta no meio da rodada não gasta a tentativa do áudio.
     */
    private function transcreverAudios(): int
    {
        if (!Transcritor::disponivel()) {
            return 0;
        }
        $n = 0;
        $inicio = time();
        $transcritor = new Transcritor();
        foreach ((new MensagemRepository())->paraTranscrever(self::LIMITE_AUDIOS) as $m) {
            if (time() - $inicio >= self::ORCAMENTO_IA_SEGUNDOS || !Transcritor::disponivel()) {
                break;
            }
            try {
                $r = $transcritor->transcrever($m);
            } catch (Throwable $e) {
                error_log('Worker, transcrição da mensagem #' . $m['id'] . ': ' . $e);
                $r = ['ok' => false, 'erro' => 'Erro inesperado: ' . $e->getMessage(), 'definitivo' => false];
            }
            $semCredito = !$r['ok'] && IaCreditos::bloqueado('gemini');
            if ($this->executor->registrarTranscricao((int) $m['id'], $r, !$semCredito)->ok && $r['ok']) {
                $n++;
            }
        }
        return $n;
    }

    /** Resume as conversas resolvidas (nota na timeline e texto na própria conversa). Sem IA configurada, a fila espera. */
    private function resumirConversas(): int
    {
        $client = new Client();
        if (!$client->configurado()) {
            return 0;
        }
        $n = 0;
        $inicio = time();
        $resumidor = new ResumidorConversa($client);
        foreach ((new ConversaRepository())->paraResumir(self::LIMITE_RESUMOS) as $c) {
            if (time() - $inicio >= self::ORCAMENTO_IA_SEGUNDOS) {
                break;
            }
            try {
                $r = $this->executor->registrarResumoConversa((int) $c['id'], $resumidor->resumir($c));
                $n += $r->ok ? 1 : 0;
            } catch (IaErro $e) {
                $this->executor->adiarResumoConversa((int) $c['id']);
                error_log('Worker, resumo da conversa #' . $c['id'] . ': ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Cadência de follow-up: negócios abertos sem interação real há 3, 7 e 14 dias (configurável) ganham, uma vez por passo, uma
     * execução do agente "redator-followup", que deixa o rascunho da mensagem como nota no negócio para o operador revisar e
     * enviar pelo canal (a caixa de entrada). Se o último contato foi do cliente, a bola está com o operador: não se acompanha. Negócios parados há mais de 30 dias além do último passo são tidos como abandonados.
     * Só roda com o agente ativo (desativá-lo desliga a cadência). Ao pular direto para um passo maior, os menores são dados
     * como cumpridos, para não gerar rajada. O teto por hora dos agentes (`ia.limite_hora`) continua valendo.
     */
    private function followups(): int
    {
        $agente = (new AgenteRepository())->porSlug(self::AGENTE_FOLLOWUP);
        if ($agente === null || (int) $agente['ativo'] !== 1) {
            return 0;
        }
        $passos = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (new ConfiguracaoRepository())->obter('followup.cadencia_dias') ?: self::CADENCIA_PADRAO)),
            static fn (int $d): bool => $d > 0,
        )));
        sort($passos);
        if ($passos === []) {
            return 0;
        }

        $n = 0;
        $corte = date('Y-m-d H:i:s', strtotime('-' . $passos[0] . ' days'));
        foreach ($this->repo->negociosSemInteracao($corte, 100) as $neg) {
            if ($n >= self::LIMITE_FOLLOWUPS) {
                break;
            }
            if ($neg['ultima_direcao'] === 'entrada') {
                continue;
            }
            $dias = (int) dias_entre(substr((string) $neg['ultima'], 0, 10), hoje());
            $alcancados = array_values(array_filter($passos, static fn (int $p): bool => $dias >= $p));
            if ($alcancados === [] || $dias > max($passos) + self::ABANDONO_DIAS) {
                continue;
            }
            $passo = max($alcancados);
            $marca = static fn (int $p): string => "followup:{$neg['id']}:{$p}:{$neg['ultima']}";
            if ($this->repo->marcada($marca($passo))) {
                continue; // este passo já foi feito para este silêncio
            }
            if (Gatilhos::enfileirar('agente', $agente, (int) $neg['id'], null, 'sistema', 'followup:' . $passo . 'd') === null) {
                break; // teto por hora atingido (ou já na fila): tenta de novo nas próximas rodadas, sem perder o passo
            }
            foreach ($alcancados as $p) {
                $this->repo->marcar($marca($p));
            }
            $n++;
        }
        return $n;
    }

    /** Contratos assinados há pelo menos N dias entram na pesquisa; só os dos últimos N + 30 dias (ativar o gatilho não dispara para contratos antigos). */
    private const JANELA_CONTRATOS_DIAS = 30;
    private const LIMITE_PESQUISAS = 50;

    /**
     * Cria as pesquisas dos formulários com gatilho: `contrato_assinado` (N dias depois da assinatura, uma por contrato) e
     * `periodica` (clientes ativos há pelo menos N dias e sem pesquisa nos últimos N). Cada uma abre uma tarefa para o operador
     * entregar o link, até 50 por rodada.
     */
    private function pesquisasAutomaticas(): int
    {
        $n = 0;
        $pesquisas = new PesquisaRepository();
        foreach ((new FormularioRepository())->pesquisasAutomaticas() as $f) {
            $dias = (int) $f['gatilho_dias'];
            if ($f['gatilho_tipo'] === 'contrato_assinado') {
                $ate = date('Y-m-d', strtotime("-{$dias} days"));
                $de = date('Y-m-d', strtotime('-' . ($dias + self::JANELA_CONTRATOS_DIAS) . ' days'));
                foreach ($pesquisas->contratosParaPesquisar((int) $f['id'], $de, $ate, self::LIMITE_PESQUISAS - $n) as $c) {
                    $n += $this->executor->criarPesquisa($f, (int) $c['empresa_id'], $c['contato_id'] !== null ? (int) $c['contato_id'] : null, (int) $c['id'], 'contrato_assinado', 'sistema')->ok ? 1 : 0;
                }
            } else {
                $desde = date('Y-m-d H:i:s', strtotime("-{$dias} days"));
                $clienteAte = date('Y-m-d', strtotime("-{$dias} days"));
                foreach ($pesquisas->clientesParaPesquisar((int) $f['id'], $desde, $clienteAte, self::LIMITE_PESQUISAS - $n) as $e) {
                    $n += $this->executor->criarPesquisa($f, (int) $e['id'], null, null, 'periodica', 'sistema')->ok ? 1 : 0;
                }
            }
            if ($n >= self::LIMITE_PESQUISAS) {
                break;
            }
        }
        return $n;
    }

    /** Link não respondido dentro do prazo → expirada. */
    private function pesquisasExpiradas(): int
    {
        $n = 0;
        foreach ((new PesquisaRepository())->vencidas(agora(), self::LIMITE) as $id) {
            $n += $this->executor->expirarPesquisa($id)->ok ? 1 : 0;
        }
        return $n;
    }

    /**
     * Tarefa recorrente concluída → cria a próxima (mesmos dados, novo vencimento). Se a tarefa foi concluída muito
     * depois do vencimento, a próxima é a primeira data futura da série (não acumula um atraso de várias ocorrências).
     */
    private function tarefasRecorrentes(): int
    {
        $n = 0;
        foreach ($this->repo->tarefasRecorrentesConcluidas(self::LIMITE) as $t) {
            if (!$this->repo->marcar('tarefa.proxima:' . $t['id'])) {
                continue;
            }
            $base = substr((string) ($t['vencimento'] ?? $t['concluida_em'] ?? agora()), 0, 10);
            $proxima = self::proximaOcorrencia($base, (string) $t['recorrencia'], hoje());
            $hora = strlen((string) $t['vencimento']) > 10 ? substr((string) $t['vencimento'], 10) : '';

            $dados = ['vencimento' => $proxima . $hora, 'status' => 'pendente'];
            foreach (['titulo', 'descricao', 'tipo', 'prioridade', 'recorrencia', 'empresa_id', 'contato_id', 'negocio_id', 'contrato_id'] as $campo) {
                if ($t[$campo] !== null) {
                    $dados[$campo] = $t[$campo];
                }
            }
            $r = $this->executor->criar('tarefas', $dados, 'sistema');
            if ($r->ok) {
                $n++;
            } else {
                error_log("Worker: tarefa recorrente #{$t['id']} não gerou a próxima: {$r->mensagem}");
            }
        }
        return $n;
    }

    private function tarefasVencidas(): int
    {
        $n = 0;
        foreach ($this->repo->tarefasVencidas(hoje(), agora(), self::LIMITE) as $t) {
            if ($this->repo->marcar('tarefa.vencida:' . $t['id'] . ':' . $t['vencimento'])) {
                Events::disparar('tarefa.vencida', ['entidade' => 'tarefas', 'id' => (int) $t['id'], 'origem' => 'sistema', 'registro' => $t]);
                $n++;
            }
        }
        return $n;
    }

    private function propostasExpiradas(): int
    {
        $n = 0;
        foreach ($this->repo->propostasExpiradas(hoje(), self::LIMITE) as $p) {
            if ($this->executor->expirarProposta((int) $p['id'])->ok) {
                $n++;
            }
        }
        return $n;
    }

    private function contratosVencendo(): int
    {
        $n = 0;
        foreach ($this->repo->contratosVencendo(hoje(), self::LIMITE) as $c) {
            if ($this->repo->marcar('contrato.vencendo:' . $c['id'] . ':' . $c['data_fim'])) {
                Events::disparar('contrato.vencendo', [
                    'entidade' => 'contratos', 'id' => (int) $c['id'], 'origem' => 'sistema', 'registro' => $c, 'dias' => dias_entre(hoje(), $c['data_fim']),
                ]);
                $n++;
            }
        }
        return $n;
    }

    private function contratosVencidos(): int
    {
        $n = 0;
        foreach ($this->repo->contratosVencidos(hoje(), self::LIMITE) as $c) {
            if ($this->executor->vencerContrato((int) $c['id'])->ok) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Primeira data da série de recorrência estritamente depois de $base e não anterior a $minimo (AAAA-MM-DD).
     * Mensal e anual preservam o dia quando possível e usam o último dia do mês quando ele não existe (31/01 → 28/02).
     */
    public static function proximaOcorrencia(string $base, string $recorrencia, string $minimo): string
    {
        $data = new DateTimeImmutable($base);
        $passo = static function (DateTimeImmutable $d, int $i) use ($base, $recorrencia): DateTimeImmutable {
            $origem = new DateTimeImmutable($base);
            return match ($recorrencia) {
                'diaria' => $origem->modify("+{$i} days"),
                'semanal' => $origem->modify('+' . (7 * $i) . ' days'),
                'mensal', 'anual' => self::somarMeses($origem, $recorrencia === 'mensal' ? $i : 12 * $i),
                default => $d,
            };
        };
        for ($i = 1; $i <= 2000; $i++) {
            $candidata = $passo($data, $i);
            if ($candidata->format('Y-m-d') >= $minimo) {
                return $candidata->format('Y-m-d');
            }
        }
        return $minimo;
    }

    private static function somarMeses(DateTimeImmutable $d, int $meses): DateTimeImmutable
    {
        $dia = (int) $d->format('j');
        $primeiro = $d->modify('first day of this month')->modify("+{$meses} months");
        return $primeiro->setDate((int) $primeiro->format('Y'), (int) $primeiro->format('n'), min($dia, (int) $primeiro->format('t')));
    }
}
