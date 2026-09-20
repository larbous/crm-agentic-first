<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConfiguracaoRepository;
use App\Repositories\ItemPropostaRepository;
use App\Repositories\Repositorios;

/**
 * Propostas e contratos no ActionExecutor: itens e totais, envio, aceite/recusa e assinatura (links públicos),
 * novas versões e renovação. É um trait para compartilhar as rotinas privadas do executor (transação,
 * eventos, auditoria) sem inchar a classe principal.
 *
 * @mixin ActionExecutor
 */
trait AcoesDocumentos
{
    // =====================================================================================
    // Itens e totais da proposta
    // =====================================================================================

    /**
     * Valida os itens (mesma whitelist/conversões do Schema "proposta_itens") e calcula o total de cada um:
     * quantidade × valor unitário − desconto. Linhas totalmente vazias são ignoradas.
     * @param array<string,string> $erros
     * @return list<array<string,mixed>>
     */
    private function normalizarItens(mixed $brutos, array &$erros): array
    {
        if (!is_array($brutos)) {
            $erros['itens'] = 'Itens inválidos.';
            return [];
        }
        $saida = [];
        foreach (array_values($brutos) as $i => $linha) {
            if (!is_array($linha)) {
                continue;
            }
            if (trim((string) ($linha['descricao'] ?? '')) === '' && trim((string) ($linha['valor_unitario'] ?? '')) === '' && empty($linha['servico_id'])) {
                continue;
            }
            $e = [];
            $limpo = $this->normalizar('proposta_itens', array_intersect_key($linha, Schema::gravaveis('proposta_itens')), true, $e);
            $n = $i + 1;
            if ($e !== []) {
                $erros['itens'] = "Item {$n}: " . implode(' ', $e);
                return [];
            }
            $quantidade = $limpo['quantidade'] ?? 1.0;
            $unitario = (int) ($limpo['valor_unitario'] ?? 0);
            $desconto = (int) ($limpo['desconto'] ?? 0);
            $bruto = (int) round($quantidade * $unitario);
            if ($quantidade <= 0) {
                $erros['itens'] = "Item {$n}: a quantidade deve ser maior que zero.";
                return [];
            }
            if ($desconto > $bruto) {
                $erros['itens'] = "Item {$n}: o desconto não pode ser maior que o valor do item.";
                return [];
            }
            $saida[] = [
                'servico_id'     => $limpo['servico_id'] ?? null,
                'descricao'      => $limpo['descricao'],
                'quantidade'     => (float) $quantidade,
                'unidade'        => $limpo['unidade'] ?? null,
                'valor_unitario' => $unitario,
                'desconto'       => $desconto,
                'total'          => $bruto - $desconto,
                'recorrente'     => (int) ($limpo['recorrente'] ?? 0),
            ];
        }
        if (count($saida) > 100) {
            $erros['itens'] = 'Uma proposta comporta no máximo 100 itens.';
        }
        return $saida;
    }

    /** Recorte dos itens usado na auditoria e na comparação. */
    private function snapshotItens(array $itens): array
    {
        return array_map(static fn (array $i): array => [
            'servico_id' => isset($i['servico_id']) ? (int) $i['servico_id'] : null, 'descricao' => $i['descricao'], 'quantidade' => (float) $i['quantidade'],
            'unidade' => $i['unidade'] ?? null, 'valor_unitario' => (int) $i['valor_unitario'], 'desconto' => (int) $i['desconto'],
            'total' => (int) $i['total'], 'recorrente' => (int) $i['recorrente'],
        ], array_values($itens));
    }

    /**
     * Subtotal, total e total recorrente. Desconto percentual em centésimos de % (750 = 7,5%).
     * @param array<string,string> $erros
     * @return array{subtotal:int,total:int,total_recorrente:int}
     */
    private function calcularTotais(array $itens, string $tipo, int $descontoValor, array &$erros): array
    {
        $subtotal = array_sum(array_map(static fn (array $i): int => (int) $i['total'], $itens));
        $recorrente = array_sum(array_map(static fn (array $i): int => (int) $i['recorrente'] === 1 ? (int) $i['total'] : 0, $itens));
        $desconto = $tipo === 'percentual' ? (int) round($subtotal * $descontoValor / 10000) : $descontoValor;
        if ($tipo === 'percentual' && $descontoValor > 10000) {
            $erros['desconto_valor'] = 'O desconto percentual não pode passar de 100%.';
        } elseif ($desconto > $subtotal) {
            $erros['desconto_valor'] = 'O desconto não pode ser maior que o subtotal.';
        }
        return ['subtotal' => $subtotal, 'total' => max(0, $subtotal - $desconto), 'total_recorrente' => $recorrente];
    }

    // =====================================================================================
    // Regras de criação/edição
    // =====================================================================================

    /** @return array<string,string> */
    private function regrasCriarProposta(array &$v, array &$extra): array
    {
        $erros = [];
        if (!empty($v['negocio_id'])) {
            $n = Repositorios::negocios()->encontrar((int) $v['negocio_id']);
            $v['empresa_id'] ??= $n['empresa_id'] ?? null;
            $v['contato_id'] ??= $n['contato_principal_id'] ?? null;
        }
        $modelo = null;
        if (!empty($v['modelo_id'])) {
            $modelo = Repositorios::modelos()->encontrar((int) $v['modelo_id']);
            if ($modelo === null || $modelo['tipo'] !== 'proposta') {
                $erros['modelo_id'] = 'Escolha um modelo do tipo proposta.';
            }
        }
        $v['data_emissao'] ??= hoje();
        $dias = (int) ((new ConfiguracaoRepository())->obter('proposta.validade_dias', '15') ?? 15);
        $v['validade'] ??= date('Y-m-d', strtotime($v['data_emissao'] . ' +' . max(1, $dias) . ' days'));
        if ($v['validade'] < $v['data_emissao']) {
            $erros['validade'] = 'A validade não pode ser anterior à data de emissão.';
        }

        $itens = $this->itensPendentes ?? [];
        $totais = $this->calcularTotais($itens, (string) ($v['desconto_tipo'] ?? 'valor'), (int) ($v['desconto_valor'] ?? 0), $erros);
        $this->itensGravar = $itens;

        $extra += $totais + [
            'numero' => Repositorios::propostas()->proximoNumero((int) date('Y')), 'versao' => 1,
            'status' => 'rascunho', 'token_publico' => token_publico(),
        ];

        if ($erros === [] && $modelo !== null && trim((string) ($v['apresentacao'] ?? '')) === '') {
            $ctx = Variaveis::contexto(['negocio_id' => $v['negocio_id'] ?? null, 'empresa_id' => $v['empresa_id'] ?? null, 'contato_id' => $v['contato_id'] ?? null]);
            $ctx['proposta'] = $v + $extra + ['itens' => $itens];
            $v['apresentacao'] = trim(Variaveis::renderizar((string) $modelo['conteudo'], $ctx, false));
        }
        return $erros;
    }

    /** @return array<string,string> */
    private function regrasAtualizarProposta(array $atual, array &$novos, array &$derivados): array
    {
        if ($atual['status'] !== 'rascunho') {
            return ['_' => 'Só propostas em rascunho podem ser editadas. Crie uma nova versão para alterar.'];
        }
        $erros = [];
        $itensRepo = new ItemPropostaRepository();
        $atuais = $itensRepo->porProposta((int) $atual['id']);
        $itens = $this->itensPendentes ?? $atuais;
        $totais = $this->calcularTotais(
            $itens,
            (string) ($novos['desconto_tipo'] ?? $atual['desconto_tipo']),
            (int) ($novos['desconto_valor'] ?? $atual['desconto_valor']),
            $erros,
        );
        $derivados += $totais;
        if ($this->itensPendentes !== null && $this->snapshotItens($this->itensPendentes) !== $this->snapshotItens($atuais)) {
            $this->itensGravar = $this->itensPendentes;
        }
        if (isset($novos['validade'], $atual['data_emissao']) && $novos['validade'] < ($novos['data_emissao'] ?? $atual['data_emissao'])) {
            $erros['validade'] = 'A validade não pode ser anterior à data de emissão.';
        }
        return $erros;
    }

    /** @return array<string,string> */
    private function regrasCriarContrato(array &$v, array &$extra): array
    {
        $erros = [];
        if (!empty($v['proposta_id'])) {
            $p = Repositorios::propostas()->encontrar((int) $v['proposta_id']);
            if ($p === null || $p['status'] !== 'aceita') {
                $erros['proposta_id'] = 'Só propostas aceitas podem virar contrato.';
            } else {
                $v['negocio_id'] ??= $p['negocio_id'];
                $v['empresa_id'] ??= $p['empresa_id'];
                $v['contato_id'] ??= $p['contato_id'];
                $v['valor_total'] ??= (int) $p['total'];
                if ((int) $p['total_recorrente'] > 0) {
                    $v['valor_mensal'] ??= (int) $p['total_recorrente'];
                }
            }
        }
        $modelo = null;
        if (!empty($v['modelo_id'])) {
            $modelo = Repositorios::modelos()->encontrar((int) $v['modelo_id']);
            if ($modelo === null || $modelo['tipo'] !== 'contrato') {
                $erros['modelo_id'] = 'Escolha um modelo do tipo contrato.';
            }
        }
        if (!empty($v['data_inicio']) && !empty($v['data_fim']) && $v['data_fim'] < $v['data_inicio']) {
            $erros['data_fim'] = 'O fim da vigência não pode ser anterior ao início.';
        }

        $extra += ['numero' => Repositorios::contratos()->proximoNumero((int) date('Y')), 'status' => 'rascunho', 'token_publico' => token_publico()];

        if ($erros === [] && $modelo !== null && trim((string) ($v['conteudo'] ?? '')) === '') {
            $ctx = Variaveis::contexto([
                'proposta_id' => $v['proposta_id'] ?? null, 'negocio_id' => $v['negocio_id'] ?? null,
                'empresa_id' => $v['empresa_id'] ?? null, 'contato_id' => $v['contato_id'] ?? null,
            ]);
            $ctx['contrato'] = $v + $extra;
            $v['conteudo'] = Html::sanitizar(Variaveis::renderizar((string) $modelo['conteudo'], $ctx, true));
        }
        return $erros;
    }

    /** @return array<string,string> */
    private function regrasAtualizarContrato(array $atual, array $novos): array
    {
        if ($atual['status'] !== 'rascunho') {
            return ['_' => 'Só contratos em rascunho podem ser editados. Cancele e crie outro, ou renove.'];
        }
        $ini = $novos['data_inicio'] ?? $atual['data_inicio'];
        $fim = $novos['data_fim'] ?? $atual['data_fim'];
        return $ini !== null && $fim !== null && $fim < $ini ? ['data_fim' => 'O fim da vigência não pode ser anterior ao início.'] : [];
    }

    // =====================================================================================
    // Auxiliares
    // =====================================================================================

    /** Atualiza campos controlados pelo servidor (status, datas, aceite) com auditoria. Devolve [registro novo, logId]. */
    private function gravarCampos(string $entidade, array $atual, array $campos, string $origem, string $acao): array
    {
        $alterados = [];
        foreach ($campos as $campo => $valor) {
            if ($this->diferente($atual[$campo] ?? null, $valor)) {
                $alterados[$campo] = $valor;
            }
        }
        $repo = Repositorios::para($entidade);
        if ($alterados === []) {
            return [$atual, null];
        }
        $repo->atualizar((int) $atual['id'], $alterados + ['atualizado_em' => agora()]);
        $logId = Audit::registrar($origem, $entidade, (int) $atual['id'], $acao, array_intersect_key($atual, $alterados), $alterados);
        return [$repo->encontrar((int) $atual['id'], true), $logId];
    }

    /** Atividade "proposta"/"contrato" na timeline. Precisa de ao menos um vínculo (negócio, empresa ou contato). */
    private function atividadeDocumento(string $tipo, string $assunto, ?string $descricao, array $doc): void
    {
        if (empty($doc['negocio_id']) && empty($doc['empresa_id']) && empty($doc['contato_id'])) {
            return;
        }
        $agora = agora();
        Repositorios::atividades()->inserir([
            'tipo' => $tipo, 'assunto' => $assunto, 'descricao' => $descricao, 'data_hora' => $agora,
            'empresa_id' => $doc['empresa_id'] ?? null, 'contato_id' => $doc['contato_id'] ?? null, 'negocio_id' => $doc['negocio_id'] ?? null,
            'criado_em' => $agora, 'atualizado_em' => $agora, 'criado_por' => 'sistema',
        ]);
    }

    private function documentoNaoEncontrado(string $rotulo): Resultado
    {
        return Resultado::erroGeral("{$rotulo} não encontrad" . ($rotulo === 'Proposta' ? 'a' : 'o') . '.');
    }

    /** CPF ou CNPJ válido (só dígitos) ou null. */
    private function documentoValido(string $texto): ?string
    {
        $d = so_digitos($texto);
        return (strlen($d) === 11 && cpf_valido($d)) || (strlen($d) === 14 && cnpj_valido($d)) ? $d : null;
    }

    // =====================================================================================
    // Propostas
    // =====================================================================================

    /** Rascunho → enviada (gera o link público). Exige itens e validade futura. */
    public function enviarProposta(int $id, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $p = Repositorios::propostas()->encontrar($id);
            if ($p === null) {
                return $this->documentoNaoEncontrado('Proposta');
            }
            if ($p['status'] !== 'rascunho') {
                return Resultado::erroGeral('Só propostas em rascunho podem ser enviadas.');
            }
            if ((new ItemPropostaRepository())->porProposta($id) === []) {
                return Resultado::erroGeral('Adicione ao menos um item antes de enviar a proposta.');
            }
            if ($p['validade'] !== null && $p['validade'] < hoje()) {
                return Resultado::erroGeral('A validade da proposta já passou. Ajuste a validade antes de enviar.');
            }
            [$novo, $logId] = $this->gravarCampos('propostas', $p, ['status' => 'enviada', 'enviada_em' => agora()], $origem, 'enviar_proposta');
            $this->atividadeDocumento('proposta', "Proposta {$p['numero']} v{$p['versao']} enviada", 'Total: ' . moeda((int) $p['total']), $p);
            $this->enfileirar('proposta.enviada', ['entidade' => 'propostas', 'id' => $id, 'origem' => $origem, 'registro' => $novo]);
            return Resultado::sucesso($id, "Proposta {$p['numero']} marcada como enviada.", $novo, $logId);
        });
    }

    /** Primeira abertura do link público: enviada → visualizada. Aberturas seguintes não geram registro. */
    public function registrarVisualizacaoProposta(int $id, string $origem = 'sistema'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $p = Repositorios::propostas()->encontrar($id);
            if ($p === null) {
                return $this->documentoNaoEncontrado('Proposta');
            }
            if ($p['status'] !== 'enviada') {
                return Resultado::sucesso($id, 'Nada a alterar.', $p);
            }
            [$novo, $logId] = $this->gravarCampos('propostas', $p, ['status' => 'visualizada', 'visualizada_em' => agora()], $origem, 'visualizar_proposta');
            $this->atividadeDocumento('proposta', "Proposta {$p['numero']} v{$p['versao']} visualizada pelo cliente", null, $p);
            $this->enfileirar('proposta.visualizada', ['entidade' => 'propostas', 'id' => $id, 'origem' => $origem, 'registro' => $novo]);
            return Resultado::sucesso($id, 'Visualização registrada.', $novo, $logId);
        });
    }

    /**
     * Resposta do cliente pelo link público. $resposta: aceita | recusada.
     * Aceite exige nome e CPF/CNPJ válidos; guarda o IP. Só a versão mais recente, dentro da validade, pode ser respondida.
     * @param array{nome?:string,documento?:string,ip?:string,motivo?:string} $dados
     */
    public function responderProposta(int $id, string $resposta, array $dados, string $origem = 'sistema'): Resultado
    {
        $this->validarOrigem($origem);
        if (!in_array($resposta, ['aceita', 'recusada'], true)) {
            return Resultado::erroGeral('Resposta inválida.');
        }
        return $this->transacao(function () use ($id, $resposta, $dados, $origem): Resultado {
            $p = Repositorios::propostas()->encontrar($id);
            if ($p === null) {
                return $this->documentoNaoEncontrado('Proposta');
            }
            if (!in_array($p['status'], ['enviada', 'visualizada'], true)) {
                return Resultado::erroGeral('Esta proposta não está aberta para resposta.');
            }
            if (proposta_expirada($p)) {
                return Resultado::erroGeral('Esta proposta expirou em ' . data_br($p['validade']) . '. Peça uma atualização à agência.');
            }
            if (Repositorios::propostas()->maiorVersao($p['numero']) > (int) $p['versao']) {
                return Resultado::erroGeral('Existe uma versão mais recente desta proposta. Use o link da versão atual.');
            }

            $campos = ['status' => $resposta, 'respondida_em' => agora()];
            if ($resposta === 'aceita') {
                $nome = trim((string) ($dados['nome'] ?? ''));
                $documento = $this->documentoValido((string) ($dados['documento'] ?? ''));
                $erros = [];
                if (mb_strlen($nome) < 3 || mb_strlen($nome) > 160 || !mb_check_encoding($nome, 'UTF-8')) {
                    $erros['nome'] = 'Informe seu nome completo.';
                }
                if ($documento === null) {
                    $erros['documento'] = 'Informe um CPF ou CNPJ válido.';
                }
                if ($erros !== []) {
                    return Resultado::falha($erros);
                }
                $campos += ['aceite_nome' => $nome, 'aceite_documento' => $documento, 'aceite_ip' => substr((string) ($dados['ip'] ?? ''), 0, 64)];
            } else {
                $motivo = trim((string) ($dados['motivo'] ?? ''));
                if (mb_strlen($motivo) > 500 || !mb_check_encoding($motivo, 'UTF-8')) {
                    return Resultado::falha(['motivo' => 'O motivo pode ter até 500 caracteres.']);
                }
                $campos['motivo_recusa'] = $motivo !== '' ? $motivo : null;
            }

            $acao = $resposta === 'aceita' ? 'aceitar_proposta' : 'recusar_proposta';
            [$novo, $logId] = $this->gravarCampos('propostas', $p, $campos, $origem, $acao);
            $this->atividadeDocumento(
                'proposta',
                "Proposta {$p['numero']} v{$p['versao']} " . ($resposta === 'aceita' ? 'aceita' : 'recusada') . ' pelo cliente',
                $resposta === 'aceita' ? 'Aceite por ' . $campos['aceite_nome'] : ($campos['motivo_recusa'] ?? null),
                $p,
            );
            $this->enfileirar($resposta === 'aceita' ? 'proposta.aceita' : 'proposta.recusada', ['entidade' => 'propostas', 'id' => $id, 'origem' => $origem, 'registro' => $novo]);
            return Resultado::sucesso($id, $resposta === 'aceita' ? 'Proposta aceita. Obrigado!' : 'Resposta registrada.', $novo, $logId);
        });
    }

    /** Nova versão (cópia com versao+1, em rascunho e com novo link). A versão anterior fica como histórico. */
    public function novaVersaoProposta(int $id, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $repo = Repositorios::propostas();
            $p = $repo->encontrar($id);
            if ($p === null) {
                return $this->documentoNaoEncontrado('Proposta');
            }
            if ($repo->maiorVersao($p['numero']) > (int) $p['versao']) {
                return Resultado::erroGeral('Já existe uma versão mais recente desta proposta.');
            }
            if ($p['status'] === 'rascunho') {
                return Resultado::erroGeral('Esta proposta ainda é um rascunho: edite-a diretamente.');
            }

            $itensRepo = new ItemPropostaRepository();
            $itens = $itensRepo->porProposta($id);
            $agora = agora();
            $dias = (int) ((new ConfiguracaoRepository())->obter('proposta.validade_dias', '15') ?? 15);
            $linha = array_intersect_key($p, array_flip([
                'negocio_id', 'empresa_id', 'contato_id', 'numero', 'titulo', 'modelo_id', 'subtotal', 'desconto_tipo', 'desconto_valor', 'total',
                'total_recorrente', 'forma_pagamento', 'condicoes_pagamento', 'parcelas', 'entrada_percentual', 'prazo_entrega_dias',
                'apresentacao', 'escopo', 'fora_escopo', 'cronograma', 'garantia', 'observacoes',
            ])) + [
                'versao' => (int) $p['versao'] + 1, 'status' => 'rascunho', 'data_emissao' => hoje(),
                'validade' => date('Y-m-d', strtotime('+' . max(1, $dias) . ' days')), 'token_publico' => token_publico(),
                'criado_em' => $agora, 'atualizado_em' => $agora, 'criado_por' => $origem,
            ];
            $novoId = $repo->inserir($linha);
            $itensRepo->substituir($novoId, $itens);
            $novo = $repo->encontrar($novoId);
            $depois = array_intersect_key($novo, array_flip($repo->colunas())) + ['_itens' => $this->snapshotItens($itens)];
            $logId = Audit::registrar($origem, 'propostas', $novoId, 'nova_versao', null, $depois);

            return Resultado::sucesso($novoId, "Nova versão {$novo['numero']} v{$novo['versao']} criada em rascunho.", $novo, $logId);
        });
    }

    // =====================================================================================
    // Contratos
    // =====================================================================================

    public function enviarContrato(int $id, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $c = Repositorios::contratos()->encontrar($id);
            if ($c === null) {
                return $this->documentoNaoEncontrado('Contrato');
            }
            if ($c['status'] !== 'rascunho') {
                return Resultado::erroGeral('Só contratos em rascunho podem ser enviados.');
            }
            if (trim(strip_tags((string) $c['conteudo'])) === '') {
                return Resultado::erroGeral('O contrato está sem conteúdo. Gere-o a partir de um modelo ou escreva o texto.');
            }
            [$novo, $logId] = $this->gravarCampos('contratos', $c, ['status' => 'enviado', 'enviado_em' => agora()], $origem, 'enviar_contrato');
            $this->atividadeDocumento('contrato', "Contrato {$c['numero']} enviado para assinatura", $c['titulo'], $c);
            return Resultado::sucesso($id, "Contrato {$c['numero']} marcado como enviado.", $novo, $logId);
        });
    }

    public function registrarVisualizacaoContrato(int $id, string $origem = 'sistema'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $c = Repositorios::contratos()->encontrar($id);
            if ($c === null) {
                return $this->documentoNaoEncontrado('Contrato');
            }
            if ($c['status'] !== 'enviado' || $c['visualizado_em'] !== null) {
                return Resultado::sucesso($id, 'Nada a alterar.', $c);
            }
            [$novo, $logId] = $this->gravarCampos('contratos', $c, ['visualizado_em' => agora()], $origem, 'visualizar_contrato');
            $this->atividadeDocumento('contrato', "Contrato {$c['numero']} visualizado pelo cliente", null, $c);
            return Resultado::sucesso($id, 'Visualização registrada.', $novo, $logId);
        });
    }

    /**
     * Assinatura pelo link público (nome + CPF/CNPJ + IP). Vai para "ativo" se a vigência já começou.
     * @param array{nome?:string,documento?:string,ip?:string} $dados
     */
    public function assinarContrato(int $id, array $dados, string $origem = 'sistema'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $dados, $origem): Resultado {
            $c = Repositorios::contratos()->encontrar($id);
            if ($c === null) {
                return $this->documentoNaoEncontrado('Contrato');
            }
            if ($c['status'] !== 'enviado') {
                return Resultado::erroGeral('Este contrato não está aberto para assinatura.');
            }
            $nome = trim((string) ($dados['nome'] ?? ''));
            $documento = $this->documentoValido((string) ($dados['documento'] ?? ''));
            $erros = [];
            if (mb_strlen($nome) < 3 || mb_strlen($nome) > 160 || !mb_check_encoding($nome, 'UTF-8')) {
                $erros['nome'] = 'Informe seu nome completo.';
            }
            if ($documento === null) {
                $erros['documento'] = 'Informe um CPF ou CNPJ válido.';
            }
            if ($erros !== []) {
                return Resultado::falha($erros);
            }
            $status = $c['data_inicio'] === null || $c['data_inicio'] <= hoje() ? 'ativo' : 'assinado';
            [$novo, $logId] = $this->gravarCampos('contratos', $c, [
                'status' => $status, 'assinado_em' => agora(), 'assinatura_nome' => $nome, 'assinatura_documento' => $documento,
                'assinatura_ip' => substr((string) ($dados['ip'] ?? ''), 0, 64),
            ], $origem, 'assinar_contrato');
            $this->atividadeDocumento('contrato', "Contrato {$c['numero']} assinado", "Assinado por {$nome}", $c);
            $this->enfileirar('contrato.assinado', ['entidade' => 'contratos', 'id' => $id, 'origem' => $origem, 'registro' => $novo]);
            return Resultado::sucesso($id, 'Contrato assinado. Obrigado!', $novo, $logId);
        });
    }

    public function cancelarContrato(int $id, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $c = Repositorios::contratos()->encontrar($id);
            if ($c === null) {
                return $this->documentoNaoEncontrado('Contrato');
            }
            if (!in_array($c['status'], ['rascunho', 'enviado', 'assinado', 'ativo'], true)) {
                return Resultado::erroGeral('Este contrato não pode ser cancelado.');
            }
            [$novo, $logId] = $this->gravarCampos('contratos', $c, ['status' => 'cancelado'], $origem, 'cancelar_contrato');
            $this->atividadeDocumento('contrato', "Contrato {$c['numero']} cancelado", null, $c);
            return Resultado::sucesso($id, "Contrato {$c['numero']} cancelado.", $novo, $logId);
        });
    }

    /**
     * Renovação: novo contrato em rascunho (contrato_origem_id = original) e original → "renovado".
     * A vigência nova começa no dia seguinte ao fim do original e mantém a mesma duração (ou 1 ano).
     */
    public function renovarContrato(int $id, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        return $this->transacao(function () use ($id, $origem): Resultado {
            $repo = Repositorios::contratos();
            $c = $repo->encontrar($id);
            if ($c === null) {
                return $this->documentoNaoEncontrado('Contrato');
            }
            if (!in_array($c['status'], ['assinado', 'ativo', 'vencido'], true)) {
                return Resultado::erroGeral('Só contratos assinados, ativos ou vencidos podem ser renovados.');
            }

            $inicio = $c['data_fim'] !== null ? date('Y-m-d', strtotime($c['data_fim'] . ' +1 day')) : hoje();
            $dias = $c['data_inicio'] !== null && $c['data_fim'] !== null ? dias_entre($c['data_inicio'], $c['data_fim']) : null;
            $fim = $dias !== null && $dias > 0
                ? date('Y-m-d', strtotime($inicio . ' +' . $dias . ' days'))
                : date('Y-m-d', strtotime($inicio . ' +1 year -1 day'));

            $agora = agora();
            $linha = array_intersect_key($c, array_flip([
                'tipo_id', 'empresa_id', 'contato_id', 'negocio_id', 'proposta_id', 'modelo_id', 'valor_total', 'valor_mensal', 'recorrencia',
                'dia_vencimento_pagamento', 'forma_pagamento', 'renovacao_automatica', 'aviso_renovacao_dias', 'indice_reajuste',
                'percentual_reajuste', 'multa_rescisoria', 'aviso_previo_dias', 'conteudo', 'clausulas_especiais', 'notas',
            ])) + [
                'numero' => $repo->proximoNumero((int) date('Y')), 'titulo' => preg_replace('/ \(renovação\)$/u', '', $c['titulo']) . ' (renovação)',
                'status' => 'rascunho', 'data_inicio' => $inicio, 'data_fim' => $fim, 'contrato_origem_id' => $id,
                'token_publico' => token_publico(), 'criado_em' => $agora, 'atualizado_em' => $agora, 'criado_por' => $origem,
            ];
            $novoId = $repo->inserir($linha);
            $novo = $repo->encontrar($novoId);
            $logNovo = Audit::registrar($origem, 'contratos', $novoId, 'criar', null, array_intersect_key($novo, array_flip($repo->colunas())));
            [, $logId] = $this->gravarCampos('contratos', $c, ['status' => 'renovado'], $origem, 'renovar_contrato');
            $this->atividadeDocumento('contrato', "Contrato {$c['numero']} renovado", "Novo contrato: {$novo['numero']}", $c);

            return Resultado::sucesso($novoId, "Contrato renovado: {$novo['numero']} criado em rascunho.", $novo, $logNovo ?? $logId);
        });
    }
}
