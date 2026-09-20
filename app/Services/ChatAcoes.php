<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AgenteRepository;
use App\Repositories\BuscaRepository;
use App\Repositories\Repositorios;
use App\Repositories\SquadRepository;
use App\Services\AI\AgentRunner;
use App\Services\AI\ContextBuilder;
use App\Services\AI\SquadRunner;

/**
 * Processa um plano já validado (ContratoRoteador): resolve referências por busca no banco (SPEC §3.5),
 * pede desambiguação/criação/confirmação quando preciso e, só então, executa pelo ActionExecutor (origem "ia").
 * Não chama IA. Todo o estado pendente é devolvido no payload da resposta e relido do banco no clique do botão.
 *
 * $estado: plano, resolvidas [chave => id], execucao_id, tela, ultima_ref, criadas [texto], confirmado.
 */
final class ChatAcoes
{
    private const ORDEM_REFS = ['empresa', 'contato', 'negocio', 'tarefa'];
    private const ENTIDADE_DA_REF = ['empresa' => 'empresas', 'contato' => 'contatos', 'negocio' => 'negocios', 'tarefa' => 'tarefas'];
    private const ROTULO_REF = ['empresa' => 'empresa', 'contato' => 'contato', 'negocio' => 'negócio', 'tarefa' => 'tarefa'];
    /** Chave de ref que identifica o registro-alvo de cada entidade. */
    private const ALVO = ['empresas' => 'empresa', 'contatos' => 'contato', 'negocios' => 'negocio', 'tarefas' => 'tarefa'];
    /** ref → campo de vínculo ao criar. */
    private const FK = [
        'negocios'   => ['empresa' => 'empresa_id', 'contato' => 'contato_principal_id'],
        'contatos'   => ['empresa' => 'empresa_id'],
        'tarefas'    => ['empresa' => 'empresa_id', 'contato' => 'contato_id', 'negocio' => 'negocio_id'],
        'atividades' => ['empresa' => 'empresa_id', 'contato' => 'contato_id', 'negocio' => 'negocio_id'],
    ];
    /** Referências que o chat oferece criar quando não existem. */
    private const CRIAVEIS = ['empresa', 'contato'];

    public function __construct(
        private readonly ActionExecutor $executor = new ActionExecutor(),
        private readonly BuscaRepository $busca = new BuscaRepository(),
        private readonly AgenteRepository $agentes = new AgenteRepository(),
        private readonly AgentRunner $runner = new AgentRunner(),
        private readonly SquadRepository $squads = new SquadRepository(),
        private readonly SquadRunner $squadRunner = new SquadRunner(),
    ) {
    }

    /** @return array{conteudo:string,payload:array,ultima_ref:?array} */
    public function avancar(array $estado): array
    {
        Audit::definirExecucao($estado['execucao_id'] ?? null);
        try {
            return $this->processar($estado);
        } finally {
            Audit::definirExecucao(null);
        }
    }

    /** Clique numa opção de desambiguação. */
    public function escolher(array $estado, string $ref, int $id): array
    {
        $estado['resolvidas'][$ref] = $id;
        return $this->avancar($estado);
    }

    /** Clique em "Criar" para uma referência inexistente. */
    public function criarReferencia(array $estado, string $ref, string $termo): array
    {
        Audit::definirExecucao($estado['execucao_id'] ?? null);
        try {
            $entidade = self::ENTIDADE_DA_REF[$ref] ?? null;
            if (!in_array($ref, self::CRIAVEIS, true) || $entidade === null) {
                return RespostaChat::erro('Não é possível criar esta referência pelo chat.');
            }
            $dados = $ref === 'empresa' ? ['nome_fantasia' => $termo] : ['nome' => $termo];
            if ($ref === 'contato' && isset($estado['resolvidas']['empresa'])) {
                $dados['empresa_id'] = $estado['resolvidas']['empresa'];
            }
            $r = $this->executor->criar($entidade, $dados, 'ia');
            if (!$r->ok) {
                return RespostaChat::erro("Não foi possível criar {$termo}: " . $r->mensagem);
            }
            $estado['resolvidas'][$ref] = (int) $r->id;
            $estado['criadas'][] = RespostaChat::acao('criar', $entidade, $r)['conteudo'];
        } finally {
            Audit::definirExecucao(null);
        }
        return $this->avancar($estado);
    }

    // =====================================================================================

    private function processar(array $estado): array
    {
        $plano = $estado['plano'];
        $estado += ['resolvidas' => [], 'criadas' => [], 'confirmado' => false];

        switch ($plano['tipo']) {
            case 'indefinido':
                return RespostaChat::texto($plano['pergunta']);
            case 'agente':
                return $this->agente($estado);
            case 'squad':
                return $this->agente($estado, 'squad');
        }

        $acao = $plano['acao'];
        if ($acao === 'consultar') {
            return RespostaChat::consulta(ConsultaChat::executar($plano['entidade'], $plano));
        }
        if ($acao === 'desfazer') {
            $r = $this->executor->desfazer(null, 'ia');
            return $r->ok ? RespostaChat::desfeito($r->mensagem) : RespostaChat::erro($r->mensagem);
        }
        $entidade = $plano['entidade'];
        if ($entidade === 'atividades' && in_array($acao, ['atualizar', 'arquivar'], true)) {
            return RespostaChat::erro('Atividades não podem ser alteradas pelo chat. Use a timeline do registro.');
        }

        $refs = $this->refsDoPlano($estado);
        if (is_string($refs)) {
            return RespostaChat::texto($refs);
        }
        $alvo = in_array($acao, ['atualizar', 'arquivar', 'concluir', 'mover_etapa', 'converter_cliente'], true) ? self::ALVO[$entidade] : null;

        foreach (self::ORDEM_REFS as $chave) {
            if (!isset($refs[$chave]) || isset($estado['resolvidas'][$chave])) {
                continue;
            }
            $resolucao = $this->resolverReferencia($chave, $refs[$chave], $estado, $chave === $alvo);
            if (isset($resolucao['id'])) {
                $estado['resolvidas'][$chave] = $resolucao['id'];
                continue;
            }
            return $resolucao['resposta'];
        }

        // SPEC §3.5 item 3: arquivar exige confirmação (o contrato não tem ações em lote; se tiver, entram aqui).
        if ($acao === 'arquivar' && !$estado['confirmado']) {
            $registro = Repositorios::para($entidade)->encontrar((int) $estado['resolvidas'][$alvo]);
            $nome = $registro !== null ? RespostaChat::nome($registro) : '#' . $estado['resolvidas'][$alvo];
            $singular = mb_strtolower(Schema::entidade($entidade)['singular']);
            return [
                'conteudo'   => "Arquivar {$singular} \"{$nome}\"? Dá para desfazer depois.",
                'payload'    => ['tipo' => 'confirmar', 'estado' => 'aberta', 'pendente' => $estado],
                'ultima_ref' => null,
            ];
        }

        return $this->executar($estado);
    }

    /**
     * Refs relevantes para a ação, com os padrões do contexto (tela / última referência).
     * @return array<string,int|string>|string mapa chave => nome|id, ou pergunta ao operador
     */
    private function refsDoPlano(array $estado): array|string
    {
        $plano = $estado['plano'];
        $acao = $plano['acao'];
        $entidade = $plano['entidade'];
        $dadas = $plano['ref'];
        $tela = $estado['tela'] ?? null;
        $ultima = $estado['ultima_ref'] ?? null;
        $refs = [];

        $alvos = ['atualizar', 'arquivar', 'concluir', 'mover_etapa', 'converter_cliente'];
        if (in_array($acao, $alvos, true)) {
            $chave = self::ALVO[$entidade];
            $valor = $dadas[$chave] ?? null;
            if ($valor === null) {
                foreach ([$tela, $ultima] as $contexto) {
                    if ($contexto !== null && $contexto['entidade'] === $entidade) {
                        $valor = $contexto['id'];
                        break;
                    }
                }
            }
            return $valor === null
                ? 'Qual ' . self::ROTULO_REF[$chave] . '? Informe o nome ou abra o registro e repita o pedido.'
                : [$chave => $valor];
        }

        $fk = in_array($acao, ['nota', 'tarefa'], true) ? self::FK['atividades'] : (self::FK[$entidade] ?? []);
        foreach (array_keys($fk) as $chave) {
            if (isset($dadas[$chave])) {
                $refs[$chave] = $dadas[$chave];
            }
        }
        if ($tela !== null && ($chaveTela = self::ALVO[$tela['entidade']] ?? null) !== null && isset($fk[$chaveTela]) && !isset($refs[$chaveTela])) {
            // Negócio/contato: a tela preenche a empresa (ou o contato) que faltar. Tarefa/nota/atividade: só se nada foi citado.
            if (in_array($entidade, ['negocios', 'contatos'], true) || $refs === []) {
                $refs[$chaveTela] = $tela['id'];
            }
        }
        return $refs;
    }

    /**
     * @param int|string $valor id (do contexto) ou nome citado
     * @return array{id:int}|array{resposta:array}
     */
    private function resolverReferencia(string $chave, int|string $valor, array $estado, bool $ehAlvo): array
    {
        $rotulo = self::ROTULO_REF[$chave];
        $entidade = self::ENTIDADE_DA_REF[$chave];
        if (is_string($valor) && $chave === 'tarefa' && ctype_digit($valor)) {
            $valor = (int) $valor;
        }

        if (is_int($valor)) {
            return Repositorios::para($entidade)->existe($valor)
                ? ['id' => $valor]
                : ['resposta' => RespostaChat::erro("Não encontrei {$rotulo} #{$valor}.")];
        }

        $empresaId = isset($estado['resolvidas']['empresa']) ? (int) $estado['resolvidas']['empresa'] : null;
        $achados = $this->busca->resolver($chave, $valor, $empresaId);
        if ($achados === [] && $empresaId !== null && in_array($chave, ['contato', 'negocio'], true)) {
            $achados = $this->busca->resolver($chave, $valor);
        }
        $exatos = array_values(array_filter($achados, static fn (array $a): bool => $a['exato']));
        if (count($exatos) === 1) {
            return ['id' => $exatos[0]['id']];
        }
        if (count($achados) === 1) {
            return ['id' => $achados[0]['id']];
        }

        if ($achados === []) {
            if ($ehAlvo || !in_array($chave, self::CRIAVEIS, true)) {
                return ['resposta' => RespostaChat::erro("Não encontrei {$rotulo} \"{$valor}\".")];
            }
            return ['resposta' => [
                'conteudo'   => "Não encontrei {$rotulo} \"{$valor}\". Quer criar?",
                'payload'    => ['tipo' => 'criar_ref', 'estado' => 'aberta', 'ref' => $chave, 'termo' => $valor, 'pendente' => $estado],
                'ultima_ref' => null,
            ]];
        }

        $opcoes = array_map(
            static fn (array $a): array => ['id' => $a['id'], 'rotulo' => $a['titulo'], 'sub' => $a['subtitulo']],
            $achados,
        );
        return ['resposta' => [
            'conteudo'   => "Qual {$rotulo} você quer dizer com \"{$valor}\"?",
            'payload'    => ['tipo' => 'escolha', 'estado' => 'aberta', 'ref' => $chave, 'opcoes' => $opcoes, 'pendente' => $estado],
            'ultima_ref' => null,
        ]];
    }

    // ---- Agentes (@slug) -------------------------------------------------------------------

    /** Chave de ref do registro que cada agente recebe (só as entidades que o chat sabe resolver por nome). */
    private const REF_DO_AGENTE = ['empresas' => 'empresa', 'contatos' => 'contato', 'negocios' => 'negocio'];

    /**
     * Executa um agente sobre o registro citado (ou a tela aberta). O registro é resolvido pelo servidor, com os mesmos
     * botões de desambiguação das demais ações; uma chamada de IA por execução, feita pelo AgentRunner.
     * Plano: slug, alvo (refs), entrada (texto de apoio) e, no atalho "@slug texto", texto_livre = true
     * (o texto é o nome do registro ou, se nenhum registro tiver esse nome, o texto de apoio para o registro aberto).
     */
    /** "@slug" (agente, executa na hora) ou "#slug" (squad, vai para a fila do worker): mesma resolução do registro-alvo. */
    private function agente(array $estado, string $tipo = 'agente'): array
    {
        $plano = $estado['plano'];
        $sigla = $tipo === 'squad' ? '#' : '@';
        $agente = $tipo === 'squad' ? $this->squads->porSlug((string) $plano['slug']) : $this->agentes->porSlug((string) $plano['slug']);
        if ($agente === null || (int) $agente['ativo'] !== 1) {
            return RespostaChat::erro("Não encontrei o {$tipo} \"" . $plano['slug'] . '" ativo. Use ' . ($tipo === 'squad' ? '/squads' : '/agentes') . ' para ver os disponíveis.');
        }
        $def = $agente['def'];
        $entidade = (string) $def['entrada'];
        $entrada = $plano['entrada'] ?? null;
        $registroId = null;

        if ($entidade === 'nenhuma') {
            $entrada ??= $plano['texto'] ?? null;
        } else {
            $tela = $estado['tela'] ?? null;
            $ultima = $estado['ultima_ref'] ?? null;
            $chave = self::REF_DO_AGENTE[$entidade] ?? null;
            $alvo = (array) ($plano['alvo'] ?? []);
            $valor = $chave !== null ? ($alvo[$chave] ?? null) : null;
            $valor ??= $alvo !== [] ? reset($alvo) : null;
            $singular = mb_strtolower(Schema::entidade($entidade)['singular']);

            if ($chave === null) {
                // Propostas e contratos não são resolvidos por nome: aceita o id ("@agente 12") ou o botão do registro.
                if ($valor !== null && ctype_digit((string) $valor)) {
                    $registroId = (int) $valor;
                } else {
                    return RespostaChat::erro("Este {$tipo} trabalha sobre {$entidade}. Abra o registro e use o botão Agentes, ou informe o id: {$sigla}{$agente['slug']} 12.");
                }
            } elseif (isset($estado['resolvidas'][$chave])) {
                $registroId = (int) $estado['resolvidas'][$chave];
            } elseif ($valor === null) {
                foreach ([$tela, $ultima] as $contexto) {
                    if ($contexto !== null && $contexto['entidade'] === $entidade) {
                        $registroId = (int) $contexto['id'];
                        break;
                    }
                }
                if ($registroId === null) {
                    return RespostaChat::texto("Sobre qual {$singular}? Informe o nome ({$sigla}{$agente['slug']} Nome) ou abra o registro e repita.");
                }
            } elseif (is_int($valor)) {
                $registroId = $valor;
            } else {
                $achados = $this->busca->resolver($chave, (string) $valor, null);
                if ($achados !== []) {
                    $resolucao = $this->resolverReferencia($chave, (string) $valor, $estado, true);
                    if (!isset($resolucao['id'])) {
                        return $resolucao['resposta'];
                    }
                    $registroId = (int) $resolucao['id'];
                } elseif (!empty($plano['texto_livre']) && $tela !== null && $tela['entidade'] === $entidade) {
                    // Nenhum registro com esse nome: com o registro certo aberto, o texto vira apoio para o agente.
                    $registroId = (int) $tela['id'];
                    $entrada = (string) $valor;
                } else {
                    return RespostaChat::erro("Não encontrei {$singular} \"{$valor}\".");
                }
            }
        }

        $ref = $entidade !== 'nenhuma' && $registroId !== null ? ContextBuilder::ref($entidade, $registroId) : null;
        if ($tipo === 'squad') {
            try {
                $execucaoId = $this->squadRunner->enfileirar($agente, $registroId, $entrada, 'ia');
            } catch (\InvalidArgumentException $e) {
                return RespostaChat::erro($e->getMessage());
            }
            return RespostaChat::squad((string) $agente['nome'], $execucaoId, $ref);
        }
        try {
            $resultado = $this->runner->executar($agente, $registroId, $entrada);
        } catch (\InvalidArgumentException $e) {
            return RespostaChat::erro($e->getMessage());
        }
        return RespostaChat::agente((string) $agente['nome'], $resultado, $entidade !== 'nenhuma' ? $entidade : null, $registroId, $ref);
    }

    // ---- Execução ------------------------------------------------------------------------

    private function executar(array $estado): array
    {
        $plano = $estado['plano'];
        $acao = $plano['acao'];
        $entidade = $plano['entidade'];
        $res = $estado['resolvidas'];
        $ctx = [];

        $dados = $this->dadosFinais($plano, $res);
        if (is_string($dados)) {
            return RespostaChat::erro($dados);
        }

        switch ($acao) {
            case 'criar':
                $r = $this->executor->criar($entidade, $dados + $this->vinculos($entidade, $res), 'ia');
                break;
            case 'nota':
                $r = $this->executor->criar('atividades', ['tipo' => 'nota'] + $dados + $this->vinculos('atividades', $res), 'ia');
                $entidade = 'atividades';
                break;
            case 'tarefa':
                $r = $this->executor->criar('tarefas', $dados + $this->vinculos('tarefas', $res), 'ia');
                break;
            case 'atualizar':
                $r = $this->executor->atualizar($entidade, (int) $res[self::ALVO[$entidade]], $dados, 'ia');
                break;
            case 'arquivar':
                $r = $this->executor->arquivar($entidade, (int) $res[self::ALVO[$entidade]], 'ia');
                break;
            case 'concluir':
                $r = $this->executor->concluirTarefa((int) $res['tarefa'], 'ia');
                break;
            case 'mover_etapa':
                $r = $this->executor->moverEtapa((int) $res['negocio'], $dados, 'ia');
                $ctx['etapa'] = $plano['dados']['etapa'];
                if ($r->ok && isset($dados['etapa_id'])) {
                    $ctx['etapa'] = Repositorios::etapas()->encontrar((int) $dados['etapa_id'], true)['nome'] ?? $ctx['etapa'];
                }
                break;
            case 'converter_cliente':
                $r = $this->executor->converterCliente((int) $res['empresa'], 'ia');
                break;
            default:
                return RespostaChat::naoEntendi();
        }

        if (!$r->ok) {
            return RespostaChat::erro('Não foi possível ' . $this->verbo($acao) . ': ' . $r->mensagem);
        }

        $pai = in_array($acao, ['nota', 'tarefa'], true) || $entidade === 'atividades' ? $this->primeiroPai($res) : null;
        $ctx['pai'] = $pai;
        $resposta = RespostaChat::acao($acao, $entidade, $r, $ctx);
        $resposta['ultima_ref'] = match (true) {
            $acao === 'arquivar' => null,
            in_array($entidade, ['empresas', 'contatos', 'negocios'], true) => ContextBuilder::ref($entidade, (int) $r->id),
            default => $pai,
        };
        if ($estado['criadas'] !== []) {
            $resposta['conteudo'] = implode("\n", $estado['criadas']) . "\n" . $resposta['conteudo'];
        }
        return $resposta;
    }

    /**
     * Converte os "dados" do plano para campos do Schema: nomes (origem, etapa, motivo) viram ids.
     * @return array<string,mixed>|string dados ou mensagem de erro
     */
    private function dadosFinais(array $plano, array $resolvidas): array|string
    {
        $dados = $plano['dados'];

        if (isset($dados['origem'])) {
            $origem = $this->porNome(Repositorios::para('origens')->todas(), $dados['origem']);
            if ($origem === null) {
                return "Não existe a origem \"{$dados['origem']}\".";
            }
            $dados['origem_id'] = (int) $origem['id'];
            unset($dados['origem']);
        }

        if ($plano['acao'] === 'mover_etapa') {
            $negocio = Repositorios::negocios()->encontrar((int) $resolvidas['negocio']);
            $etapas = $negocio !== null ? Repositorios::etapas()->doPipeline((int) $negocio['pipeline_id']) : [];
            $etapa = $this->porNome($etapas, $dados['etapa']);
            if ($etapa === null) {
                return "Não encontrei a etapa \"{$dados['etapa']}\". Etapas: " . implode(', ', array_column($etapas, 'nome')) . '.';
            }
            $dados['etapa_id'] = (int) $etapa['id'];
            unset($dados['etapa']);
            if (isset($dados['motivo_perda'])) {
                $motivo = $this->porNome(Repositorios::para('motivos_perda')->todas(), $dados['motivo_perda']);
                if ($motivo === null) {
                    return "Não existe o motivo de perda \"{$dados['motivo_perda']}\".";
                }
                $dados['motivo_perda_id'] = (int) $motivo['id'];
                unset($dados['motivo_perda']);
            }
        }
        return $dados;
    }

    /** Linha cujo "nome" é igual ao texto (sem acento/caixa) ou, se não houver, a única que o contém. */
    private function porNome(array $linhas, string $nome): ?array
    {
        $alvo = normalizar_busca($nome);
        $contem = [];
        foreach ($linhas as $l) {
            $n = normalizar_busca((string) $l['nome']);
            if ($n === $alvo) {
                return $l;
            }
            if ($alvo !== '' && str_contains($n, $alvo)) {
                $contem[] = $l;
            }
        }
        return count($contem) === 1 ? $contem[0] : null;
    }

    /** @return array<string,int> */
    private function vinculos(string $entidade, array $resolvidas): array
    {
        $campos = [];
        foreach (self::FK[$entidade] ?? [] as $ref => $campo) {
            if (isset($resolvidas[$ref])) {
                $campos[$campo] = (int) $resolvidas[$ref];
            }
        }
        return $campos;
    }

    /** Registro em que a nota/tarefa foi ancorada (negócio > contato > empresa). */
    private function primeiroPai(array $resolvidas): ?array
    {
        foreach (['negocio' => 'negocios', 'contato' => 'contatos', 'empresa' => 'empresas'] as $ref => $entidade) {
            if (isset($resolvidas[$ref])) {
                return ContextBuilder::ref($entidade, (int) $resolvidas[$ref]);
            }
        }
        return null;
    }

    private function verbo(string $acao): string
    {
        return match ($acao) {
            'criar', 'tarefa' => 'criar',
            'nota' => 'registrar a nota',
            'atualizar' => 'atualizar',
            'arquivar' => 'arquivar',
            'concluir' => 'concluir a tarefa',
            'mover_etapa' => 'mover a etapa',
            'converter_cliente' => 'converter em cliente',
            default => 'executar a ação',
        };
    }
}
