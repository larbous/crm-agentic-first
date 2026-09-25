<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Repositories\Repositorios;

/**
 * Integração de entrada com o Opensquad (squads de IA que rodam fora do CRM). Cada evento vira uma transação:
 * ou tudo o que ele pede é gravado, ou nada. Origem `opensquad:<squad>`, que não dispara agentes/squads do CRM
 * (ver Gatilhos) — o trabalho de pesquisa, qualificação e documentos já foi feito no Opensquad.
 *
 * Tipos de evento (SPEC §13):
 * - `lead`: localiza ou cria a empresa (e o contato), completa só campos vazios, garante um negócio aberto e o
 *   avança para a etapa pedida — nunca retrocede.
 * - `ganho`: como `lead`, mas leva o negócio aberto para a etapa de ganho (exige valor_fechado) e converte a
 *   empresa em cliente. Se o negócio já estava ganho, não cria outro.
 * - `perdido`: move o negócio aberto de uma empresa existente para a etapa de perda, com motivo.
 * - `atividade`: só registra nota e tarefas numa empresa existente.
 * Todos aceitam `nota` (vira atividade) e `tarefas` (lista).
 */
trait AcoesIntegracao
{
    /** Campos de empresa que a integração não define (status e cliente_desde mudam só por ganho/conversão). */
    private static array $empresaBloqueados = ['status', 'origem_id', 'cliente_desde', 'campos_extras', 'asaas_customer_id'];

    private static array $contatoBloqueados = ['empresa_id', 'origem_id', 'status', 'campos_extras', 'ultimo_contato_em'];

    private static array $negocioPermitidos = [
        'titulo', 'valor_estimado', 'tipo_receita', 'valor_recorrente', 'previsao_fechamento', 'temperatura', 'prioridade',
        'dor_principal', 'objetivo_cliente', 'orcamento_cliente', 'prazo_desejado', 'criterio_decisao', 'concorrentes',
        'proximo_passo', 'proximo_passo_em', 'notas',
    ];

    private const LIMITE_TAREFAS_EVENTO = 20;

    /**
     * Roda $fn (que processa um lote de eventos) numa transação sempre desfeita no fim: nada é gravado e nenhum evento
     * é disparado. Os eventos do lote enxergam os anteriores (origem criada uma vez, códigos em sequência), então o
     * operador vê exatamente o que o envio real faria.
     */
    public function simularIntegracao(callable $fn): mixed
    {
        $pdo = DB::conexao();
        $pdo->beginTransaction();
        try {
            return $fn();
        } finally {
            $pdo->rollBack();
            $this->eventos = [];
        }
    }

    /**
     * Processa um evento do Opensquad, tudo ou nada. Dentro de uma simulação usa um savepoint, para que um evento
     * recusado não deixe rastro para os seguintes do mesmo lote.
     * Sucesso: registro = empresa_id, contato_id, negocio_id, acoes (o que foi feito) e avisos (o que foi ignorado).
     */
    public function processarEventoIntegracao(string $squad, array $evento): Resultado
    {
        $origem = 'opensquad:' . $squad;
        $this->validarOrigem($origem);

        $executar = function () use ($evento, $origem): Resultado {
            $ctx = ['acoes' => [], 'avisos' => [], 'empresa' => null, 'contato_id' => null, 'negocio' => null];
            try {
                match ((string) ($evento['tipo'] ?? '')) {
                    'lead'      => $this->integrarLead($evento, $origem, $ctx),
                    'ganho'     => $this->integrarGanho($evento, $origem, $ctx),
                    'perdido'   => $this->integrarPerdido($evento, $origem, $ctx),
                    'atividade' => $this->integrarAtividade($evento, $ctx),
                    default     => throw new IntegracaoRecusada('Tipo de evento desconhecido: "' . ($evento['tipo'] ?? '') . '". Use lead, ganho, perdido ou atividade.'),
                };
                $this->integrarNotaETarefas($evento, $origem, $ctx);
            } catch (IntegracaoRecusada $e) {
                return Resultado::erroGeral($e->getMessage());
            }
            return Resultado::sucesso($ctx['negocio']['id'] ?? $ctx['empresa']['id'] ?? null, implode('; ', $ctx['acoes']), [
                'empresa_id' => $ctx['empresa']['id'] ?? null,
                'contato_id' => $ctx['contato_id'],
                'negocio_id' => $ctx['negocio']['id'] ?? null,
                'acoes'      => $ctx['acoes'],
                'avisos'     => $ctx['avisos'],
            ]);
        };

        $pdo = DB::conexao();
        if (!$pdo->inTransaction()) {
            return $this->transacao($executar);
        }
        $pdo->exec('SAVEPOINT integracao_evento');
        try {
            $r = $executar();
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT integracao_evento');
            $pdo->exec('RELEASE SAVEPOINT integracao_evento');
            throw $e;
        }
        if (!$r->ok) {
            $pdo->exec('ROLLBACK TO SAVEPOINT integracao_evento');
        }
        $pdo->exec('RELEASE SAVEPOINT integracao_evento');
        return $r;
    }

    // ---- Tipos de evento ----------------------------------------------------------------------

    private function integrarLead(array $evento, string $origem, array &$ctx): void
    {
        $this->integrarEmpresaEContato($evento, $origem, true, $ctx);
        $dados = (array) ($evento['negocio'] ?? []);
        $etapaNome = isset($dados['etapa']) ? trim((string) $dados['etapa']) : '';

        $aberto = Repositorios::negocios()->ultimoDaEmpresa((int) $ctx['empresa']['id'], ['aberto', 'pausado']);
        if ($aberto === null) {
            $pipelineId = $this->pipelinePadraoIntegracao();
            $etapa = $etapaNome !== '' ? $this->etapaAbertaPorNome($pipelineId, $etapaNome) : Repositorios::etapas()->primeiraAberta($pipelineId);
            $this->criarNegocioIntegracao($dados, $etapa, $evento, $origem, $ctx);
            return;
        }

        $ctx['negocio'] = $aberto;
        $this->completarNegocio($aberto, $dados, $origem, $ctx);
        if ($etapaNome === '') {
            $ctx['acoes'][] = "negócio {$aberto['codigo']} mantido em {$aberto['etapa_nome']}";
            return;
        }
        $destino = $this->etapaAbertaPorNome((int) $aberto['pipeline_id'], $etapaNome);
        $atual = Repositorios::etapas()->encontrar((int) $aberto['etapa_id'], true);
        if ((int) $destino['id'] === (int) $aberto['etapa_id']) {
            $ctx['acoes'][] = "negócio {$aberto['codigo']} já está em {$destino['nome']}";
        } elseif ($atual !== null && (int) $destino['ordem'] < (int) $atual['ordem']) {
            $ctx['avisos'][] = "negócio {$aberto['codigo']} já está em {$atual['nome']}; a integração não retrocede para {$destino['nome']}";
        } else {
            $this->exigirOk($this->moverEtapa((int) $aberto['id'], ['etapa_id' => (int) $destino['id']], $origem), 'Mover negócio');
            $ctx['acoes'][] = "negócio {$aberto['codigo']}: {$aberto['etapa_nome']} → {$destino['nome']}";
        }
        $ctx['negocio'] = Repositorios::negocios()->encontrar((int) $aberto['id']);
    }

    private function integrarGanho(array $evento, string $origem, array &$ctx): void
    {
        $valor = $evento['valor_fechado'] ?? null;
        if ($valor === null || $valor === '') {
            throw new IntegracaoRecusada('Informe valor_fechado (em reais) para marcar o negócio como ganho.');
        }
        $this->integrarEmpresaEContato($evento, $origem, true, $ctx);
        $empresaId = (int) $ctx['empresa']['id'];
        $dados = (array) ($evento['negocio'] ?? []);
        $fechamento = ['valor_fechado' => $valor];

        $aberto = Repositorios::negocios()->ultimoDaEmpresa($empresaId, ['aberto', 'pausado']);
        if ($aberto !== null) {
            $this->completarNegocio($aberto, $dados, $origem, $ctx);
            $ganho = Repositorios::etapas()->primeiraDoTipo((int) $aberto['pipeline_id'], 'ganho')
                ?? throw new IntegracaoRecusada('O pipeline do negócio não tem etapa de ganho.');
            $this->exigirOk($this->moverEtapa((int) $aberto['id'], ['etapa_id' => (int) $ganho['id']] + $fechamento, $origem), 'Ganhar negócio');
            $ctx['negocio'] = Repositorios::negocios()->encontrar((int) $aberto['id']);
            $ctx['acoes'][] = "negócio {$aberto['codigo']}: {$aberto['etapa_nome']} → {$ganho['nome']} (" . moeda($ctx['negocio']['valor_fechado']) . ')';
        } elseif (($jaGanho = Repositorios::negocios()->ultimoDaEmpresa($empresaId, ['ganho'])) !== null) {
            $ctx['negocio'] = $jaGanho;
            $ctx['avisos'][] = "negócio {$jaGanho['codigo']} já estava ganho; nenhum negócio novo criado";
        } else {
            $ganho = Repositorios::etapas()->primeiraDoTipo($this->pipelinePadraoIntegracao(), 'ganho')
                ?? throw new IntegracaoRecusada('O pipeline padrão não tem etapa de ganho.');
            $this->criarNegocioIntegracao($dados + $fechamento, $ganho, $evento, $origem, $ctx);
        }

        if (($evento['converter_cliente'] ?? true) !== false && $ctx['empresa']['status'] !== 'cliente') {
            $this->exigirOk($this->converterCliente($empresaId, $origem), 'Converter em cliente');
            $ctx['acoes'][] = "empresa convertida em cliente";
        }
    }

    private function integrarPerdido(array $evento, string $origem, array &$ctx): void
    {
        $motivoNome = trim((string) ($evento['motivo'] ?? ''));
        if ($motivoNome === '') {
            throw new IntegracaoRecusada('Informe o motivo da perda (campo motivo).');
        }
        $this->exigirEmpresaExistente($evento, $ctx);
        $empresaId = (int) $ctx['empresa']['id'];

        $aberto = Repositorios::negocios()->ultimoDaEmpresa($empresaId, ['aberto', 'pausado']);
        if ($aberto === null) {
            $perdido = Repositorios::negocios()->ultimoDaEmpresa($empresaId, ['perdido']);
            if ($perdido === null) {
                throw new IntegracaoRecusada("A empresa {$ctx['empresa']['nome_fantasia']} não tem negócio aberto para marcar como perdido.");
            }
            $ctx['negocio'] = $perdido;
            $ctx['avisos'][] = "negócio {$perdido['codigo']} já estava perdido";
            return;
        }
        $etapa = Repositorios::etapas()->primeiraDoTipo((int) $aberto['pipeline_id'], 'perdido')
            ?? throw new IntegracaoRecusada('O pipeline do negócio não tem etapa de perda.');
        $motivoId = $this->idPorNomeIntegracao('motivos_perda', $motivoNome, $origem, $ctx);
        $dados = ['etapa_id' => (int) $etapa['id'], 'motivo_perda_id' => $motivoId];
        if (trim((string) ($evento['detalhe'] ?? '')) !== '') {
            $dados['detalhe_perda'] = (string) $evento['detalhe'];
        }
        $this->exigirOk($this->moverEtapa((int) $aberto['id'], $dados, $origem), 'Perder negócio');
        $ctx['negocio'] = Repositorios::negocios()->encontrar((int) $aberto['id']);
        $ctx['acoes'][] = "negócio {$aberto['codigo']}: {$aberto['etapa_nome']} → {$etapa['nome']} (motivo: {$motivoNome})";
    }

    private function integrarAtividade(array $evento, array &$ctx): void
    {
        $this->exigirEmpresaExistente($evento, $ctx);
        $ctx['negocio'] = Repositorios::negocios()->ultimoDaEmpresa((int) $ctx['empresa']['id'], ['aberto', 'pausado']);
        if (empty($evento['nota']) && empty($evento['tarefas'])) {
            throw new IntegracaoRecusada('Evento "atividade" sem nota e sem tarefas: nada a registrar.');
        }
    }

    // ---- Empresa, contato e negócio -----------------------------------------------------------

    /** Localiza a empresa (e o contato) ou cria; em quem já existe, só preenche campos vazios. */
    private function integrarEmpresaEContato(array $evento, string $origem, bool $criarSeFaltar, array &$ctx): void
    {
        $dadosEmpresa = $this->filtrarCampos('empresas', (array) ($evento['empresa'] ?? []), self::$empresaBloqueados, 'empresa', $ctx);
        $dadosContato = $this->filtrarCampos('contatos', (array) ($evento['contato'] ?? []), self::$contatoBloqueados, 'contato', $ctx);
        if (trim((string) ($dadosEmpresa['nome_fantasia'] ?? '')) === '') {
            throw new IntegracaoRecusada('Informe empresa.nome_fantasia.');
        }

        [$empresa, $contato, $por] = $this->localizarEmpresaIntegracao($dadosEmpresa, $dadosContato);
        $origemId = trim((string) ($evento['origem'] ?? '')) !== '' ? $this->idPorNomeIntegracao('origens', (string) $evento['origem'], $origem, $ctx) : null;

        if ($empresa !== null) {
            $empresa = Repositorios::empresas()->encontrar((int) $empresa['id']);
            $vazios = $this->somenteVazios($empresa, $dadosEmpresa + ($origemId !== null ? ['origem_id' => $origemId] : []));
            unset($vazios['nome_fantasia']);
            if (isset($vazios['cnpj']) && Repositorios::empresas()->porCnpj(so_digitos((string) $vazios['cnpj']), (int) $empresa['id']) !== null) {
                unset($vazios['cnpj']);
                $ctx['avisos'][] = 'CNPJ informado já pertence a outra empresa; não foi gravado';
            }
            if ($vazios !== []) {
                $this->exigirOk($this->atualizar('empresas', (int) $empresa['id'], $vazios, $origem), 'Completar empresa');
            }
            $ctx['acoes'][] = "empresa já cadastrada: {$empresa['nome_fantasia']} (#{$empresa['id']}, igual por {$por})"
                . ($vazios !== [] ? ', ' . count($vazios) . ' campo(s) vazio(s) completado(s)' : '');
        } elseif (!$criarSeFaltar) {
            throw new IntegracaoRecusada("Empresa \"{$dadosEmpresa['nome_fantasia']}\" não encontrada no CRM.");
        } else {
            $novos = $dadosEmpresa + ($origemId !== null ? ['origem_id' => $origemId] : []);
            if (isset($novos['cnpj']) && Repositorios::empresas()->porCnpj(so_digitos((string) $novos['cnpj'])) !== null) {
                unset($novos['cnpj']);
            }
            $r = $this->exigirOk($this->criar('empresas', $novos, $origem), 'Criar empresa');
            $empresa = ['id' => (int) $r->id];
            $ctx['acoes'][] = "empresa nova: {$dadosEmpresa['nome_fantasia']}";
        }
        $ctx['empresa'] = Repositorios::empresas()->encontrar((int) $empresa['id']);
        $ctx['origem_id'] = $origemId;

        if ($dadosContato === []) {
            return;
        }
        if ($contato !== null) {
            $contato = Repositorios::contatos()->encontrar((int) $contato['id']);
            $novos = $dadosContato + ($contato['empresa_id'] === null ? ['empresa_id' => (int) $ctx['empresa']['id']] : []);
            $vazios = $this->somenteVazios($contato, $novos);
            unset($vazios['nome']);
            if ($vazios !== []) {
                $this->exigirOk($this->atualizar('contatos', (int) $contato['id'], $vazios, $origem), 'Completar contato');
            }
            $ctx['contato_id'] = (int) $contato['id'];
            $ctx['acoes'][] = "contato já cadastrado: {$contato['nome']}";
        } elseif (trim((string) ($dadosContato['nome'] ?? '')) === '') {
            $ctx['avisos'][] = 'contato sem nome não foi criado (telefone/e-mail ficam só na empresa, se informados lá)';
        } else {
            $novos = $dadosContato + ['empresa_id' => (int) $ctx['empresa']['id']] + ($origemId !== null ? ['origem_id' => $origemId] : []);
            $r = $this->exigirOk($this->criar('contatos', $novos, $origem), 'Criar contato');
            $ctx['contato_id'] = (int) $r->id;
            $ctx['acoes'][] = "contato novo: {$dadosContato['nome']}";
        }
    }

    /** Para perdido/atividade: a empresa tem de existir; nada é criado nem completado. */
    private function exigirEmpresaExistente(array $evento, array &$ctx): void
    {
        $dadosEmpresa = $this->filtrarCampos('empresas', (array) ($evento['empresa'] ?? []), self::$empresaBloqueados, 'empresa', $ctx);
        $nome = trim((string) ($dadosEmpresa['nome_fantasia'] ?? ''));
        [$empresa] = $this->localizarEmpresaIntegracao($dadosEmpresa, []);
        if ($empresa === null) {
            throw new IntegracaoRecusada('Empresa' . ($nome !== '' ? " \"{$nome}\"" : '') . ' não encontrada no CRM (informe CNPJ, e-mail, telefone, site ou nome + cidade).');
        }
        $ctx['empresa'] = Repositorios::empresas()->encontrar((int) $empresa['id']);
    }

    /**
     * Procura quem já existe, na ordem: CNPJ → e-mail → WhatsApp/telefone → domínio do site → nome + cidade.
     * @return array{0:?array,1:?array,2:?string} empresa, contato e o critério que casou
     */
    private function localizarEmpresaIntegracao(array $e, array $c): array
    {
        $empresas = Repositorios::empresas();
        $contatos = Repositorios::contatos();
        $contato = null;

        if (!empty($e['cnpj']) && ($empresa = $empresas->porCnpj(so_digitos((string) $e['cnpj']))) !== null) {
            return [$empresa, $this->contatoPorCanais($c), 'CNPJ'];
        }
        foreach (array_filter([$e['email_geral'] ?? null, $c['email'] ?? null]) as $email) {
            $contato ??= $contatos->porEmail((string) $email);
            if (($empresa = $empresas->porEmailGeral((string) $email)) !== null) {
                return [$empresa, $contato ?? $this->contatoPorCanais($c), 'e-mail'];
            }
        }
        foreach (array_filter([$e['whatsapp'] ?? null, $e['telefone'] ?? null, $c['whatsapp'] ?? null, $c['telefone'] ?? null]) as $tel) {
            $d = so_digitos((string) $tel);
            $contato ??= $contatos->porTelefone($d);
            if (($empresa = $empresas->porTelefone($d)) !== null) {
                return [$empresa, $contato, 'telefone'];
            }
        }
        if ($contato !== null && $contato['empresa_id'] !== null && ($empresa = $empresas->encontrar((int) $contato['empresa_id'])) !== null) {
            return [$empresa, $contato, 'contato'];
        }
        $dominio = dominio_de((string) ($e['site'] ?? $e['dominio'] ?? ''));
        if ($dominio !== '' && ($empresa = $empresas->porDominio($dominio)) !== null) {
            return [$empresa, $contato, 'domínio do site'];
        }
        if (!empty($e['nome_fantasia']) && !empty($e['cidade'])
            && ($empresa = $empresas->porNomeECidade((string) $e['nome_fantasia'], (string) $e['cidade'])) !== null) {
            return [$empresa, $contato, 'nome e cidade'];
        }
        return [null, $contato, null];
    }

    private function contatoPorCanais(array $c): ?array
    {
        $contatos = Repositorios::contatos();
        foreach (array_filter([$c['email'] ?? null]) as $email) {
            if (($achado = $contatos->porEmail((string) $email)) !== null) {
                return $achado;
            }
        }
        foreach (array_filter([$c['whatsapp'] ?? null, $c['telefone'] ?? null]) as $tel) {
            if (($achado = $contatos->porTelefone(so_digitos((string) $tel))) !== null) {
                return $achado;
            }
        }
        return null;
    }

    private function criarNegocioIntegracao(array $dados, array $etapa, array $evento, string $origem, array &$ctx): void
    {
        $empresa = $ctx['empresa'];
        $novos = $this->filtrarNegocio($dados, $ctx);
        if (array_key_exists('valor_fechado', $dados)) {
            $novos['valor_fechado'] = $dados['valor_fechado'];
        }
        $novos['titulo'] ??= $this->tituloPadraoNegocio($empresa);
        $novos += ['empresa_id' => (int) $empresa['id'], 'etapa_id' => (int) $etapa['id']];
        if ($ctx['contato_id'] !== null) {
            $novos['contato_principal_id'] = $ctx['contato_id'];
        }
        if (($ctx['origem_id'] ?? null) !== null) {
            $novos['origem_id'] = $ctx['origem_id'];
        }
        $r = $this->exigirOk($this->criar('negocios', $novos, $origem), 'Criar negócio');
        $ctx['negocio'] = Repositorios::negocios()->encontrar((int) $r->id);
        $ctx['acoes'][] = "negócio novo {$ctx['negocio']['codigo']} em {$etapa['nome']}";
    }

    /** Preenche só campos vazios do negócio existente (e o contato principal, se ainda não tiver). */
    private function completarNegocio(array $negocio, array $dados, string $origem, array &$ctx): void
    {
        $novos = $this->filtrarNegocio($dados, $ctx);
        $titulo = $novos['titulo'] ?? null;
        unset($novos['titulo']);
        if ($ctx['contato_id'] !== null) {
            $novos['contato_principal_id'] = $ctx['contato_id'];
        }
        $vazios = $this->somenteVazios($negocio, $novos);
        // O título genérico foi a própria integração que pôs (lead sem título); um título de verdade o substitui.
        if ($titulo !== null && $negocio['titulo'] === $this->tituloPadraoNegocio($ctx['empresa']) && $titulo !== $negocio['titulo']) {
            $vazios['titulo'] = $titulo;
        }
        if ($vazios !== []) {
            $this->exigirOk($this->atualizar('negocios', (int) $negocio['id'], $vazios, $origem), 'Completar negócio');
        }
    }

    private function tituloPadraoNegocio(array $empresa): string
    {
        return mb_substr('Oportunidade — ' . $empresa['nome_fantasia'], 0, 200);
    }

    private function filtrarNegocio(array $dados, array &$ctx): array
    {
        $saida = [];
        foreach ($dados as $campo => $valor) {
            if ($campo === 'etapa' || $campo === 'valor_fechado') {
                continue;
            }
            if (!in_array($campo, self::$negocioPermitidos, true)) {
                $ctx['avisos'][] = "campo negocio.{$campo} ignorado";
                continue;
            }
            if ($valor !== null && $valor !== '') {
                $saida[$campo] = $valor;
            }
        }
        return $saida;
    }

    /** Campos conhecidos do Schema, fora os bloqueados; o resto vira aviso (não derruba o evento). */
    private function filtrarCampos(string $entidade, array $dados, array $bloqueados, string $prefixo, array &$ctx): array
    {
        $campos = Schema::entidade($entidade)['campos'];
        $saida = [];
        foreach ($dados as $campo => $valor) {
            $def = $campos[$campo] ?? null;
            if ($def === null || !empty($def['sis']) || in_array($campo, $bloqueados, true)) {
                $ctx['avisos'][] = "campo {$prefixo}.{$campo} ignorado";
                continue;
            }
            if ($valor !== null && $valor !== '' && !is_array($valor)) {
                $saida[$campo] = $valor;
            }
        }
        return $saida;
    }

    // ---- Nota e tarefas ---------------------------------------------------------------------------

    private function integrarNotaETarefas(array $evento, string $origem, array &$ctx): void
    {
        $vinculos = array_filter([
            'empresa_id' => $ctx['empresa']['id'] ?? null,
            'contato_id' => $ctx['contato_id'],
            'negocio_id' => $ctx['negocio']['id'] ?? null,
        ], static fn ($v) => $v !== null);

        if (!empty($evento['nota'])) {
            $nota = is_array($evento['nota']) ? $evento['nota'] : ['descricao' => (string) $evento['nota']];
            $dados = array_intersect_key($nota, array_flip(['tipo', 'assunto', 'descricao', 'direcao', 'resultado', 'data_hora']));
            $dados['tipo'] = Schema::chaveDoEnum('tipo_atividade', (string) ($dados['tipo'] ?? 'nota')) ?? 'nota';
            if (isset($dados['direcao'])) {
                $dados['direcao'] = Schema::chaveDoEnum('direcao', (string) $dados['direcao']);
            }
            $this->exigirOk($this->criar('atividades', $dados + $vinculos, $origem), 'Registrar nota');
            $ctx['acoes'][] = 'nota registrada' . (isset($dados['assunto']) ? ": {$dados['assunto']}" : '');
        }

        $tarefas = $evento['tarefas'] ?? [];
        if (!is_array($tarefas) || !array_is_list($tarefas)) {
            throw new IntegracaoRecusada('O campo tarefas deve ser uma lista.');
        }
        if (count($tarefas) > self::LIMITE_TAREFAS_EVENTO) {
            throw new IntegracaoRecusada('Máximo de ' . self::LIMITE_TAREFAS_EVENTO . ' tarefas por evento.');
        }
        foreach ($tarefas as $i => $t) {
            $t = (array) $t;
            $dados = array_intersect_key($t, array_flip(['titulo', 'descricao', 'vencimento']));
            $dados['tipo'] = Schema::chaveDoEnum('tipo_tarefa', (string) ($t['tipo'] ?? 'followup')) ?? 'followup';
            $dados['prioridade'] = Schema::chaveDoEnum('prioridade_tar', (string) ($t['prioridade'] ?? 'media')) ?? 'media';
            if (!isset($dados['vencimento']) && isset($t['em_dias'])) {
                $dias = filter_var($t['em_dias'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3650]]);
                if ($dias === false) {
                    throw new IntegracaoRecusada('Tarefa ' . ($i + 1) . ': em_dias deve ser um número inteiro de 0 a 3650.');
                }
                $dados['vencimento'] = date('Y-m-d', strtotime(hoje() . " +{$dias} days"));
            }
            $this->exigirOk($this->criar('tarefas', $dados + $vinculos, $origem), 'Tarefa ' . ($i + 1));
        }
        if ($tarefas !== []) {
            $ctx['acoes'][] = count($tarefas) === 1 ? '1 tarefa criada' : count($tarefas) . ' tarefas criadas';
        }
    }

    // ---- Apoio ------------------------------------------------------------------------------------

    private function pipelinePadraoIntegracao(): int
    {
        $pipeline = Repositorios::pipelines()->padrao() ?? throw new IntegracaoRecusada('Configure um pipeline padrão no CRM.');
        return (int) $pipeline['id'];
    }

    private function etapaAbertaPorNome(int $pipelineId, string $nome): array
    {
        $etapa = Repositorios::etapas()->porNome($pipelineId, $nome);
        if ($etapa === null) {
            $nomes = array_column(Repositorios::etapas()->doPipeline($pipelineId), 'nome');
            throw new IntegracaoRecusada("Etapa \"{$nome}\" não existe no pipeline. Etapas: " . implode(', ', $nomes) . '.');
        }
        if ($etapa['tipo'] !== 'aberta') {
            throw new IntegracaoRecusada("A etapa \"{$etapa['nome']}\" encerra o negócio: use o evento \"{$etapa['tipo']}\".");
        }
        return $etapa;
    }

    /** Id de uma origem ou motivo de perda pelo nome; cria se ainda não existir. */
    private function idPorNomeIntegracao(string $entidade, string $nome, string $origem, array &$ctx): int
    {
        $nome = mb_substr(trim($nome), 0, 80);
        $achado = Repositorios::para($entidade)->porNome($nome);
        if ($achado !== null) {
            return (int) $achado['id'];
        }
        $r = $this->exigirOk($this->criar($entidade, ['nome' => $nome], $origem), 'Criar ' . Schema::entidade($entidade)['singular']);
        $ctx['acoes'][] = mb_strtolower(Schema::entidade($entidade)['singular']) . " nova: {$nome}";
        return (int) $r->id;
    }

    private function exigirOk(Resultado $r, string $contexto): Resultado
    {
        if (!$r->ok) {
            throw new IntegracaoRecusada("{$contexto}: {$r->mensagem}");
        }
        return $r;
    }
}
