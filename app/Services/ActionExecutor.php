<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;
use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * Ponto único de escrita de dados (humano, chat, agente, formulário): valida contra o Schema (whitelist),
 * aplica as regras de negócio, grava, registra em log_auditoria e dispara eventos (depois do commit).
 * Nenhuma outra classe escreve em tabelas de negócio.
 *
 * Peça central do CRM Lárbous (github.com/larbous/crm-agentic-first) — licença MIT.
 */
final class ActionExecutor
{
    use AcoesDocumentos;
    use AcoesAgentes;
    use AcoesSquads;
    use AcoesWorker;
    use AcoesFormularios;
    use AcoesPesquisas;
    use AcoesCaixa;
    use AcoesFinanceiro;
    use AcoesIntegracao;

    private const PADROES = [
        'empresas' => ['status' => 'lead'],
        'contatos' => ['status' => 'ativo'],
        'tarefas'  => ['tipo' => 'outro', 'prioridade' => 'media', 'status' => 'pendente', 'recorrencia' => 'nenhuma'],
        'etapas'   => ['tipo' => 'aberta', 'probabilidade_padrao' => 0],
        'chamados' => ['prioridade' => 'media', 'status' => 'aberto'],
        'servicos' => ['categoria' => 'outro', 'unidade' => 'projeto'],
        'propostas' => ['desconto_tipo' => 'valor'],
        'contratos' => ['recorrencia' => 'unica', 'indice_reajuste' => 'nenhum'],
    ];

    /** Atividades que contam como contato com a pessoa (atualizam contatos.ultimo_contato_em). */
    private const TIPOS_CONTATO = ['ligacao', 'whatsapp', 'email', 'instagram', 'reuniao', 'visita'];

    /** Entidades cujo nome deve ser único entre os registros ativos (com escopo opcional). */
    private const NOME_UNICO = ['origens' => [], 'motivos_perda' => [], 'tags' => [], 'pipelines' => [], 'etapas' => ['pipeline_id'], 'contrato_tipos' => [], 'areas' => []];

    /** @var list<array{0:string,1:array}> */
    private array $eventos = [];

    /** Itens de proposta normalizados na operação corrente (null = não informados). */
    private ?array $itensPendentes = null;

    /** Itens que devem ser gravados ao final da operação corrente (null = manter). */
    private ?array $itensGravar = null;

    // =====================================================================================
    // API pública
    // =====================================================================================

    /** Despacho por nome de ação (usado pelo chat e por agentes a partir das Fases 4–5). */
    public function executar(string $acao, string $entidade, ?int $id, array $dados, string $origem = 'humano'): Resultado
    {
        return match ($acao) {
            'criar'              => $this->criar($entidade, $dados, $origem),
            'atualizar'          => $this->exigirId($id, fn (int $i) => $this->atualizar($entidade, $i, $dados, $origem)),
            'arquivar'           => $this->exigirId($id, fn (int $i) => $this->arquivar($entidade, $i, $origem)),
            'mover_etapa'        => $this->exigirId($id, fn (int $i) => $this->moverEtapa($i, $dados, $origem)),
            'concluir'           => $this->exigirId($id, fn (int $i) => $this->concluirTarefa($i, $origem)),
            'converter_cliente'  => $this->exigirId($id, fn (int $i) => $this->converterCliente($i, $origem)),
            'desfazer'           => $this->desfazer($id, $origem),
            default              => Resultado::erroGeral("Ação não permitida: {$acao}"),
        };
    }

    public function criar(string $entidade, array $dados, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        $schema = Schema::entidade($entidade);
        if ($schema === null) {
            return Resultado::erroGeral("Entidade não permitida: {$entidade}");
        }

        return $this->transacao(function () use ($entidade, $schema, $dados, $origem): Resultado {
            $erros = [];
            $dados += self::PADROES[$entidade] ?? [];
            $this->itensPendentes = $this->itensGravar = null;
            $itensBrutos = null;
            if ($entidade === 'propostas' && array_key_exists('itens', $dados)) {
                $itensBrutos = $dados['itens'];
                unset($dados['itens']);
            }
            $novos = $this->normalizar($entidade, $dados, true, $erros, null, $origem === 'humano');
            if ($entidade === 'propostas') {
                $this->itensPendentes = $this->normalizarItens($itensBrutos ?? [], $erros);
            }
            if ($erros !== []) {
                return Resultado::falha($erros);
            }
            $extra = [];
            $erros += $this->regrasCriar($entidade, $novos, $extra, $origem);
            if ($erros !== []) {
                return Resultado::falha($erros);
            }

            if ($this->vinculoFormulario !== null && in_array($entidade, ['empresas', 'negocios'], true)) {
                $extra += $this->vinculoFormulario; // formulario_id / submissao_id (envio de formulário público)
            }

            $agora = agora();
            $linha = $novos + $extra + ['criado_em' => $agora, 'atualizado_em' => $agora];
            if (in_array('criado_por', Repositorios::para($entidade)->colunas(), true)) {
                $linha['criado_por'] = $origem;
            }
            $repo = Repositorios::para($entidade);
            $id = $repo->inserir($linha);
            $registro = $repo->encontrar($id);
            $depois = array_intersect_key($registro, array_flip($repo->colunas()));
            if ($entidade === 'propostas') {
                (new ItemPropostaRepository())->substituir($id, $this->itensGravar ?? []);
                if ($this->itensGravar) {
                    $depois['_itens'] = $this->snapshotItens($this->itensGravar);
                }
            }
            $logId = Audit::registrar($origem, $entidade, $id, 'criar', null, $depois);
            $this->aposCriar($entidade, $registro, $origem);

            return Resultado::sucesso($id, $this->mensagem($schema, $registro, 'criar'), $registro, $logId);
        });
    }

    public function atualizar(string $entidade, int $id, array $dados, string $origem = 'humano', string $acao = 'atualizar'): Resultado
    {
        $this->validarOrigem($origem);
        $schema = Schema::entidade($entidade);
        if ($schema === null) {
            return Resultado::erroGeral("Entidade não permitida: {$entidade}");
        }

        return $this->transacao(function () use ($entidade, $schema, $id, $dados, $origem, $acao): Resultado {
            $repo = Repositorios::para($entidade);
            $atual = $repo->encontrar($id);
            if ($atual === null) {
                return Resultado::erroGeral("{$schema['singular']} não encontrad" . ($schema['genero'] === 'f' ? 'a' : 'o') . '.');
            }

            $erros = [];
            $this->itensPendentes = $this->itensGravar = null;
            $itensBrutos = null;
            if ($entidade === 'propostas' && array_key_exists('itens', $dados)) {
                $itensBrutos = $dados['itens'];
                unset($dados['itens']);
            }
            $novos = $this->normalizar($entidade, $dados, false, $erros, $atual, $origem === 'humano');
            if ($itensBrutos !== null) {
                $this->itensPendentes = $this->normalizarItens($itensBrutos, $erros);
            }
            if ($erros !== []) {
                return Resultado::falha($erros);
            }
            $derivados = [];
            $erros += $this->regrasAtualizar($entidade, $atual, $novos, $derivados, $origem);
            if ($erros !== []) {
                return Resultado::falha($erros);
            }

            $alterados = [];
            foreach ($novos + $derivados as $campo => $valor) {
                if ($this->diferente($atual[$campo] ?? null, $valor)) {
                    $alterados[$campo] = $valor;
                }
            }
            if ($alterados === [] && $this->itensGravar === null) {
                return Resultado::sucesso($id, 'Nada a alterar.', $atual);
            }

            $antes = array_intersect_key($atual, $alterados);
            $depois = $alterados;
            if ($alterados !== []) {
                $repo->atualizar($id, $alterados + ['atualizado_em' => agora()]);
            }
            if ($this->itensGravar !== null) {
                $itensRepo = new ItemPropostaRepository();
                $antes['_itens'] = $this->snapshotItens($itensRepo->porProposta($id));
                $depois['_itens'] = $this->snapshotItens($this->itensGravar);
                $itensRepo->substituir($id, $this->itensGravar);
                if ($alterados === []) {
                    $repo->atualizar($id, ['atualizado_em' => agora()]);
                }
            }
            $registro = $repo->encontrar($id);
            $logId = Audit::registrar($origem, $entidade, $id, $acao, $antes, $depois);
            $this->aposAtualizar($entidade, $atual, $registro, $alterados, $origem);

            return Resultado::sucesso($id, $this->mensagem($schema, $registro, 'atualizar'), $registro, $logId);
        });
    }

    /** Arquivamento (soft delete). Nunca há DELETE físico em entidades de negócio. */
    public function arquivar(string $entidade, int $id, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        $schema = Schema::entidade($entidade);
        if ($schema === null) {
            return Resultado::erroGeral("Entidade não permitida: {$entidade}");
        }

        return $this->transacao(function () use ($entidade, $schema, $id, $origem): Resultado {
            $repo = Repositorios::para($entidade);
            $atual = $repo->encontrar($id);
            if ($atual === null) {
                return Resultado::erroGeral("{$schema['singular']} não encontrad" . ($schema['genero'] === 'f' ? 'a' : 'o') . ' ou já arquivad' . ($schema['genero'] === 'f' ? 'a.' : 'o.'));
            }
            if (($erro = $this->impedimentosArquivar($entidade, $atual)) !== null) {
                return Resultado::erroGeral($erro);
            }

            $agora = agora();
            $repo->atualizar($id, ['arquivado_em' => $agora, 'atualizado_em' => $agora]);
            $logId = Audit::registrar($origem, $entidade, $id, 'arquivar', ['arquivado_em' => null], ['arquivado_em' => $agora]);

            return Resultado::sucesso($id, $this->mensagem($schema, $atual, 'arquivar'), $atual, $logId);
        });
    }

    /** Mover negócio de etapa. Ganho exige valor_fechado; perdido exige motivo_perda_id. */
    public function moverEtapa(int $negocioId, array $dados, string $origem = 'humano'): Resultado
    {
        $permitidos = array_intersect_key($dados, array_flip(['etapa_id', 'valor_fechado', 'motivo_perda_id', 'detalhe_perda']));
        if (!isset($permitidos['etapa_id'])) {
            return Resultado::falha(['etapa_id' => 'Informe a etapa de destino.']);
        }
        return $this->atualizar('negocios', $negocioId, $permitidos, $origem, 'mover_etapa');
    }

    public function concluirTarefa(int $tarefaId, string $origem = 'humano'): Resultado
    {
        return $this->atualizar('tarefas', $tarefaId, ['status' => 'concluida'], $origem, 'concluir');
    }

    // ---- Chamados: status e checklist (Fase 13) -----------------------------------------

    /** Muda o status de um chamado (aberto, andamento, aguardando, concluido, cancelado); a resolução é opcional ao fechar. */
    public function mudarStatusChamado(int $id, string $status, ?string $resolucao = null, string $origem = 'humano'): Resultado
    {
        $dados = ['status' => $status];
        if ($resolucao !== null && trim($resolucao) !== '') {
            $dados['resolucao'] = $resolucao;
        }
        return $this->atualizar('chamados', $id, $dados, $origem, 'mudar_status');
    }

    /** Marca ou desmarca o item $indice (0-based) do checklist. */
    public function alternarItemChecklist(int $id, int $indice, string $origem = 'humano'): Resultado
    {
        return $this->editarChecklist($id, $origem, 'checklist_alternar', static function (array $itens) use ($indice): ?array {
            if (!isset($itens[$indice])) {
                return null;
            }
            $itens[$indice]['feito'] = $itens[$indice]['feito'] === 1 ? 0 : 1;
            return $itens;
        });
    }

    public function adicionarItemChecklist(int $id, string $texto, string $origem = 'humano'): Resultado
    {
        return $this->editarChecklist($id, $origem, 'checklist_adicionar', static fn (array $itens): array => [...$itens, ['texto' => $texto, 'feito' => 0]]);
    }

    public function removerItemChecklist(int $id, int $indice, string $origem = 'humano'): Resultado
    {
        return $this->editarChecklist($id, $origem, 'checklist_remover', static function (array $itens) use ($indice): ?array {
            if (!isset($itens[$indice])) {
                return null;
            }
            array_splice($itens, $indice, 1);
            return $itens;
        });
    }

    /** @param callable(list<array{texto:string,feito:int}>):?list<array> $mudar devolve os novos itens (null = índice inexistente) */
    private function editarChecklist(int $id, string $origem, string $acao, callable $mudar): Resultado
    {
        $chamado = Repositorios::chamados()->encontrar($id);
        if ($chamado === null) {
            return Resultado::erroGeral('Chamado não encontrado.');
        }
        $novos = $mudar(Checklist::itens($chamado['checklist']));
        if ($novos === null) {
            return Resultado::erroGeral('Item do checklist não encontrado.');
        }
        return $this->atualizar('chamados', $id, ['checklist' => $novos], $origem, $acao);
    }

    public function converterCliente(int $empresaId, string $origem = 'humano'): Resultado
    {
        return $this->atualizar('empresas', $empresaId, ['status' => 'cliente'], $origem, 'converter_cliente');
    }

    /** Substitui as tags de um registro (empresas, contatos ou negocios). */
    public function definirTags(string $entidade, int $registroId, array $tagIds, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        if (!in_array($entidade, ['empresas', 'contatos', 'negocios'], true)) {
            return Resultado::erroGeral('Esta entidade não aceita tags.');
        }

        return $this->transacao(function () use ($entidade, $registroId, $tagIds, $origem): Resultado {
            if (!Repositorios::para($entidade)->existe($registroId)) {
                return Resultado::erroGeral('Registro não encontrado.');
            }
            $novos = [];
            foreach ($tagIds as $t) {
                $t = (int) $t;
                if (!Repositorios::tags()->existe($t)) {
                    return Resultado::erroGeral('Tag inexistente.');
                }
                $novos[$t] = $t;
            }
            $novos = array_values($novos);
            sort($novos);
            $atuais = Repositorios::tags()->idsDe($entidade, $registroId);
            sort($atuais);
            if ($novos === $atuais) {
                return Resultado::sucesso($registroId, 'Nada a alterar.');
            }

            Repositorios::tags()->substituir($entidade, $registroId, $novos);
            $logId = Audit::registrar($origem, $entidade, $registroId, 'definir_tags', ['tags' => $atuais], ['tags' => $novos]);
            return Resultado::sucesso($registroId, 'Tags atualizadas.', null, $logId);
        });
    }

    public function vincularContato(int $negocioId, int $contatoId, ?string $papel, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($negocioId, $contatoId, $papel, $origem): Resultado {
            if (!Repositorios::negocios()->existe($negocioId) || !Repositorios::contatos()->existe($contatoId)) {
                return Resultado::erroGeral('Negócio ou contato não encontrado.');
            }
            $papel = $papel !== null && trim($papel) !== '' ? mb_substr(trim($papel), 0, 80) : null;
            $anterior = Repositorios::negocios()->encontrarVinculo($negocioId, $contatoId);
            Repositorios::negocios()->vincularContato($negocioId, $contatoId, $papel);
            $logId = Audit::registrar(
                $origem, 'negocios', $negocioId, 'vincular_contato',
                $anterior ? ['contato_id' => $contatoId, 'papel' => $anterior['papel']] : null,
                ['contato_id' => $contatoId, 'papel' => $papel],
            );
            return Resultado::sucesso($negocioId, 'Contato vinculado ao negócio.', null, $logId);
        });
    }

    public function desvincularContato(int $negocioId, int $contatoId, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($negocioId, $contatoId, $origem): Resultado {
            $vinculo = Repositorios::negocios()->encontrarVinculo($negocioId, $contatoId);
            if ($vinculo === null) {
                return Resultado::erroGeral('Vínculo não encontrado.');
            }
            Repositorios::negocios()->desvincularContato($negocioId, $contatoId);
            $logId = Audit::registrar($origem, 'negocios', $negocioId, 'desvincular_contato',
                ['contato_id' => $contatoId, 'papel' => $vinculo['papel']], null);
            return Resultado::sucesso($negocioId, 'Contato desvinculado do negócio.', null, $logId);
        });
    }

    /** Preferências/configurações (não gera auditoria: não é registro de negócio). */
    public function definirConfiguracao(string $chave, ?string $valor): Resultado
    {
        if (!preg_match('/^[a-z0-9_.]{1,80}$/', $chave)) {
            return Resultado::erroGeral('Chave de configuração inválida.');
        }
        (new ConfiguracaoRepository())->definir($chave, $valor);
        return Resultado::sucesso(null, 'Configuração salva.');
    }

    /**
     * Desfaz uma entrada do log (a última não desfeita, se $logId for null): criar → arquivar;
     * atualizar → restaurar valores; arquivar → limpar arquivado_em. O próprio desfazer é auditado.
     */
    public function desfazer(?int $logId = null, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($logId, $origem): Resultado {
            $auditoria = new AuditoriaRepository();
            $log = $logId !== null ? $auditoria->encontrar($logId) : $auditoria->ultimaDesfazivel();
            if ($log === null) {
                return Resultado::erroGeral('Não há ação para desfazer.');
            }
            if ($log['desfeito_em'] !== null) {
                return Resultado::erroGeral('Esta ação já foi desfeita.');
            }
            if ($log['acao'] === 'desfazer') {
                return Resultado::erroGeral('Um desfazer não pode ser desfeito.');
            }

            $entidade = (string) $log['entidade'];
            $id = (int) $log['registro_id'];
            $antes = $log['antes'] !== null ? (array) json_decode($log['antes'], true) : null;
            $depois = $log['depois'] !== null ? (array) json_decode($log['depois'], true) : null;
            $repo = Repositorios::para($entidade);
            $agora = agora();

            try {
                switch ($log['acao']) {
                    case 'criar':
                        if ($repo->encontrar($id) === null) {
                            return Resultado::erroGeral('O registro já está arquivado.');
                        }
                        $revertido = ['arquivado_em' => $agora];
                        $anterior = ['arquivado_em' => null];
                        $repo->atualizar($id, $revertido + ['atualizado_em' => $agora]);
                        break;
                    case 'definir_tags':
                        $anterior = ['tags' => Repositorios::tags()->idsDe($entidade, $id)];
                        $revertido = ['tags' => (array) ($antes['tags'] ?? [])];
                        Repositorios::tags()->substituir($entidade, $id, $revertido['tags']);
                        break;
                    case 'vincular_contato':
                        $contatoId = (int) ($depois['contato_id'] ?? 0);
                        $anterior = ['contato_id' => $contatoId, 'papel' => $depois['papel'] ?? null];
                        if ($antes !== null) {
                            Repositorios::negocios()->vincularContato($id, $contatoId, $antes['papel'] ?? null);
                            $revertido = $antes;
                        } else {
                            Repositorios::negocios()->desvincularContato($id, $contatoId);
                            $revertido = null;
                        }
                        break;
                    case 'desvincular_contato':
                        $contatoId = (int) ($antes['contato_id'] ?? 0);
                        $anterior = null;
                        Repositorios::negocios()->vincularContato($id, $contatoId, $antes['papel'] ?? null);
                        $revertido = $antes;
                        break;
                    default: // arquivar, atualizar, mover_etapa, concluir, converter_cliente
                        $registro = $repo->encontrar($id, true);
                        if ($registro === null || $antes === null) {
                            return Resultado::erroGeral('Não é possível desfazer esta ação.');
                        }
                        $itensAntes = $antes['_itens'] ?? null;
                        unset($antes['_itens']);
                        $anterior = array_intersect_key($registro, $antes);
                        $revertido = $antes;
                        if ($itensAntes !== null) {
                            $itensRepo = new ItemPropostaRepository();
                            $anterior['_itens'] = $this->snapshotItens($itensRepo->porProposta($id));
                            $itensRepo->substituir($id, $itensAntes);
                            $revertido['_itens'] = $itensAntes;
                        }
                        $repo->atualizar($id, $antes + ['atualizado_em' => $agora]);
                }
            } catch (PDOException $e) {
                return Resultado::erroGeral('Não foi possível desfazer: o estado atual conflita com o registro anterior.');
            }

            $auditoria->marcarDesfeito((int) $log['id'], $agora);
            $logDesfazer = Audit::registrar(
                $origem, $entidade, $id, 'desfazer', $anterior,
                ($revertido ?? []) + ['_desfaz' => (int) $log['id']],
            );

            return Resultado::sucesso($id, 'Ação desfeita: ' . $this->descreverAcao($log) . '.', null, $logDesfazer);
        });
    }

    // =====================================================================================
    // Transação e eventos
    // =====================================================================================

    private function transacao(callable $fn): Resultado
    {
        $pdo = DB::conexao();
        $externa = !$pdo->inTransaction();
        if ($externa) {
            $pdo->beginTransaction();
            $this->eventos = [];
        }
        try {
            $resultado = $fn();
            if ($externa) {
                if ($resultado->ok) {
                    $pdo->commit();
                    $this->dispararEventos();
                } else {
                    $pdo->rollBack();
                    $this->eventos = [];
                }
            }
            return $resultado;
        } catch (Throwable $e) {
            if ($externa && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->eventos = [];
            throw $e;
        }
    }

    private function enfileirar(string $evento, array $payload): void
    {
        $this->eventos[] = [$evento, $payload];
    }

    private function dispararEventos(): void
    {
        $fila = $this->eventos;
        $this->eventos = [];
        foreach ($fila as [$evento, $payload]) {
            Events::disparar($evento, $payload);
        }
    }

    private function validarOrigem(string $origem): void
    {
        if (!preg_match(Schema::ORIGENS_VALIDAS, $origem)) {
            throw new InvalidArgumentException("Origem inválida: {$origem}");
        }
    }

    private function exigirId(?int $id, callable $fn): Resultado
    {
        return $id === null ? Resultado::erroGeral('Informe o registro.') : $fn($id);
    }

    // =====================================================================================
    // Validação e normalização (whitelist)
    // =====================================================================================

    /**
     * Valida e converte a entrada. Campos fora da whitelist ou controlados pelo servidor geram erro.
     * Valores monetários entram em reais (número ou texto pt-BR) e saem em centavos.
     * Campos extras (`campos_extras`) mesclam sobre o valor atual; os obrigatórios só são exigidos de quem edita
     * pela interface ($exigirExtras), para não travar chat, agentes e formulários públicos.
     * @param array<string,string> $erros
     */
    private function normalizar(string $entidade, array $dados, bool $criar, array &$erros, ?array $atual = null, bool $exigirExtras = false): array
    {
        $campos = Schema::entidade($entidade)['campos'];
        $saida = [];

        foreach ($dados as $chave => $valor) {
            $def = $campos[$chave] ?? null;
            if ($def === null || !empty($def['sis'])) {
                $erros[$chave] = 'Campo não permitido: ' . $chave . '.';
                continue;
            }
            if ($def['t'] === 'extras') {
                $saida[$chave] = CamposExtras::normalizar($entidade, $valor, $atual['campos_extras'] ?? null, $exigirExtras, $erros);
                continue;
            }
            if ($def['t'] === 'checklist') {
                $erroChecklist = null;
                $json = Checklist::normalizar($valor, $erroChecklist);
                if ($json === false) {
                    $erros[$chave] = (string) $erroChecklist;
                } else {
                    $saida[$chave] = $json;
                }
                continue;
            }
            $erro = null;
            $saida[$chave] = $this->converter($def, $valor, $erro);
            if ($erro !== null) {
                $erros[$chave] = $erro;
            }
        }

        if ($criar && $exigirExtras && isset($campos['campos_extras']) && !array_key_exists('campos_extras', $dados)) {
            $extras = CamposExtras::normalizar($entidade, [], null, true, $erros);
            if ($extras !== null) {
                $saida['campos_extras'] = $extras;
            }
        }

        foreach ($campos as $chave => $def) {
            if (empty($def['req']) || isset($erros[$chave])) {
                continue;
            }
            $presente = array_key_exists($chave, $saida);
            if (($criar && (!$presente || $saida[$chave] === null)) || (!$criar && $presente && $saida[$chave] === null)) {
                $erros[$chave] = "O campo {$def['r']} é obrigatório.";
            }
        }
        return $saida;
    }

    private function converter(array $def, mixed $valor, ?string &$erro): mixed
    {
        $t = $def['t'];
        if (is_string($valor)) {
            if (!mb_check_encoding($valor, 'UTF-8')) {
                return $this->falhar($erro, "O campo {$def['r']} contém caracteres inválidos (esperado UTF-8).");
            }
            $valor = trim($valor);
        }
        if ($t === 'bool') {
            return match (true) {
                $valor === null || $valor === '' => 0,
                in_array($valor, [1, '1', true, 'true', 'on', 'sim'], true) => 1,
                in_array($valor, [0, '0', false, 'false', 'off', 'nao', 'não'], true) => 0,
                default => $this->falhar($erro, "O campo {$def['r']} deve ser sim ou não."),
            };
        }
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!is_scalar($valor)) {
            return $this->falhar($erro, "O campo {$def['r']} tem um valor inválido.");
        }

        switch ($t) {
            case 'texto':
            case 'textarea':
            case 'tel':
            case 'url':
                $texto = (string) $valor;
                $max = $def['max'] ?? ($t === 'textarea' ? 5000 : 255);
                return mb_strlen($texto) > $max ? $this->falhar($erro, "O campo {$def['r']} deve ter no máximo {$max} caracteres.") : $texto;
            case 'email':
                $email = mb_strtolower((string) $valor);
                return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : $this->falhar($erro, "O campo {$def['r']} deve ser um e-mail válido.");
            case 'int':
                if (!preg_match('/^-?\d+$/', (string) $valor)) {
                    return $this->falhar($erro, "O campo {$def['r']} deve ser um número inteiro.");
                }
                $n = (int) $valor;
                if ((isset($def['min']) && $n < $def['min']) || (isset($def['max']) && $n > $def['max'])) {
                    $faixa = ($def['min'] ?? '−∞') . ' e ' . ($def['max'] ?? '∞');
                    return $this->falhar($erro, "O campo {$def['r']} deve estar entre {$faixa}.");
                }
                return $n;
            case 'money':
                $c = reais_para_centavos(is_string($valor) || is_float($valor) || is_int($valor) ? $valor : null);
                if ($c === null || $c < 0) {
                    return $this->falhar($erro, "O campo {$def['r']} deve ser um valor em reais válido.");
                }
                return $c;
            case 'data':
                return $this->dataIso((string) $valor) ?? $this->falhar($erro, "O campo {$def['r']} deve ser uma data válida.");
            case 'datahora':
                return $this->dataHoraIso((string) $valor) ?? $this->falhar($erro, "O campo {$def['r']} deve ser uma data e hora válidas.");
            case 'vencimento':
                $s = (string) $valor;
                if ($this->dataIso($s) !== null && !str_contains($s, ':')) {
                    return $this->dataIso($s);
                }
                return $this->dataHoraIso($s) ?? $this->falhar($erro, "O campo {$def['r']} deve ser uma data válida.");
            case 'enum':
                return array_key_exists((string) $valor, Schema::opcoes($def['op']))
                    ? (string) $valor
                    : $this->falhar($erro, "O campo {$def['r']} tem um valor inválido.");
            case 'fk':
                if (!preg_match('/^\d+$/', (string) $valor) || !Repositorios::para($def['fk'])->existe((int) $valor)) {
                    return $this->falhar($erro, "O campo {$def['r']} referencia um registro inexistente.");
                }
                return (int) $valor;
            case 'cnpj':
                return cnpj_valido((string) $valor) ? so_digitos((string) $valor) : $this->falhar($erro, 'CNPJ inválido.');
            case 'cpf':
                return cpf_valido((string) $valor) ? so_digitos((string) $valor) : $this->falhar($erro, 'CPF inválido.');
            case 'cep':
                return strlen(so_digitos((string) $valor)) === 8 ? so_digitos((string) $valor) : $this->falhar($erro, 'CEP inválido.');
            case 'uf':
                return preg_match('/^[A-Za-z]{2}$/', (string) $valor) ? strtoupper((string) $valor) : $this->falhar($erro, 'UF inválida.');
            case 'decimal':
                $t = (string) $valor;
                if (str_contains($t, ',')) {
                    $t = str_replace(',', '.', str_replace('.', '', $t));
                }
                if (!is_numeric($t) || (float) $t < 0 || (float) $t > 1000000) {
                    return $this->falhar($erro, "O campo {$def['r']} deve ser um número válido.");
                }
                return round((float) $t, 3);
            case 'html':
                $texto = (string) $valor;
                if (mb_strlen($texto) > ($def['max'] ?? 200000)) {
                    return $this->falhar($erro, "O campo {$def['r']} é grande demais.");
                }
                return Html::sanitizar($texto);
            case 'cor':
                return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $valor) ? strtolower((string) $valor) : $this->falhar($erro, 'Cor inválida (use #rrggbb).');
        }
        return $this->falhar($erro, "Tipo de campo não suportado: {$t}.");
    }

    private function falhar(?string &$erro, string $mensagem): null
    {
        $erro = $mensagem;
        return null;
    }

    private function dataIso(string $texto): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $texto : null;
        }
        return data_iso($texto);
    }

    private function dataHoraIso(string $texto): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $texto, $m)) {
            $data = $this->dataIso($m[1]);
            $s = (int) ($m[4] ?? 0);
            return $data !== null && (int) $m[2] < 24 && (int) $m[3] < 60 && $s < 60
                ? sprintf('%s %02d:%02d:%02d', $data, $m[2], $m[3], $s)
                : null;
        }
        if (preg_match('#^(\d{1,2}/\d{1,2}/\d{4})(?: (\d{2}):(\d{2}))?$#', $texto, $m)) {
            $data = data_iso($m[1]);
            return $data !== null ? sprintf('%s %02d:%02d:00', $data, $m[2] ?? 0, $m[3] ?? 0) : null;
        }
        $data = $this->dataIso($texto);
        return $data !== null ? $data . ' 00:00:00' : null;
    }

    private function diferente(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return false;
        }
        return (string) ($a ?? '') !== (string) ($b ?? '') || ($a === null) !== ($b === null);
    }

    // =====================================================================================
    // Regras de negócio por entidade
    // =====================================================================================

    /** @return array<string,string> erros; pode preencher $extra (campos controlados pelo servidor) */
    private function regrasCriar(string $entidade, array &$v, array &$extra, string $origem): array
    {
        $erros = [];
        switch ($entidade) {
            case 'empresas':
                if (isset($v['cnpj']) && ($outra = Repositorios::empresas()->porCnpj($v['cnpj'])) !== null) {
                    $erros['cnpj'] = "Já existe uma empresa com este CNPJ ({$outra['nome_fantasia']}).";
                }
                if (($v['status'] ?? '') === 'cliente' && empty($v['cliente_desde'])) {
                    $extra['cliente_desde'] = hoje();
                }
                break;

            case 'negocios':
                $this->aplicarEtapaNegocio($v, null, $extra, $erros);
                if ($erros === []) {
                    $extra['codigo'] = Repositorios::negocios()->proximoCodigo((int) date('Y'));
                    $extra += Champ::derivar([], $v);
                }
                break;

            case 'atividades':
                if (($v['tipo'] ?? '') === 'sistema' && $origem !== 'sistema') {
                    $erros['tipo'] = 'Atividades de sistema só podem ser geradas automaticamente.';
                }
                if (empty($v['empresa_id']) && empty($v['contato_id']) && empty($v['negocio_id'])) {
                    $erros['_'] = 'Vincule a atividade a uma empresa, contato ou negócio.';
                    break;
                }
                $v['data_hora'] ??= agora();
                if (empty($v['negocio_id']) === false && empty($v['empresa_id'])) {
                    $v['empresa_id'] = Repositorios::negocios()->encontrar((int) $v['negocio_id'])['empresa_id'] ?? null;
                }
                if (empty($v['contato_id']) === false && empty($v['empresa_id'])) {
                    $v['empresa_id'] = Repositorios::contatos()->encontrar((int) $v['contato_id'])['empresa_id'] ?? null;
                }
                break;

            case 'tarefas':
                if (($v['status'] ?? '') === 'concluida') {
                    $extra['concluida_em'] = agora();
                }
                break;

            case 'anexos':
                if (!Repositorios::para($v['entidade'])->existe((int) $v['registro_id'])) {
                    $erros['registro_id'] = 'Registro de destino não encontrado.';
                }
                break;

            case 'etapas':
                $v['ordem'] ??= Repositorios::etapas()->proximaOrdem((int) $v['pipeline_id']);
                break;

            case 'pipelines':
                $extra['padrao'] = Repositorios::pipelines()->padrao() === null ? 1 : 0;
                break;

            case 'propostas':
                $erros += $this->regrasCriarProposta($v, $extra);
                break;

            case 'contratos':
                $erros += $this->regrasCriarContrato($v, $extra);
                break;

            case 'campos_extras_def':
                $erros += $this->regrasCampoExtra($v, null);
                break;

            case 'metas':
                $extra['data_fim'] = Metas::fimDoPeriodo((string) $v['data_inicio'], (string) $v['periodo']);
                break;

            case 'chamados':
                $extra['codigo'] = Repositorios::chamados()->proximoCodigo((int) date('Y'));
                if (($v['status'] ?? 'aberto') === 'concluido') {
                    $extra['concluido_em'] = agora();
                }
                break;

            case 'modelos_documento':
                $v['conteudo'] ??= '';
                if (($v['tipo'] ?? '') === 'contrato') {
                    $v['conteudo'] = Html::sanitizar($v['conteudo']);
                }
                break;

            case 'cobrancas':
                $extra['status'] = 'pendente';
                $erros += $this->regrasCicloCobranca($v);
                break;

            case 'custos':
                if (empty($v['empresa_id']) && empty($v['negocio_id'])) {
                    $erros['_'] = 'Vincule o custo a uma empresa ou a um negócio.';
                }
                break;
        }

        return $erros + $this->nomeUnico($entidade, $v, null);
    }

    /** @return array<string,string> */
    private function regrasAtualizar(string $entidade, array $atual, array &$novos, array &$derivados, string $origem): array
    {
        $erros = [];
        switch ($entidade) {
            case 'empresas':
                if (isset($novos['cnpj']) && ($outra = Repositorios::empresas()->porCnpj($novos['cnpj'], (int) $atual['id'])) !== null) {
                    $erros['cnpj'] = "Já existe uma empresa com este CNPJ ({$outra['nome_fantasia']}).";
                }
                if (($novos['status'] ?? $atual['status']) === 'cliente' && $atual['status'] !== 'cliente'
                    && empty($novos['cliente_desde']) && empty($atual['cliente_desde'])) {
                    $derivados['cliente_desde'] = hoje();
                }
                break;

            case 'negocios':
                $this->aplicarEtapaNegocio($novos, $atual, $derivados, $erros);
                $derivados += Champ::derivar($atual, $novos);
                break;

            case 'tarefas':
                $status = $novos['status'] ?? $atual['status'];
                if ($status === 'concluida' && $atual['status'] !== 'concluida') {
                    $derivados['concluida_em'] = agora();
                } elseif ($status !== 'concluida' && $atual['concluida_em'] !== null) {
                    $derivados['concluida_em'] = null;
                }
                break;

            case 'atividades':
                if ($atual['tipo'] === 'sistema') {
                    $erros['_'] = 'Atividades de sistema não podem ser editadas.';
                }
                break;

            case 'anexos':
                $erros['_'] = 'Anexos não podem ser editados; envie um novo arquivo.';
                break;

            case 'campos_extras_def':
                $erros += $this->regrasCampoExtra($novos, $atual);
                break;

            case 'modelos_documento':
                if (array_key_exists('conteudo', $novos)) {
                    $novos['conteudo'] ??= '';
                    if (($novos['tipo'] ?? $atual['tipo']) === 'contrato') {
                        $novos['conteudo'] = Html::sanitizar($novos['conteudo']);
                    }
                }
                break;

            case 'propostas':
                $erros += $this->regrasAtualizarProposta($atual, $novos, $derivados);
                break;

            case 'contratos':
                $erros += $this->regrasAtualizarContrato($atual, $novos);
                break;

            case 'metas':
                // O fim do período acompanha o tipo de período e a data de início.
                $derivados['data_fim'] = Metas::fimDoPeriodo((string) ($novos['data_inicio'] ?? $atual['data_inicio']), (string) ($novos['periodo'] ?? $atual['periodo']));
                break;

            case 'chamados':
                // Concluído carimba a data; sair de concluído (reabrir) limpa.
                $statusNovo = $novos['status'] ?? $atual['status'];
                if ($statusNovo === 'concluido' && $atual['status'] !== 'concluido') {
                    $derivados['concluido_em'] = agora();
                } elseif ($statusNovo !== 'concluido' && $atual['concluido_em'] !== null) {
                    $derivados['concluido_em'] = null;
                }
                break;

            case 'cobrancas':
                $erros += $this->regrasCicloCobranca($novos, $atual);
                if ($atual['asaas_id'] !== null) {
                    foreach (array_diff(array_keys($novos), ['notas']) as $campo) {
                        $erros[$campo] = 'Não é possível alterar depois de emitida no Asaas; cancele e crie uma nova cobrança.';
                    }
                }
                break;

            case 'custos':
                if (empty($novos['empresa_id'] ?? $atual['empresa_id']) && empty($novos['negocio_id'] ?? $atual['negocio_id'])) {
                    $erros['_'] = 'Vincule o custo a uma empresa ou a um negócio.';
                }
                break;
        }

        return $erros + $this->nomeUnico($entidade, $novos, (int) $atual['id'], $atual);
    }

    /**
     * Definição de campo extra: chave (minúsculas, números e _) única por entidade e imutável depois de criada
     * (os valores gravados nos registros a referenciam); opções só para lista, uma por linha, gravadas como JSON.
     * @return array<string,string>
     */
    private function regrasCampoExtra(array &$v, ?array $atual): array
    {
        $erros = [];
        $repo = Repositorios::camposExtras();
        $entidade = (string) ($v['entidade'] ?? $atual['entidade'] ?? '');

        if ($atual === null) {
            $chave = (string) ($v['chave'] ?? '');
            if (!preg_match(CamposExtras::PADRAO_CHAVE, $chave)) {
                $erros['chave'] = 'Use só letras minúsculas, números e _, começando por letra (até 40 caracteres).';
            } elseif ($repo->chaveEmUso($entidade, $chave)) {
                $erros['chave'] = 'Já existe um campo extra com esta chave.';
            }
            $v['ordem'] ??= $repo->proximaOrdem($entidade);
        } else {
            foreach (['entidade', 'chave'] as $imutavel) {
                if (isset($v[$imutavel]) && $v[$imutavel] !== $atual[$imutavel]) {
                    $erros[$imutavel] = 'Não pode ser alterado depois de criado.';
                }
            }
        }

        $tipo = (string) ($v['tipo'] ?? $atual['tipo'] ?? 'texto');
        if ($tipo !== 'select') {
            $v['opcoes'] = null;
        } elseif (array_key_exists('opcoes', $v) || $atual === null || ($atual['tipo'] ?? '') !== 'select') {
            $opcoes = [];
            foreach (preg_split('/\R/u', (string) ($v['opcoes'] ?? '')) ?: [] as $linha) {
                $linha = trim($linha);
                if ($linha !== '') {
                    $opcoes[$linha] = $linha;
                }
            }
            if ($opcoes === []) {
                $erros['opcoes'] = 'Informe ao menos uma opção (uma por linha).';
            } elseif (count($opcoes) > 50 || max(array_map('mb_strlen', $opcoes)) > 80) {
                $erros['opcoes'] = 'Use até 50 opções de até 80 caracteres.';
            } else {
                $v['opcoes'] = json_encode(array_values($opcoes), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
        }
        return $erros;
    }

    /**
     * Recorrente exige ciclo (mensal/anual); avulsa nunca leva ciclo. Só mexe em `ciclo` quando esta operação
     * realmente muda o tipo ou o próprio ciclo: uma edição que não toca nenhum dos dois (ex.: só as notas) não pode
     * ganhar um `ciclo => null` de brinde, senão o bloqueio de campos pós-emissão (ver regrasAtualizar) barraria a
     * edição por engano. @return array<string,string>
     */
    private function regrasCicloCobranca(array &$v, ?array $atual = null): array
    {
        $tipoNovo = $v['tipo'] ?? null;
        $tipoEfetivo = $tipoNovo ?? ($atual['tipo'] ?? null);

        if ($tipoEfetivo === 'recorrente' && empty($v['ciclo'] ?? $atual['ciclo'] ?? null)) {
            return ['ciclo' => 'Informe o ciclo de cobrança (mensal ou anual).'];
        }
        if ($tipoEfetivo === 'avulsa' && ($tipoNovo !== null || array_key_exists('ciclo', $v))) {
            $v['ciclo'] = null;
        }
        return [];
    }

    /** @return array<string,string> */
    private function nomeUnico(string $entidade, array $novos, ?int $ignorarId, array $atual = []): array
    {
        if (!isset(self::NOME_UNICO[$entidade]) || !isset($novos['nome'])) {
            return [];
        }
        $escopo = [];
        foreach (self::NOME_UNICO[$entidade] as $col) {
            $escopo[$col] = $novos[$col] ?? $atual[$col] ?? null;
        }
        return Repositorios::para($entidade)->valorDuplicado('nome', $novos['nome'], $ignorarId, $escopo)
            ? ['nome' => 'Já existe um registro com este nome.']
            : [];
    }

    /**
     * Regras de etapa/status do negócio (SPEC §4.3): pipeline derivado da etapa; ganho → status ganho,
     * data_fechamento = hoje, exige valor_fechado; perdido → exige motivo_perda_id; reabrir volta a "aberto".
     * Preenche $derivados (campos controlados pelo servidor) e devolve erros em $erros.
     */
    private function aplicarEtapaNegocio(array &$novos, ?array $atual, array &$derivados, array &$erros): void
    {
        $etapas = Repositorios::etapas();
        $criando = $atual === null;
        $etapa = null;

        $etapaId = $novos['etapa_id'] ?? ($criando ? null : (int) $atual['etapa_id']);
        if ($etapaId === null) {
            $pipeline = Repositorios::pipelines()->padrao();
            $etapa = $pipeline !== null ? $etapas->primeiraAberta((int) $pipeline['id']) : null;
            if ($etapa === null) {
                $erros['etapa_id'] = 'Configure ao menos um pipeline com uma etapa aberta.';
                return;
            }
        } else {
            $etapa = $etapas->encontrar((int) $etapaId);
            if ($etapa === null) {
                $erros['etapa_id'] = 'Etapa inexistente.';
                return;
            }
        }

        $mudou = $criando || (int) $atual['etapa_id'] !== (int) $etapa['id'];
        $derivados['pipeline_id'] = (int) $etapa['pipeline_id'];
        if ($mudou) {
            $derivados['etapa_id'] = (int) $etapa['id'];
            $derivados['entrou_etapa_em'] = agora();
            if (!array_key_exists('probabilidade', $novos) || $novos['probabilidade'] === null) {
                $derivados['probabilidade'] = (int) $etapa['probabilidade_padrao'];
            }
        }
        unset($novos['etapa_id']);

        $statusAtual = $atual['status'] ?? 'aberto';
        $pedido = $novos['status'] ?? null;
        unset($novos['status']);
        $tipo = $etapa['tipo'];

        if ($tipo === 'ganho') {
            $valor = array_key_exists('valor_fechado', $novos) ? $novos['valor_fechado'] : ($atual['valor_fechado'] ?? null);
            if ($valor === null) {
                $erros['valor_fechado'] = 'Informe o valor fechado para ganhar o negócio.';
            }
            $final = 'ganho';
            if ($statusAtual !== 'ganho') {
                $derivados['data_fechamento'] = hoje();
            }
        } elseif ($tipo === 'perdido') {
            $motivo = array_key_exists('motivo_perda_id', $novos) ? $novos['motivo_perda_id'] : ($atual['motivo_perda_id'] ?? null);
            if ($motivo === null) {
                $erros['motivo_perda_id'] = 'Informe o motivo da perda.';
            }
            $final = 'perdido';
            if ($statusAtual !== 'perdido') {
                $derivados['data_fechamento'] = hoje();
            }
        } else {
            $reabrindo = in_array($statusAtual, ['ganho', 'perdido'], true);
            $final = in_array($pedido, ['aberto', 'pausado'], true) ? $pedido : ($reabrindo ? 'aberto' : $statusAtual);
            if ($reabrindo) {
                $derivados['data_fechamento'] = null;
            }
        }

        if ($pedido !== null && in_array($pedido, ['ganho', 'perdido'], true) && $pedido !== $final) {
            $erros['status'] = 'Para ganhar ou perder um negócio, mova-o para a etapa correspondente.';
        } elseif ($pedido === 'pausado' && $final !== 'pausado' && $tipo !== 'aberta') {
            $erros['status'] = 'Só negócios em etapas abertas podem ser pausados.';
        }
        $derivados['status'] = $final;
    }

    private function impedimentosArquivar(string $entidade, array $atual): ?string
    {
        if ($entidade === 'atividades' && $atual['tipo'] === 'sistema') {
            return 'Atividades de sistema não podem ser arquivadas.';
        }
        if ($entidade === 'cobrancas' && $atual['status'] === 'pendente' && $atual['asaas_id'] !== null) {
            return 'Cancele a cobrança no Asaas antes de arquivar.';
        }
        if ($entidade === 'etapas' && Repositorios::negocios()->contarAtivosNaEtapa((int) $atual['id']) > 0) {
            return 'Há negócios ativos nesta etapa. Mova-os antes de arquivá-la.';
        }
        if ($entidade === 'pipelines') {
            if ((int) $atual['padrao'] === 1) {
                return 'O pipeline padrão não pode ser arquivado.';
            }
            foreach (Repositorios::etapas()->doPipeline((int) $atual['id']) as $etapa) {
                if (Repositorios::negocios()->contarAtivosNaEtapa((int) $etapa['id']) > 0) {
                    return 'Há negócios ativos neste pipeline. Mova-os antes de arquivá-lo.';
                }
            }
        }
        return null;
    }

    // =====================================================================================
    // Efeitos colaterais (atividades de sistema, último contato, eventos)
    // =====================================================================================

    private function aposCriar(string $entidade, array $registro, string $origem): void
    {
        $payload = ['entidade' => $entidade, 'id' => (int) $registro['id'], 'origem' => $origem, 'registro' => $registro];
        switch ($entidade) {
            case 'empresas':
                $this->enfileirar('empresa.criada', $payload);
                break;
            case 'contatos':
                $this->enfileirar('contato.criado', $payload);
                break;
            case 'negocios':
                $this->atividadeSistema('Negócio criado', "{$registro['codigo']} — {$registro['titulo']}", $registro);
                $this->enfileirar('negocio.criado', $payload);
                break;
            case 'atividades':
                if (in_array($registro['tipo'], self::TIPOS_CONTATO, true)) {
                    $contatoId = $registro['contato_id']
                        ?? (Repositorios::negocios()->encontrar((int) ($registro['negocio_id'] ?? 0))['contato_principal_id'] ?? null);
                    if ($contatoId !== null) {
                        Repositorios::contatos()->atualizarUltimoContato((int) $contatoId, (string) $registro['data_hora']);
                    }
                }
                $this->enfileirar('atividade.criada', $payload);
                break;
        }
    }

    private function aposAtualizar(string $entidade, array $antes, array $depois, array $alterados, string $origem): void
    {
        $payload = ['entidade' => $entidade, 'id' => (int) $depois['id'], 'origem' => $origem, 'registro' => $depois, 'alterados' => array_keys($alterados)];

        if ($entidade === 'empresas') {
            $this->enfileirar('empresa.atualizada', $payload);
            if (isset($alterados['status']) && $depois['status'] === 'cliente' && $antes['status'] !== 'cliente') {
                $this->atividadeSistema('Empresa convertida em cliente', null, $depois, true);
                $this->enfileirar('empresa.convertida', $payload);
            }
        }

        if ($entidade === 'negocios' && isset($alterados['etapa_id'])) {
            $de = Repositorios::etapas()->encontrar((int) $antes['etapa_id'], true)['nome'] ?? '?';
            $para = Repositorios::etapas()->encontrar((int) $depois['etapa_id'], true)['nome'] ?? '?';
            $detalhe = "{$de} → {$para}";
            if ($depois['status'] === 'ganho') {
                $detalhe .= ' · Valor fechado: ' . moeda($depois['valor_fechado']);
            } elseif ($depois['status'] === 'perdido') {
                $motivo = Repositorios::para('motivos_perda')->encontrar((int) $depois['motivo_perda_id'], true)['nome'] ?? '';
                $detalhe .= ' · Motivo: ' . $motivo . (($depois['detalhe_perda'] ?? '') !== '' ? " ({$depois['detalhe_perda']})" : '');
            }
            $this->atividadeSistema('Etapa alterada', $detalhe, $depois);
            $this->enfileirar('negocio.etapa_mudou', $payload + ['de_etapa_id' => (int) $antes['etapa_id'], 'para_etapa_id' => (int) $depois['etapa_id']]);
            if ($depois['status'] === 'ganho' && $antes['status'] !== 'ganho') {
                $this->enfileirar('negocio.ganho', $payload);
            } elseif ($depois['status'] === 'perdido' && $antes['status'] !== 'perdido') {
                $this->enfileirar('negocio.perdido', $payload);
            }
        }
    }

    /**
     * Atividade tipo "sistema" da timeline. Vinculada a um negócio (padrão) ou diretamente à empresa.
     * Não é auditada à parte: é consequência da ação principal, que já está no log.
     */
    private function atividadeSistema(string $assunto, ?string $descricao, array $registro, bool $daEmpresa = false): void
    {
        $agora = agora();
        Repositorios::atividades()->inserir([
            'tipo'          => 'sistema',
            'assunto'       => $assunto,
            'descricao'     => $descricao,
            'data_hora'     => $agora,
            'empresa_id'    => $daEmpresa ? (int) $registro['id'] : ($registro['empresa_id'] ?? null),
            'negocio_id'    => $daEmpresa ? null : (int) $registro['id'],
            'criado_em'     => $agora,
            'atualizado_em' => $agora,
            'criado_por'    => 'sistema',
        ]);
    }

    // =====================================================================================
    // Mensagens
    // =====================================================================================

    private function nomeDoRegistro(array $registro): string
    {
        if (isset($registro['valor_alvo'], $registro['data_fim'])) {
            return Metas::rotulo($registro);
        }
        return (string) ($registro['nome_fantasia'] ?? $registro['titulo'] ?? $registro['nome'] ?? $registro['nome_original'] ?? $registro['rotulo'] ?? $registro['descricao'] ?? ('#' . ($registro['id'] ?? '')));
    }

    private function mensagem(array $schema, array $registro, string $acao): string
    {
        $f = $schema['genero'] === 'f';
        $verbo = match ($acao) {
            'criar'     => $f ? 'criada' : 'criado',
            'atualizar' => $f ? 'atualizada' : 'atualizado',
            'arquivar'  => $f ? 'arquivada' : 'arquivado',
        };
        return "{$schema['singular']} \"{$this->nomeDoRegistro($registro)}\" {$verbo}.";
    }

    private function descreverAcao(array $log): string
    {
        $schema = Schema::entidade((string) $log['entidade']);
        $rotulo = $schema['singular'] ?? $log['entidade'];
        return match ($log['acao']) {
            'criar'      => "criação de {$rotulo} #{$log['registro_id']}",
            'arquivar'   => "arquivamento de {$rotulo} #{$log['registro_id']}",
            default      => "{$log['acao']} em {$rotulo} #{$log['registro_id']}",
        };
    }
}
