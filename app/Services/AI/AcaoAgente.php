<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Repositories\ContextoAgenteRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Resultado;
use App\Services\Schema;

/**
 * Ações que um agente pode pedir (SPEC §6.1): validação contra `acoes_permitidas` e `campos_gravaveis` do agente,
 * contra a whitelist do Schema e contra o alcance do registro-alvo. A IA nunca informa ids: o registro afetado é
 * resolvido pelo servidor (o alvo e seus relacionados) e nomes (origem, etapa, modelo, serviço…) viram ids aqui.
 * A execução é sempre pelo ActionExecutor. Não há arquivar, desfazer nem consulta para agentes.
 */
final class AcaoAgente
{
    public const ACOES = ['atualizar', 'criar', 'nota', 'tarefa', 'mover_etapa', 'converter_cliente'];

    /** Entidades que `atualizar` alcança (o alvo ou um registro relacionado a ele). */
    public const ATUALIZAVEIS = ['empresas', 'contatos', 'negocios', 'propostas', 'contratos'];

    public const CRIAVEIS = ['contatos', 'negocios', 'propostas', 'contratos', 'tarefas'];

    /** Nomes aceitos em `campos_gravaveis` além dos campos do Schema: a IA informa o nome, o servidor resolve o id. */
    public const PSEUDOS = ['origem', 'etapa', 'motivo_perda', 'modelo', 'tipo_contrato', 'itens', 'negocio'];

    private const TIPOS_NOTA = ['nota', 'ligacao', 'whatsapp', 'email', 'reuniao', 'visita'];
    private const CAMPOS_NOTA = ['descricao', 'assunto', 'tipo'];
    private const CAMPOS_TAREFA = ['titulo', 'descricao', 'vencimento', 'prioridade', 'tipo', 'negocio'];
    private const CAMPOS_MOVER = ['etapa', 'valor_fechado', 'motivo_perda', 'detalhe_perda'];
    private const CAMPOS_ITEM = ['servico', 'descricao', 'quantidade', 'unidade', 'valor_unitario', 'desconto', 'recorrente'];

    /**
     * Registros alcançáveis a partir do alvo: entidade => id (ou null). `alvo` é a entidade do próprio alvo.
     * @return array{alvo:?string,empresas:?int,contatos:?int,negocios:?int,propostas:?int,contratos:?int}
     */
    public static function cadeia(?string $entidade, ?int $id): array
    {
        $c = ['alvo' => $entidade, 'empresas' => null, 'contatos' => null, 'negocios' => null, 'propostas' => null, 'contratos' => null];
        if ($entidade === null || $id === null) {
            return $c;
        }
        $registro = Repositorios::para($entidade)->encontrar($id);
        if ($registro === null) {
            return $c;
        }
        $c[$entidade] = $id;
        $ctx = new ContextoAgenteRepository();
        switch ($entidade) {
            case 'contatos':
                $c['empresas'] = self::inteiro($registro['empresa_id'] ?? null);
                break;
            case 'negocios':
                $c['empresas'] = self::inteiro($registro['empresa_id'] ?? null);
                $c['contatos'] = self::inteiro($registro['contato_principal_id'] ?? null);
                $c['propostas'] = self::inteiro($ctx->propostaDoNegocio($id)['id'] ?? null);
                $c['contratos'] = self::inteiro($ctx->contratoDoNegocio($id)['id'] ?? null);
                break;
            case 'propostas':
                $c['negocios'] = self::inteiro($registro['negocio_id'] ?? null);
                $c['empresas'] = self::inteiro($registro['empresa_id'] ?? null);
                $c['contatos'] = self::inteiro($registro['contato_id'] ?? null);
                break;
            case 'contratos':
                $c['propostas'] = self::inteiro($registro['proposta_id'] ?? null);
                $c['negocios'] = self::inteiro($registro['negocio_id'] ?? null);
                $c['empresas'] = self::inteiro($registro['empresa_id'] ?? null);
                $c['contatos'] = self::inteiro($registro['contato_id'] ?? null);
                break;
        }
        return $c;
    }

    /**
     * @param array $def definição do agente (já validada)
     * @return array{ok:true,plano:array}|array{ok:false,motivo:string}
     *   plano: acao, entidade, dados (o que a IA pediu, normalizado), servidor {acao, entidade, registro_id, dados} (o que será executado)
     */
    public static function validar(mixed $bruta, array $def, array $cadeia): array
    {
        if (!is_array($bruta) || array_is_list($bruta)) {
            return self::recusa('ação não é um objeto');
        }
        $acao = $bruta['acao'] ?? null;
        if (!is_string($acao) || !in_array($acao, self::ACOES, true)) {
            return self::recusa('ação fora da whitelist: ' . (is_string($acao) ? $acao : '?'));
        }
        if (!in_array($acao, (array) $def['acoes_permitidas'], true)) {
            return self::recusa("o agente não pode usar a ação \"{$acao}\"");
        }
        $entidade = match ($acao) {
            'nota' => 'atividades', 'tarefa' => 'tarefas', 'mover_etapa' => 'negocios', 'converter_cliente' => 'empresas',
            default => is_string($bruta['entidade'] ?? null) ? $bruta['entidade'] : '',
        };
        $permitidas = match ($acao) { 'atualizar' => self::ATUALIZAVEIS, 'criar' => self::CRIAVEIS, default => [$entidade] };
        if (!in_array($entidade, $permitidas, true)) {
            return self::recusa("entidade não permitida para \"{$acao}\": " . ($entidade !== '' ? $entidade : '?'));
        }
        $dados = $bruta['dados'] ?? [];
        if ($dados === null || $dados === []) {
            $dados = [];
        } elseif (!is_array($dados) || array_is_list($dados)) {
            return self::recusa('dados inválidos');
        }

        $registroId = null;
        if (in_array($acao, ['atualizar', 'mover_etapa', 'converter_cliente'], true)) {
            $registroId = $cadeia[$entidade] ?? null;
            if ($registroId === null) {
                return self::recusa("não há " . mb_strtolower(Schema::entidade($entidade)['singular']) . ' relacionado ao registro em análise');
            }
        }

        $campos = (array) $def['campos_gravaveis'];
        $vinculos = [];
        switch ($acao) {
            case 'converter_cliente':
                $servidorDados = [];
                break;
            case 'mover_etapa':
                $r = self::dadosMover($dados, $campos, (int) $registroId);
                break;
            case 'nota':
                $r = self::dadosSimples('atividades', $dados, self::CAMPOS_NOTA, ['descricao']);
                $vinculos = self::vinculos('atividades', $cadeia);
                if ($vinculos === []) {
                    return self::recusa('a nota exige um registro-alvo');
                }
                break;
            case 'tarefa':
                $r = self::dadosSimples('tarefas', $dados, self::CAMPOS_TAREFA, ['titulo']);
                $vinculos = self::vinculos('tarefas', $cadeia);
                if (is_array($r) && isset($r['servidor']['negocio'])) {
                    $negocio = (new ContextoAgenteRepository())->negocioPorCodigo((string) $r['servidor']['negocio']);
                    if ($negocio === null) {
                        return self::recusa('negócio não encontrado: ' . $r['servidor']['negocio']);
                    }
                    $vinculos = ['negocio_id' => (int) $negocio['id']] + ($negocio['empresa_id'] ? ['empresa_id' => (int) $negocio['empresa_id']] : []);
                    unset($r['servidor']['negocio']);
                }
                break;
            default: // atualizar, criar
                $r = self::dadosGravaveis($entidade, $dados, $campos, $acao === 'criar');
                if ($acao === 'criar') {
                    $vinculos = self::vinculos($entidade, $cadeia);
                }
        }
        if (isset($r) && is_string($r)) {
            return self::recusa($r);
        }
        if (isset($r)) {
            $servidorDados = $r['servidor'];
            $dados = $r['humano'];
        }
        if ($acao === 'atualizar' && $servidorDados === []) {
            return self::recusa('a ação não altera nenhum campo');
        }

        return ['ok' => true, 'plano' => [
            'acao' => $acao, 'entidade' => $entidade, 'dados' => $dados,
            'servidor' => ['acao' => $acao, 'entidade' => $entidade, 'registro_id' => $registroId, 'dados' => $servidorDados + $vinculos],
        ]];
    }

    /** Executa uma ação já validada (o campo `servidor`) pelo ActionExecutor. */
    public static function aplicar(array $servidor, string $origem, ?ActionExecutor $executor = null): Resultado
    {
        $executor ??= new ActionExecutor();
        $dados = (array) ($servidor['dados'] ?? []);
        $id = isset($servidor['registro_id']) ? (int) $servidor['registro_id'] : null;
        $entidade = (string) ($servidor['entidade'] ?? '');
        return match ($servidor['acao'] ?? '') {
            'criar' => $executor->criar($entidade, $dados, $origem),
            'nota' => $executor->criar('atividades', $dados + ['tipo' => 'nota'], $origem),
            'tarefa' => $executor->criar('tarefas', $dados, $origem),
            'atualizar' => $id === null ? Resultado::erroGeral('Registro não informado.') : $executor->atualizar($entidade, $id, $dados, $origem),
            'mover_etapa' => $id === null ? Resultado::erroGeral('Registro não informado.') : $executor->moverEtapa($id, $dados, $origem),
            'converter_cliente' => $id === null ? Resultado::erroGeral('Registro não informado.') : $executor->converterCliente($id, $origem),
            default => Resultado::erroGeral('Ação não permitida.'),
        };
    }

    /** Frase curta que descreve a ação (lista de pendentes, resposta do chat). */
    public static function descrever(array $plano): string
    {
        $entidade = (string) $plano['entidade'];
        $singular = mb_strtolower(Schema::entidade($entidade)['singular'] ?? $entidade);
        $dados = (array) $plano['dados'];
        $registroId = $plano['servidor']['registro_id'] ?? null;
        $nome = '';
        if ($registroId !== null) {
            $reg = Repositorios::para($entidade)->encontrar((int) $registroId);
            $nome = $reg !== null ? ' "' . self::nomeDe($reg) . '"' : '';
        }
        $rotulos = static function () use ($entidade, $dados): string {
            $schema = Schema::entidade($entidade)['campos'] ?? [];
            $r = [];
            foreach (array_keys($dados) as $campo) {
                $r[] = mb_strtolower($schema[$campo]['r'] ?? self::ROTULO_PSEUDO[$campo] ?? $campo);
            }
            return implode(', ', array_slice($r, 0, 6));
        };
        return match ($plano['acao']) {
            'atualizar' => "Atualizar {$singular}{$nome}: " . $rotulos(),
            'criar' => "Criar {$singular}" . (isset($dados['titulo']) ? ' "' . mb_strimwidth((string) $dados['titulo'], 0, 80, '…') . '"' : (isset($dados['nome']) ? ' "' . $dados['nome'] . '"' : '')),
            'nota' => 'Registrar nota: ' . mb_strimwidth(trim((string) ($dados['assunto'] ?? $dados['descricao'] ?? '')), 0, 80, '…'),
            'tarefa' => 'Criar tarefa "' . mb_strimwidth((string) ($dados['titulo'] ?? ''), 0, 80, '…') . '"',
            'mover_etapa' => "Mover negócio{$nome} para \"" . ($dados['etapa'] ?? '?') . '"',
            'converter_cliente' => "Converter empresa{$nome} em cliente",
            default => 'Ação',
        };
    }

    public const ROTULO_PSEUDO = [
        'origem' => 'Origem', 'etapa' => 'Etapa', 'motivo_perda' => 'Motivo da perda', 'modelo' => 'Modelo',
        'tipo_contrato' => 'Tipo de contrato', 'negocio' => 'Negócio', 'itens' => 'Itens',
    ];

    /**
     * Antes/depois para o componente diff (valores em formatos que `formatar_valor_campo` entende: dinheiro em centavos).
     * @return array{antes:?array,depois:array,entidade:string}
     */
    public static function previa(array $def): array
    {
        $servidor = (array) ($def['servidor'] ?? []);
        $entidade = (string) ($def['entidade'] ?? '');
        $humano = (array) ($def['dados'] ?? []);
        $campos = Schema::entidade($entidade)['campos'] ?? [];

        $depois = [];
        foreach ($humano as $campo => $valor) {
            if ($campo === 'itens') {
                $depois['_itens'] = self::snapshotItens((array) $valor);
            } elseif (isset($campos[$campo]) && $campos[$campo]['t'] === 'money' && is_numeric($valor)) {
                $depois[$campo] = reais_para_centavos($valor);
            } else {
                $depois[$campo] = $valor;
            }
        }

        $antes = null;
        $id = $servidor['registro_id'] ?? null;
        if ($id !== null && ($def['acao'] ?? '') === 'atualizar') {
            $reg = Repositorios::para($entidade)->encontrar((int) $id);
            if ($reg !== null) {
                $antes = array_intersect_key($reg, $depois);
                if (isset($depois['_itens'])) {
                    $antes['_itens'] = array_map(static fn (array $i): array => [
                        'descricao' => $i['descricao'], 'total' => (int) $i['total'],
                    ], (new ItemPropostaRepository())->porProposta((int) $id));
                }
            }
        }
        if ($id !== null && ($def['acao'] ?? '') === 'mover_etapa') {
            $reg = Repositorios::negocios()->encontrar((int) $id);
            $antes = $reg !== null ? ['etapa' => (string) ($reg['etapa_nome'] ?? '')] : null;
        }
        return ['antes' => $antes, 'depois' => $depois, 'entidade' => $entidade];
    }

    // =====================================================================================

    /** @return array{humano:array,servidor:array}|string */
    private static function dadosGravaveis(string $entidade, array $bruto, array $permitidos, bool $criar): array|string
    {
        $schema = Schema::gravaveis($entidade);
        $humano = $servidor = [];
        foreach ($bruto as $chave => $valor) {
            if (!is_string($chave) || !in_array($chave, $permitidos, true)) {
                return 'campo fora de campos_gravaveis do agente: ' . (is_string($chave) ? $chave : '?');
            }
            if (in_array($chave, self::PSEUDOS, true)) {
                $r = self::pseudo($entidade, $chave, $valor);
                if (is_string($r)) {
                    return $r;
                }
                $humano[$chave] = $r['humano'];
                $servidor = $r['servidor'] + $servidor;
                if (isset($r['itens'])) {
                    $servidor['itens'] = $r['itens'];
                }
                continue;
            }
            if (!isset($schema[$chave]) || $schema[$chave]['t'] === 'fk') {
                return "campo inexistente ou não gravável em {$entidade}: {$chave}";
            }
            $limpo = self::escalar($schema[$chave], $chave, $valor);
            if (is_string($limpo) && str_starts_with($limpo, "\0erro:")) {
                return substr($limpo, 6);
            }
            $humano[$chave] = $limpo;
            $servidor[$chave] = $limpo;
        }
        if ($criar) {
            foreach (['titulo', 'nome', 'nome_fantasia'] as $identificador) {
                if (!empty($schema[$identificador]['req']) && trim((string) ($servidor[$identificador] ?? '')) === '') {
                    return "dados.{$identificador} é obrigatório para criar {$entidade}";
                }
            }
        }
        return ['humano' => $humano, 'servidor' => $servidor];
    }

    /** Nota/tarefa: campos fixos, sem depender de campos_gravaveis. */
    private static function dadosSimples(string $entidade, array $bruto, array $permitidos, array $obrigatorios): array|string
    {
        $schema = Schema::gravaveis($entidade);
        $humano = $servidor = [];
        foreach ($bruto as $chave => $valor) {
            if (!is_string($chave) || !in_array($chave, $permitidos, true)) {
                return "campo não permitido em {$entidade}: " . (is_string($chave) ? $chave : '?');
            }
            if ($chave === 'negocio') {
                if (!is_string($valor) || trim($valor) === '') {
                    return 'dados.negocio inválido';
                }
                $humano[$chave] = $servidor[$chave] = trim($valor);
                continue;
            }
            if ($chave === 'tipo' && $entidade === 'atividades') {
                $tipo = is_string($valor) ? Schema::chaveDoEnum('tipo_atividade', $valor) : null;
                if ($tipo === null || !in_array($tipo, self::TIPOS_NOTA, true)) {
                    return 'tipo de nota inválido';
                }
                $humano[$chave] = $servidor[$chave] = $tipo;
                continue;
            }
            $limpo = self::escalar($schema[$chave], $chave, $valor);
            if (is_string($limpo) && str_starts_with($limpo, "\0erro:")) {
                return substr($limpo, 6);
            }
            $humano[$chave] = $servidor[$chave] = $limpo;
        }
        foreach ($obrigatorios as $campo) {
            if (!isset($humano[$campo]) || trim((string) $humano[$campo]) === '') {
                return "dados.{$campo} é obrigatório";
            }
        }
        return ['humano' => $humano, 'servidor' => $servidor];
    }

    private static function dadosMover(array $bruto, array $permitidos, int $negocioId): array|string
    {
        if (!in_array('etapa', $permitidos, true)) {
            return 'mover_etapa exige "etapa" em campos_gravaveis';
        }
        $schema = Schema::gravaveis('negocios');
        $humano = $servidor = [];
        foreach ($bruto as $chave => $valor) {
            if (!is_string($chave) || !in_array($chave, self::CAMPOS_MOVER, true) || !in_array($chave, $permitidos, true)) {
                return 'campo não permitido em mover_etapa: ' . (is_string($chave) ? $chave : '?');
            }
            if ($chave === 'etapa' || $chave === 'motivo_perda') {
                $r = self::pseudo('negocios', $chave, $valor, $negocioId);
                if (is_string($r)) {
                    return $r;
                }
                $humano[$chave] = $r['humano'];
                $servidor = $r['servidor'] + $servidor;
                continue;
            }
            $limpo = self::escalar($schema[$chave], $chave, $valor);
            if (is_string($limpo) && str_starts_with($limpo, "\0erro:")) {
                return substr($limpo, 6);
            }
            $humano[$chave] = $servidor[$chave] = $limpo;
        }
        if (!isset($servidor['etapa_id'])) {
            return 'mover_etapa exige dados.etapa';
        }
        return ['humano' => $humano, 'servidor' => $servidor];
    }

    /**
     * Nome → id. Devolve o valor exibido, os campos prontos para o ActionExecutor (e `itens` normalizados).
     * @return array{humano:mixed,servidor:array,itens?:array}|string
     */
    private static function pseudo(string $entidade, string $chave, mixed $valor, ?int $negocioId = null): array|string
    {
        if ($chave === 'itens') {
            if ($entidade !== 'propostas') {
                return 'itens só existem em propostas';
            }
            return self::itens($valor);
        }
        if (!is_string($valor) || trim($valor) === '' || mb_strlen($valor) > 160) {
            return "dados.{$chave} inválido";
        }
        $nome = trim($valor);
        switch ($chave) {
            case 'origem':
                $linha = self::porNome(Repositorios::para('origens')->todas(), $nome);
                return $linha === null ? "origem inexistente: {$nome}" : ['humano' => $linha['nome'], 'servidor' => ['origem_id' => (int) $linha['id']]];
            case 'motivo_perda':
                $linha = self::porNome(Repositorios::para('motivos_perda')->todas(), $nome);
                return $linha === null ? "motivo de perda inexistente: {$nome}" : ['humano' => $linha['nome'], 'servidor' => ['motivo_perda_id' => (int) $linha['id']]];
            case 'etapa':
                $negocio = $negocioId !== null ? Repositorios::negocios()->encontrar($negocioId) : null;
                $etapas = $negocio !== null ? Repositorios::etapas()->doPipeline((int) $negocio['pipeline_id']) : [];
                $linha = self::porNome($etapas, $nome);
                return $linha === null ? "etapa inexistente: {$nome}" : ['humano' => $linha['nome'], 'servidor' => ['etapa_id' => (int) $linha['id']]];
            case 'modelo':
                $tipo = $entidade === 'contratos' ? 'contrato' : ($entidade === 'propostas' ? 'proposta' : null);
                if ($tipo === null) {
                    return 'modelo só se aplica a propostas e contratos';
                }
                $opcoes = [];
                foreach (Repositorios::modelos()->opcoesPorTipo($tipo) as $id => $n) {
                    $opcoes[] = ['id' => $id, 'nome' => $n];
                }
                $linha = self::porNome($opcoes, $nome);
                return $linha === null ? "modelo de {$tipo} inexistente: {$nome}" : ['humano' => $linha['nome'], 'servidor' => ['modelo_id' => (int) $linha['id']]];
            case 'tipo_contrato':
                if ($entidade !== 'contratos') {
                    return 'tipo_contrato só se aplica a contratos';
                }
                $linha = self::porNome(Repositorios::para('contrato_tipos')->todas(), $nome);
                return $linha === null ? "tipo de contrato inexistente: {$nome}" : ['humano' => $linha['nome'], 'servidor' => ['tipo_id' => (int) $linha['id']]];
        }
        return "campo não aplicável: {$chave}";
    }

    /** @return array{humano:array,servidor:array,itens:array}|string */
    private static function itens(mixed $bruto): array|string
    {
        if (!is_array($bruto) || !array_is_list($bruto) || $bruto === [] || count($bruto) > 50) {
            return 'itens deve ser uma lista de 1 a 50 linhas';
        }
        $catalogo = Repositorios::servicos()->ativos();
        $saida = [];
        foreach ($bruto as $i => $linha) {
            $n = $i + 1;
            if (!is_array($linha) || array_is_list($linha)) {
                return "item {$n} inválido";
            }
            foreach ($linha as $k => $v) {
                if (!is_string($k) || !in_array($k, self::CAMPOS_ITEM, true) || (!is_scalar($v) && $v !== null)) {
                    return "item {$n}: campo não permitido " . (is_string($k) ? $k : '?');
                }
            }
            $item = [];
            $servico = null;
            if (isset($linha['servico']) && trim((string) $linha['servico']) !== '') {
                $servico = self::porNome($catalogo, (string) $linha['servico']);
                if ($servico === null && trim((string) ($linha['descricao'] ?? '')) === '') {
                    return "item {$n}: serviço fora do catálogo: " . $linha['servico'];
                }
            }
            if ($servico !== null) {
                $item['servico_id'] = (int) $servico['id'];
                $item['descricao'] = trim((string) ($linha['descricao'] ?? '')) !== '' ? trim((string) $linha['descricao']) : $servico['nome'];
                $item['unidade'] = $linha['unidade'] ?? (Schema::opcoes('unidade_servico')[$servico['unidade']] ?? null);
                $item['valor_unitario'] = $linha['valor_unitario'] ?? ($servico['preco_base'] !== null ? $servico['preco_base'] / 100 : null);
                $item['recorrente'] = $linha['recorrente'] ?? (int) $servico['recorrente'];
            } else {
                $item['descricao'] = trim((string) ($linha['descricao'] ?? ''));
                $item['unidade'] = $linha['unidade'] ?? null;
                $item['valor_unitario'] = $linha['valor_unitario'] ?? null;
                $item['recorrente'] = $linha['recorrente'] ?? 0;
            }
            if ($item['descricao'] === '' || mb_strlen($item['descricao']) > 300) {
                return "item {$n}: descrição inválida";
            }
            $item['quantidade'] = $linha['quantidade'] ?? 1;
            $item['desconto'] = $linha['desconto'] ?? 0;
            foreach (['quantidade', 'valor_unitario', 'desconto'] as $num) {
                if ($item[$num] !== null && !is_numeric($item[$num])) {
                    return "item {$n}: {$num} deve ser numérico";
                }
            }
            $saida[] = array_filter($item, static fn ($v): bool => $v !== null);
        }
        return ['humano' => $saida, 'servidor' => [], 'itens' => $saida];
    }

    /** Aceita escalar (texto/número/booleano), respeitando enum e tamanho. Erro volta como string com prefixo "\0erro:". */
    private static function escalar(array $def, string $chave, mixed $valor): mixed
    {
        if ($valor !== null && !is_scalar($valor)) {
            return "\0erro:valor não escalar em dados.{$chave}";
        }
        $limite = ($def['t'] ?? '') === 'html' ? 200000 : 5000;
        if (is_string($valor) && mb_strlen($valor) > $limite) {
            return "\0erro:valor grande demais em dados.{$chave}";
        }
        if (($def['t'] ?? '') === 'enum' && $valor !== null && $valor !== '') {
            $chaveEnum = Schema::chaveDoEnum((string) $def['op'], (string) $valor);
            return $chaveEnum ?? "\0erro:valor inválido em dados.{$chave}";
        }
        return is_string($valor) ? trim($valor) : $valor;
    }

    /** Vínculos do registro criado (nota/tarefa/criar) com base no alvo e seus relacionados. */
    private static function vinculos(string $entidade, array $c): array
    {
        $alvo = $c['alvo'];
        $v = [];
        switch ($entidade) {
            case 'atividades':
            case 'tarefas':
                if ($c['empresas'] !== null) {
                    $v['empresa_id'] = $c['empresas'];
                }
                if ($c['negocios'] !== null) {
                    $v['negocio_id'] = $c['negocios'];
                }
                if ($alvo === 'contatos' && $c['contatos'] !== null) {
                    $v['contato_id'] = $c['contatos'];
                }
                if ($entidade === 'tarefas' && $alvo === 'contratos' && $c['contratos'] !== null) {
                    $v['contrato_id'] = $c['contratos'];
                }
                break;
            case 'negocios':
                $v = array_filter(['empresa_id' => $c['empresas'], 'contato_principal_id' => $c['contatos']], static fn ($x): bool => $x !== null);
                break;
            case 'contatos':
                $v = array_filter(['empresa_id' => $c['empresas']], static fn ($x): bool => $x !== null);
                break;
            case 'propostas':
                $v = array_filter(['empresa_id' => $c['empresas'], 'contato_id' => $c['contatos'], 'negocio_id' => $c['negocios']], static fn ($x): bool => $x !== null);
                break;
            case 'contratos':
                $v = array_filter([
                    'empresa_id' => $c['empresas'], 'contato_id' => $c['contatos'], 'negocio_id' => $c['negocios'], 'proposta_id' => $c['propostas'],
                ], static fn ($x): bool => $x !== null);
                break;
        }
        return $v;
    }

    /** Linha cujo "nome" é igual ao texto (sem acento/caixa) ou, se não houver, a única que o contém. */
    private static function porNome(array $linhas, string $nome): ?array
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

    private static function snapshotItens(array $itens): array
    {
        return array_map(static function (array $i): array {
            $bruto = (int) round((float) ($i['quantidade'] ?? 1) * (float) ($i['valor_unitario'] ?? 0) * 100);
            return ['descricao' => (string) $i['descricao'], 'total' => max(0, $bruto - (int) round((float) ($i['desconto'] ?? 0) * 100))];
        }, array_values($itens));
    }

    private static function nomeDe(array $registro): string
    {
        return (string) ($registro['nome_fantasia'] ?? $registro['titulo'] ?? trim(($registro['nome'] ?? '') . ' ' . ($registro['sobrenome'] ?? '')));
    }

    private static function inteiro(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    private static function recusa(string $motivo): array
    {
        return ['ok' => false, 'motivo' => $motivo];
    }
}
