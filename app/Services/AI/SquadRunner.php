<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\AgenteRepository;
use App\Repositories\ExecucaoRepository;
use App\Repositories\Repositorios;
use App\Repositories\WorkerRepository;
use App\Services\ActionExecutor;
use App\Services\Audit;
use App\Services\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Executa squads (SPEC §7): etapas em sequência, cada uma um agente (AgentRunner) ou uma ação fixa do servidor.
 * Uma execução de squad é uma linha "cabeçalho" em `execucoes` (squad_id, sem squad_execucao_id) cujo `saida` guarda
 * o progresso das etapas (JSON, inclusive uma cópia das etapas da definição da hora do disparo); cada etapa de IA é
 * outra linha em `execucoes` ligada ao cabeçalho. `condicao` e `parar_se` são avaliadas pelo servidor (Condicao).
 * Etapa com ações pendentes de aprovação pausa o squad (cabeçalho `aguardando_aprovacao`); decidir todas as ações
 * devolve o cabeçalho para a fila (ExecucaoRepository::retomarSquadDaEtapa) e o worker continua da próxima etapa.
 */
final class SquadRunner
{
    public function __construct(
        private readonly AgentRunner $agentes = new AgentRunner(),
        private readonly ExecucaoRepository $execucoes = new ExecucaoRepository(),
        private readonly ActionExecutor $executor = new ActionExecutor(),
        private readonly AgenteRepository $repoAgentes = new AgenteRepository(),
        private readonly WorkerRepository $worker = new WorkerRepository(),
    ) {
    }

    /**
     * Põe o squad na fila para o worker executar. Devolve o id da execução (cabeçalho).
     * @param array $squad linha de SquadRepository (com `def`)
     * @param string $origem de quem pediu: humano, ia, sistema, agente:<slug>, formulario:<id> (usado em `origem == ...` e no loop de eventos)
     * @throws InvalidArgumentException squad desativado, registro inexistente ou de outra entidade
     */
    public function enfileirar(array $squad, ?int $registroId, ?string $texto = null, string $origem = 'humano'): int
    {
        $def = (array) $squad['def'];
        if ((int) $squad['ativo'] !== 1) {
            throw new InvalidArgumentException('O squad "' . $squad['nome'] . '" está desativado.');
        }
        $entidade = $def['entrada'] === 'nenhuma' ? null : (string) $def['entrada'];
        if ($entidade !== null) {
            if ($registroId === null || !Repositorios::para($entidade)->existe($registroId)) {
                throw new InvalidArgumentException('Registro não encontrado para o squad "' . $squad['nome'] . '" (ele trabalha sobre ' . mb_strtolower(Schema::entidade($entidade)['plural']) . ').');
            }
        } else {
            $registroId = null;
        }
        Guardrails::verificarLimite('squad', $squad);

        $etapas = [];
        foreach ($def['etapas'] as $i => $e) {
            $etapas[] = $e + [
                'agente' => null, 'acao' => null, 'dados' => [], 'condicao' => null, 'usa_saida_de' => [],
                'ordem' => $i + 1, 'estado' => 'pendente', 'execucao_id' => null, 'status' => null, 'resumo' => null, 'motivo' => null,
            ];
        }
        $estado = ['slug' => $squad['slug'], 'nome' => $squad['nome'], 'versao' => (int) $squad['versao'], 'parar_se' => $def['parar_se'] ?? null, 'parada' => null, 'etapas' => $etapas];

        return $this->execucoes->enfileirar([
            'squad_id' => (int) $squad['id'], 'entidade' => $entidade, 'registro_id' => $registroId,
            'entrada' => self::json(['origem' => $origem, 'texto' => $texto !== null && trim($texto) !== '' ? mb_substr(trim($texto), 0, 2000) : null]),
            'saida' => self::json($estado),
        ]);
    }

    /**
     * Executa (ou retoma) um cabeçalho já assumido pelo worker (status `rodando`) até terminar, pausar ou falhar.
     * Nunca lança: qualquer falha vira erro no cabeçalho.
     * @param array $cabecalho linha de `execucoes`
     */
    public function executar(array $cabecalho): void
    {
        $id = (int) $cabecalho['id'];
        $estado = (array) json_decode((string) $cabecalho['saida'], true);
        $entrada = (array) json_decode((string) $cabecalho['entrada'], true);
        $entidade = $cabecalho['entidade'] !== null ? (string) $cabecalho['entidade'] : null;
        $registroId = $cabecalho['registro_id'] !== null ? (int) $cabecalho['registro_id'] : null;
        $origem = (string) ($entrada['origem'] ?? 'humano');

        Audit::definirExecucao($id);
        try {
            $final = $this->percorrer($id, (int) $cabecalho['squad_id'], $estado, $entidade, $registroId, $origem, $entrada['texto'] ?? null);
            $this->gravar($id, $estado, $final);
        } catch (Throwable $e) {
            error_log('Squad #' . $id . ': ' . $e);
            $this->gravar($id, $estado, ['status' => 'erro', 'erro' => mb_substr('Erro inesperado: ' . $e->getMessage(), 0, 500)]);
        } finally {
            Audit::definirExecucao(null);
        }
    }

    /** @return array{status:string,erro?:string} campos finais do cabeçalho */
    private function percorrer(int $id, int $squadId, array &$estado, ?string $entidade, ?int $registroId, string $origem, ?string $texto): array
    {
        $etapas = &$estado['etapas'];

        // Retomada: a etapa que esperava aprovação foi decidida; conta como concluída e pode acionar o "parar_se".
        foreach ($etapas as $i => &$e) {
            if ($e['estado'] === 'aguardando_aprovacao') {
                $e['estado'] = 'concluida';
                if ($this->deveParar($estado, $entidade, $registroId, $origem)) {
                    $this->encerrarRestantes($estado, $i);
                    return ['status' => 'concluida'];
                }
            }
        }
        unset($e);

        foreach ($etapas as $i => &$e) {
            if ($e['estado'] !== 'pendente') {
                continue;
            }
            $cadeia = AcaoAgente::cadeia($entidade, $registroId);
            if ($e['condicao'] !== null && !Condicao::verdadeira($e['condicao'], fn (string $v) => $this->variavel($v, $origem, $cadeia, $entidade, $estado))) {
                $e['estado'] = 'pulada';
                $e['motivo'] = 'Condição não atendida: ' . $e['condicao'];
                $this->gravar($id, $estado, ['status' => 'rodando']);
                continue;
            }

            $e['estado'] = 'rodando';
            $this->gravar($id, $estado, ['status' => 'rodando']);
            $falha = $e['agente'] !== null
                ? $this->etapaDeAgente($id, $squadId, $e, $estado, $cadeia, $texto)
                : $this->etapaDeAcao($id, $e, $estado, $cadeia);
            if ($falha !== null) {
                return ['status' => 'erro', 'erro' => mb_substr('Etapa ' . $e['ordem'] . ': ' . $falha, 0, 500)];
            }
            $this->gravar($id, $estado, ['status' => 'rodando']);

            if ($e['estado'] === 'aguardando_aprovacao') {
                return ['status' => 'aguardando_aprovacao'];
            }
            if ($this->deveParar($estado, $entidade, $registroId, $origem)) {
                $this->encerrarRestantes($estado, $i);
                return ['status' => 'concluida'];
            }
        }
        return ['status' => 'concluida'];
    }

    /** Executa uma etapa de agente. Devolve a mensagem de erro (a etapa fica em `erro`) ou null. */
    private function etapaDeAgente(int $cabecalhoId, int $squadId, array &$e, array $estado, array $cadeia, ?string $texto): ?string
    {
        $agente = $this->repoAgentes->porSlug((string) $e['agente']);
        if ($agente === null || (int) $agente['ativo'] !== 1) {
            return $this->falharEtapa($e, 'o agente "' . $e['agente'] . '" não existe ou está desativado.');
        }
        $alvo = (string) $agente['def']['entrada'];
        $registro = null;
        if ($alvo !== 'nenhuma') {
            $registro = $cadeia[$alvo] ?? null;
            if ($registro === null && $alvo === 'negocios' && ($cadeia['empresas'] ?? null) !== null) {
                $registro = $this->worker->negocioAbertoDaEmpresa((int) $cadeia['empresas']);
            }
            if ($registro === null) {
                $e['estado'] = 'pulada';
                $e['motivo'] = 'Sem ' . mb_strtolower(Schema::entidade($alvo)['singular']) . ' relacionado ao registro do squad.';
                return null;
            }
        }

        try {
            $r = $this->agentes->executar($agente, $registro, $this->textoDaEtapa($e, $estado, $texto), false, [
                'squad_id' => $squadId, 'squad_execucao_id' => $cabecalhoId, 'etapa_ordem' => $e['ordem'],
            ]);
        } catch (InvalidArgumentException | IaErro $ex) {
            return $this->falharEtapa($e, $ex->getMessage());
        }
        $e['execucao_id'] = $r['execucao_id'];
        $e['status'] = $r['status'];
        $e['resumo'] = $r['resumo'] !== '' ? $r['resumo'] : null;
        if (!$r['ok']) {
            return $this->falharEtapa($e, (string) $r['erro']);
        }
        $e['estado'] = $r['pendentes'] !== [] ? 'aguardando_aprovacao' : 'concluida';
        return null;
    }

    /** Executa uma etapa de ação fixa (sem IA), com origem `agente:<slug do squad>`. */
    private function etapaDeAcao(int $cabecalhoId, array &$e, array $estado, array $cadeia): ?string
    {
        $origem = 'agente:' . $estado['slug'];

        if ($e['acao'] === 'converter_cliente') {
            $empresa = $cadeia['empresas'] ?? null;
            if ($empresa === null) {
                $e['estado'] = 'pulada';
                $e['motivo'] = 'Sem empresa relacionada ao registro do squad.';
                return null;
            }
            $r = $this->executor->converterCliente((int) $empresa, $origem);
        } else {
            $dados = (array) $e['dados'];
            $dias = $dados['vencimento_em_dias'] ?? null;
            unset($dados['vencimento_em_dias']);
            if ($dias !== null) {
                $dados['vencimento'] = date('Y-m-d', strtotime('+' . (int) $dias . ' days'));
            }
            foreach (['empresas' => 'empresa_id', 'negocios' => 'negocio_id', 'contratos' => 'contrato_id'] as $entidade => $campo) {
                if (($cadeia[$entidade] ?? null) !== null) {
                    $dados[$campo] = $cadeia[$entidade];
                }
            }
            $r = $this->executor->criar('tarefas', $dados, $origem);
        }

        if (!$r->ok) {
            return $this->falharEtapa($e, $r->mensagem);
        }
        $e['estado'] = 'concluida';
        $e['resumo'] = $r->mensagem;
        return null;
    }

    private function falharEtapa(array &$e, string $motivo): string
    {
        $e['estado'] = 'erro';
        $e['motivo'] = mb_substr($motivo, 0, 500);
        return $motivo;
    }

    /** Texto de apoio da etapa: instruções do operador + resumos das etapas de `usa_saida_de`. */
    private function textoDaEtapa(array $e, array $estado, ?string $texto): ?string
    {
        $partes = [];
        if ($texto !== null && trim($texto) !== '') {
            $partes[] = trim($texto);
        }
        $anteriores = [];
        foreach ((array) $e['usa_saida_de'] as $slug) {
            $s = $this->ultimaSaida($estado, (string) $slug);
            if ($s !== null && ($s['resumo'] ?? '') !== '') {
                $anteriores[] = "- {$slug}: {$s['resumo']}" . (($s['status'] ?? null) !== null ? " (status: {$s['status']})" : '');
            }
        }
        if ($anteriores !== []) {
            $partes[] = "Resultado das etapas anteriores do squad:\n" . implode("\n", $anteriores);
        }
        return $partes === [] ? null : mb_substr(implode("\n\n", $partes), 0, 2000);
    }

    /** Última etapa concluída (ou aguardando aprovação) do agente indicado. */
    private function ultimaSaida(array $estado, string $slug): ?array
    {
        foreach (array_reverse($estado['etapas']) as $e) {
            if ($e['agente'] === $slug && in_array($e['estado'], ['concluida', 'aguardando_aprovacao'], true)) {
                return $e;
            }
        }
        return null;
    }

    private function deveParar(array $estado, ?string $entidade, ?int $registroId, string $origem): bool
    {
        $cond = $estado['parar_se'] ?? null;
        if ($cond === null) {
            return false;
        }
        $cadeia = AcaoAgente::cadeia($entidade, $registroId);
        return Condicao::verdadeira((string) $cond, fn (string $v) => $this->variavel($v, $origem, $cadeia, $entidade, $estado));
    }

    /** Depois de um `parar_se` verdadeiro: as etapas que faltam não rodam. */
    private function encerrarRestantes(array &$estado, int $apos): void
    {
        foreach ($estado['etapas'] as $j => &$e) {
            if ($j > $apos && $e['estado'] === 'pendente') {
                $e['estado'] = 'nao_executada';
            }
        }
        $estado['parada'] = 'Parou por "parar_se": ' . $estado['parar_se'];
    }

    /**
     * Valor de uma variável de condição. `origem` (tipo de quem disparou); `<agente>.status|resumo` (saída da última
     * execução do agente no squad); `<empresa|contato|negocio|proposta|contrato>.<campo>` (lido do banco na hora, então
     * enxerga o que etapas anteriores gravaram; dinheiro em reais).
     */
    private function variavel(string $nome, string $origem, array $cadeia, ?string $entidade, array $estado): string|int|float|null
    {
        if ($nome === 'origem') {
            return strtok($origem, ':') ?: 'humano';
        }
        [$prefixo, $campo] = array_pad(explode('.', $nome, 2), 2, '');

        $plural = SquadDefinicao::ENTIDADES_DE_CONDICAO[$prefixo] ?? null;
        if ($plural !== null) {
            $registroId = $cadeia[$plural] ?? null;
            if ($registroId === null && $plural === 'negocios' && ($cadeia['empresas'] ?? null) !== null) {
                $registroId = $this->worker->negocioAbertoDaEmpresa((int) $cadeia['empresas']);
            }
            $registro = $registroId !== null ? Repositorios::para($plural)->encontrar((int) $registroId) : null;
            $valor = $registro[$campo] ?? null;
            $def = Schema::entidade($plural)['campos'][$campo] ?? null;
            if ($valor !== null && $def !== null && $def['t'] === 'money' && empty($def['pct'])) {
                return (int) $valor / 100;
            }
            return is_scalar($valor) ? $valor : null;
        }

        $saida = $this->ultimaSaida($estado, $prefixo);
        return $saida !== null && in_array($campo, ['status', 'resumo'], true) ? ($saida[$campo] ?? null) : null;
    }

    /** Grava o progresso e os campos finais no cabeçalho. Estados terminais registram `concluido_em`. */
    private function gravar(int $id, array $estado, array $campos): void
    {
        $extra = ['saida' => self::json($estado)] + $campos;
        if (in_array($campos['status'] ?? '', ['concluida', 'erro'], true)) {
            $extra['concluido_em'] = agora();
        }
        $this->execucoes->atualizar($id, $extra);
    }

    private static function json(array $dados): string
    {
        return json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
