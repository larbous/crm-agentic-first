<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ConversaRepository;
use App\Repositories\MensagemRepository;
use App\Repositories\Repositorios;
use App\Services\AI\Transcritor;
use App\Services\Canais\Canais;
use App\Services\Canais\ContatoResolver;
use App\Services\Canais\Email;
use App\Services\Canais\Meta;
use PDOException;

/**
 * Caixa de entrada unificada (Fase 14) pelo ActionExecutor: receber mensagem (webhook ou e-mail), enviar resposta,
 * atualizar o status de entrega e organizar conversas. Conversa e mensagem são o próprio registro (como submissões e
 * pesquisas, sem log_auditoria); cada mensagem de uma conversa ligada a um contato ou empresa vira uma atividade da
 * timeline, escrita pelo caminho normal (com `ultimo_contato_em` e o evento `atividade.criada`).
 */
trait AcoesCaixa
{
    /**
     * Registra uma mensagem recebida. Idempotente: o mesmo `id_externo` nunca entra duas vezes (a Meta reenvia webhooks).
     * @param array $m canal, identificador, nome, id_externo, tipo, texto, midia, data_hora, assunto (e-mail)
     */
    public function receberMensagem(array $m): Resultado
    {
        $canal = (string) ($m['canal'] ?? '');
        $identificador = trim((string) ($m['identificador'] ?? ''));
        if (!isset(Canais::ROTULOS[$canal]) || $identificador === '' || trim((string) ($m['id_externo'] ?? '')) === '') {
            return Resultado::erroGeral('Mensagem sem canal, remetente ou identificador.');
        }
        $mensagens = new MensagemRepository();
        if ($mensagens->porIdExterno((string) $m['id_externo']) !== null) {
            return Resultado::sucesso(null, 'Mensagem já registrada.', ['duplicada' => true]);
        }

        try {
            return $this->transacao(function () use ($m, $canal, $identificador, $mensagens): Resultado {
                $conversas = new ConversaRepository();
                $agora = agora();
                $tipo = (string) ($m['tipo'] ?? 'texto');
                $texto = isset($m['texto']) && $m['texto'] !== '' ? mb_substr((string) $m['texto'], 0, 20000) : null;
                $dataHora = (string) ($m['data_hora'] ?? $agora);
                $previa = mb_substr(preg_replace('/\s+/u', ' ', Canais::descricao($tipo, $texto)) ?? '', 0, 140);

                $conversa = $conversas->porIdentificador($canal, $identificador);
                $ligacao = $conversa !== null && ($conversa['contato_id'] !== null || $conversa['empresa_id'] !== null)
                    ? ['contato_id' => $conversa['contato_id'], 'empresa_id' => $conversa['empresa_id']]
                    : ContatoResolver::resolver($canal, $identificador);
                if ($conversa === null) {
                    $id = $conversas->inserir([
                        'canal' => $canal, 'identificador' => $identificador, 'nome' => ($m['nome'] ?? null) ?: null,
                        'contato_id' => $ligacao['contato_id'], 'empresa_id' => $ligacao['empresa_id'], 'assunto' => ($m['assunto'] ?? null) ?: null,
                        'status' => 'aberta', 'nao_lidas' => 0, 'criado_em' => $agora, 'atualizado_em' => $agora, 'criado_por' => 'sistema',
                    ]);
                } else {
                    $id = (int) $conversa['id'];
                }

                $mensagemId = $mensagens->inserir([
                    'conversa_id' => $id, 'direcao' => 'entrada', 'tipo' => $tipo, 'texto' => $texto,
                    'midia' => !empty($m['midia']) ? json_encode($m['midia'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'id_externo' => (string) $m['id_externo'], 'status' => 'recebida', 'data_hora' => $dataHora, 'criado_em' => $agora,
                    // Áudio com mídia baixável entra na fila de transcrição do worker (Fase 15).
                    'transcricao_status' => $tipo === 'audio' && !empty($m['midia']) ? 'pendente' : null,
                ]);

                $atual = $conversas->encontrar($id);
                $novo = ['status' => 'aberta', 'ultima_entrada_em' => max($dataHora, (string) $atual['ultima_entrada_em']), 'atualizado_em' => $agora];
                if ($atual['ultima_mensagem_em'] === null || $dataHora >= $atual['ultima_mensagem_em']) {
                    $novo += ['ultima_mensagem_em' => $dataHora, 'ultima_direcao' => 'entrada', 'ultima_previa' => $previa];
                }
                if (($atual['nome'] === null || $atual['nome'] === '') && !empty($m['nome'])) {
                    $novo['nome'] = (string) $m['nome'];
                }
                if (!empty($m['assunto'])) {
                    $novo['assunto'] = mb_substr((string) $m['assunto'], 0, 200);
                }
                if ($atual['contato_id'] === null && $atual['empresa_id'] === null) {
                    $novo += ['contato_id' => $ligacao['contato_id'], 'empresa_id' => $ligacao['empresa_id']];
                }
                $conversas->atualizar($id, $novo);
                $conversas->somarNaoLidas($id, 1);

                $conversa = $conversas->encontrar($id);
                $atividadeId = $this->atividadeDaMensagem($conversa, $mensagens->encontrar($mensagemId));
                if ($atividadeId !== null) {
                    $mensagens->atualizar($mensagemId, ['atividade_id' => $atividadeId]);
                }

                $alvo = $conversa['contato_id'] !== null ? ['contatos', (int) $conversa['contato_id']] : ($conversa['empresa_id'] !== null ? ['empresas', (int) $conversa['empresa_id']] : null);
                $this->enfileirar('mensagem.recebida', [
                    'evento' => 'mensagem.recebida', 'entidade' => $alvo[0] ?? '', 'id' => $alvo[1] ?? 0, 'origem' => 'sistema', 'registro' => [],
                    'conversa_id' => $id, 'mensagem_id' => $mensagemId, 'canal' => $canal, 'tipo' => $tipo,
                ]);
                return Resultado::sucesso($mensagemId, 'Mensagem registrada.', ['conversa_id' => $id]);
            });
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') && str_contains($e->getMessage(), 'id_externo')) {
                return Resultado::sucesso(null, 'Mensagem já registrada.', ['duplicada' => true]); // corrida entre duas entregas do mesmo webhook
            }
            throw $e;
        }
    }

    /**
     * Envia uma resposta pelo canal da conversa. Em três passos (a chamada de rede fica fora da transação do SQLite, para não
     * segurar o banco): grava como "enviando", envia, grava o resultado. WhatsApp e Instagram só aceitam texto livre até
     * 24 h depois da última mensagem do cliente.
     */
    public function enviarMensagem(int $conversaId, string $texto, string $origem = 'humano'): Resultado
    {
        $this->validarOrigem($origem);
        $texto = trim($texto);
        $conversas = new ConversaRepository();
        $conversa = $conversas->encontrar($conversaId);
        if ($conversa === null) {
            return Resultado::erroGeral('Conversa não encontrada.');
        }
        if ($texto === '' || mb_strlen($texto) > 4000) {
            return Resultado::erroGeral('Escreva a mensagem (até 4000 caracteres).');
        }
        $canal = (string) $conversa['canal'];
        if (!Canais::ativo($canal)) {
            return Resultado::erroGeral('O canal ' . Canais::ROTULOS[$canal] . ' não está configurado (veja config.local.php).');
        }
        if (Canais::temJanela($canal)) {
            $ultima = (string) ($conversa['ultima_entrada_em'] ?? '');
            if ($ultima === '' || strtotime($ultima) < time() - Canais::JANELA_HORAS * 3600) {
                return Resultado::erroGeral('Passaram mais de ' . Canais::JANELA_HORAS . ' h desde a última mensagem do cliente: a ' . Canais::ROTULOS[$canal] . ' só permite mensagem livre dentro dessa janela (fora dela é preciso um modelo aprovado, que o CRM ainda não envia).');
            }
        }

        $mensagens = new MensagemRepository();
        $agora = agora();
        $mensagemId = $mensagens->inserir([
            'conversa_id' => $conversaId, 'direcao' => 'saida', 'tipo' => 'texto', 'texto' => $texto, 'status' => 'enviando',
            'data_hora' => $agora, 'criado_em' => $agora, 'criado_por' => $origem,
        ]);

        if ($canal === 'email') {
            $assunto = trim((string) $conversa['assunto']) !== '' ? (preg_match('/^re:/i', (string) $conversa['assunto']) === 1 ? (string) $conversa['assunto'] : 'Re: ' . $conversa['assunto']) : 'Contato';
            $respondeA = $this->ultimoIdEmailRecebido($conversaId);
            $envio = Email::enviar((string) $conversa['identificador'], $assunto, $texto, $respondeA);
        } else {
            $envio = Meta::enviar($canal, (string) $conversa['identificador'], $texto);
        }

        // A falha também precisa ser gravada (mensagem "falhou"): a transação termina com sucesso e o erro é devolvido depois.
        $gravado = $this->transacao(function () use ($envio, $mensagens, $mensagemId, $conversas, $conversaId, $conversa, $texto, $agora): Resultado {
            if (!$envio['ok']) {
                $mensagens->atualizar($mensagemId, ['status' => 'falhou', 'erro' => $envio['erro']]);
                return Resultado::sucesso($mensagemId, 'Falha registrada.');
            }
            $mensagens->atualizar($mensagemId, ['status' => 'enviada', 'id_externo' => $envio['id']]);
            $conversas->atualizar($conversaId, [
                'ultima_mensagem_em' => $agora, 'ultima_direcao' => 'saida', 'ultima_previa' => mb_substr(preg_replace('/\s+/u', ' ', $texto) ?? '', 0, 140), 'atualizado_em' => $agora,
            ]);
            $atualizada = $conversas->encontrar($conversaId);
            $atividadeId = $this->atividadeDaMensagem($atualizada, $mensagens->encontrar($mensagemId));
            if ($atividadeId !== null) {
                $mensagens->atualizar($mensagemId, ['atividade_id' => $atividadeId]);
            }
            return Resultado::sucesso($mensagemId, 'Mensagem enviada.', $mensagens->encontrar($mensagemId));
        });
        return $envio['ok'] ? $gravado : Resultado::erroGeral('Não foi possível enviar: ' . $envio['erro']);
    }

    /** Status de entrega do provedor (enviada → entregue → lida; falhou vale a qualquer momento). Nunca retrocede. */
    public function atualizarStatusMensagem(string $idExterno, string $status, ?string $erro = null): Resultado
    {
        $ordem = ['enviando' => 0, 'enviada' => 1, 'sent' => 1, 'entregue' => 2, 'delivered' => 2, 'lida' => 3, 'read' => 3];
        $mapa = ['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'];
        $novo = $mapa[$status] ?? $status;
        $mensagens = new MensagemRepository();
        $m = $mensagens->porIdExterno($idExterno);
        if ($m === null || $m['direcao'] !== 'saida') {
            return Resultado::sucesso(null, 'Nada a alterar.');
        }
        if ($novo === 'falhou') {
            $mensagens->atualizar((int) $m['id'], ['status' => 'falhou', 'erro' => $erro ?? 'Falha informada pelo provedor.']);
        } elseif (($ordem[$novo] ?? -1) > ($ordem[(string) $m['status']] ?? 0)) {
            $mensagens->atualizar((int) $m['id'], ['status' => $novo]);
        }
        return Resultado::sucesso((int) $m['id'], 'Status atualizado.');
    }

    public function marcarConversaLida(int $conversaId): Resultado
    {
        $conversas = new ConversaRepository();
        if ($conversas->encontrar($conversaId) === null) {
            return Resultado::erroGeral('Conversa não encontrada.');
        }
        $conversas->atualizar($conversaId, ['nao_lidas' => 0]);
        return Resultado::sucesso($conversaId, 'Marcada como lida.');
    }

    public function mudarStatusConversa(int $conversaId, string $status): Resultado
    {
        $conversas = new ConversaRepository();
        $conversa = $conversas->encontrar($conversaId);
        if ($conversa === null) {
            return Resultado::erroGeral('Conversa não encontrada.');
        }
        if (!in_array($status, ['aberta', 'resolvida'], true)) {
            return Resultado::erroGeral('Situação inválida.');
        }
        $novo = ['status' => $status, 'atualizado_em' => agora()];
        if ($status === 'resolvida') {
            $novo += ['nao_lidas' => 0];
            // Ao final do atendimento, o worker gera o resumo (só se houve troca de mensagens e nada mudou desde o último resumo).
            $jaResumida = $conversa['resumo_em'] !== null && (string) $conversa['resumo_em'] >= (string) $conversa['ultima_mensagem_em'];
            if (!$jaResumida && $conversa['ultima_entrada_em'] !== null && count((new MensagemRepository())->daConversa($conversaId, 2)) >= 2) {
                $novo += ['resumo_pendente' => 1, 'resumo_tentativas' => 0];
            }
        }
        $conversas->atualizar($conversaId, $novo);
        return Resultado::sucesso($conversaId, $status === 'resolvida' ? 'Conversa resolvida.' : 'Conversa reaberta.');
    }

    /** Liga a conversa a um contato (e à empresa dele) e leva o histórico para a timeline. */
    public function vincularConversa(int $conversaId, int $contatoId): Resultado
    {
        $conversas = new ConversaRepository();
        $conversa = $conversas->encontrar($conversaId);
        $contato = Repositorios::contatos()->encontrar($contatoId);
        if ($conversa === null || $contato === null) {
            return Resultado::erroGeral('Conversa ou contato não encontrado.');
        }
        return $this->transacao(function () use ($conversas, $conversaId, $contato): Resultado {
            $conversas->atualizar($conversaId, [
                'contato_id' => (int) $contato['id'], 'empresa_id' => $contato['empresa_id'] !== null ? (int) $contato['empresa_id'] : null, 'atualizado_em' => agora(),
            ]);
            $conversa = $conversas->encontrar($conversaId);
            $mensagens = new MensagemRepository();
            foreach ($mensagens->semAtividade($conversaId) as $mensagem) {
                $atividadeId = $this->atividadeDaMensagem($conversa, $mensagem);
                if ($atividadeId !== null) {
                    $mensagens->atualizar((int) $mensagem['id'], ['atividade_id' => $atividadeId]);
                }
            }
            return Resultado::sucesso($conversaId, 'Conversa vinculada a ' . $contato['nome_completo'] . '.');
        });
    }

    /** Cria um contato a partir da conversa (o número ou o e-mail do canal viram os dados do contato) e a vincula. */
    public function criarContatoDaConversa(int $conversaId, string $nome, ?int $empresaId = null): Resultado
    {
        $conversa = (new ConversaRepository())->encontrar($conversaId);
        if ($conversa === null) {
            return Resultado::erroGeral('Conversa não encontrada.');
        }
        $dados = ['nome' => $nome !== '' ? $nome : (string) ($conversa['nome'] ?: $conversa['identificador'])];
        if ($empresaId !== null && $empresaId > 0) {
            $dados['empresa_id'] = $empresaId;
        }
        if ($conversa['canal'] === 'whatsapp') {
            $dados['whatsapp'] = '+' . $conversa['identificador'];
        } elseif ($conversa['canal'] === 'email') {
            $dados['email'] = $conversa['identificador'];
        }
        return $this->transacao(function () use ($conversaId, $dados): Resultado {
            $r = $this->criar('contatos', $dados, 'humano');
            if (!$r->ok) {
                return $r;
            }
            $v = $this->vincularConversa($conversaId, (int) $r->id);
            return $v->ok ? Resultado::sucesso((int) $r->id, 'Contato criado e conversa vinculada.') : $v;
        });
    }

    /**
     * Grava o resultado da transcrição de um áudio (Fase 15). A IA só devolve dados; aqui intenção e sentimento passam pela lista
     * fixa e o texto vai para a mensagem, para a prévia da conversa e para a descrição da atividade da timeline.
     * Falha: conta uma tentativa (a não ser que `$contarTentativa` seja falso, como na falta de crédito) e desiste se for definitiva
     * ou ao esgotar as tentativas; enquanto não desiste, o áudio continua pendente.
     * @param array $r saída de Transcritor::transcrever
     */
    public function registrarTranscricao(int $mensagemId, array $r, bool $contarTentativa = true): Resultado
    {
        $mensagens = new MensagemRepository();
        $m = $mensagens->encontrar($mensagemId);
        if ($m === null || $m['transcricao_status'] !== 'pendente') {
            return Resultado::erroGeral('Não há transcrição pendente para esta mensagem.');
        }

        if (empty($r['ok'])) {
            $tentativas = (int) $m['transcricao_tentativas'] + ($contarTentativa ? 1 : 0);
            $desiste = !empty($r['definitivo']) || $tentativas >= Transcritor::MAX_TENTATIVAS;
            $mensagens->atualizar($mensagemId, [
                'transcricao_tentativas' => $tentativas, 'transcricao_erro' => mb_substr((string) ($r['erro'] ?? 'Falha na transcrição.'), 0, 300),
            ] + ($desiste ? ['transcricao_status' => 'falhou'] : []));
            return Resultado::sucesso($mensagemId, $desiste ? 'Transcrição desistida.' : 'Nova tentativa mais tarde.');
        }

        $texto = mb_substr(trim((string) ($r['transcricao'] ?? '')), 0, 20000);
        $intencao = isset(Transcritor::INTENCOES[(string) ($r['intencao'] ?? '')]) ? (string) $r['intencao'] : null;
        $sentimento = isset(Transcritor::SENTIMENTOS[(string) ($r['sentimento'] ?? '')]) ? (string) $r['sentimento'] : null;

        return $this->transacao(function () use ($mensagens, $mensagemId, $m, $texto, $intencao, $sentimento): Resultado {
            $mensagens->atualizar($mensagemId, [
                'transcricao_status' => 'concluida', 'transcricao' => $texto, 'intencao' => $intencao, 'sentimento' => $sentimento, 'transcricao_erro' => null,
            ]);
            $conversas = new ConversaRepository();
            $conversa = $conversas->encontrar((int) $m['conversa_id']);
            if ($conversa !== null && (string) $conversa['ultima_mensagem_em'] === (string) $m['data_hora']) {
                $conversas->atualizar((int) $conversa['id'], ['ultima_previa' => mb_substr(preg_replace('/\s+/u', ' ', Canais::descricao('audio', $texto)) ?? '', 0, 140)]);
            }
            if ($m['atividade_id'] !== null && $texto !== '') {
                $r = $this->atualizar('atividades', (int) $m['atividade_id'], ['descricao' => mb_substr(Canais::descricao('audio', $texto), 0, 4900)], 'sistema');
                if (!$r->ok) {
                    error_log("Transcrição da mensagem #{$mensagemId} não atualizou a timeline: {$r->mensagem}");
                }
            }
            return Resultado::sucesso($mensagemId, 'Transcrição registrada.');
        });
    }

    /** Grava o resumo do atendimento e, se a conversa já tem contato ou empresa, uma nota na timeline (origem "ia"). */
    public function registrarResumoConversa(int $conversaId, string $texto): Resultado
    {
        $conversas = new ConversaRepository();
        $conversa = $conversas->encontrar($conversaId);
        $texto = mb_substr(trim($texto), 0, 3000);
        if ($conversa === null || $texto === '') {
            return Resultado::erroGeral('Conversa não encontrada ou resumo vazio.');
        }
        return $this->transacao(function () use ($conversas, $conversaId, $conversa, $texto): Resultado {
            $agora = agora();
            $conversas->atualizar($conversaId, ['resumo' => $texto, 'resumo_em' => $agora, 'resumo_pendente' => 0, 'resumo_tentativas' => 0]);
            if ($conversa['contato_id'] !== null || $conversa['empresa_id'] !== null) {
                $r = $this->criar('atividades', [
                    'tipo' => 'nota', 'assunto' => 'Resumo do atendimento (' . Canais::ROTULOS[(string) $conversa['canal']] . ')', 'descricao' => mb_substr($texto, 0, 4900),
                    'empresa_id' => $conversa['empresa_id'] !== null ? (int) $conversa['empresa_id'] : null,
                    'contato_id' => $conversa['contato_id'] !== null ? (int) $conversa['contato_id'] : null, 'data_hora' => $agora,
                ], 'ia');
                if (!$r->ok) {
                    error_log("Resumo da conversa #{$conversaId} não virou nota na timeline: {$r->mensagem}");
                }
            }
            return Resultado::sucesso($conversaId, 'Resumo registrado.');
        });
    }

    /** O resumo falhou: conta a tentativa e, na terceira, tira a conversa da fila. */
    public function adiarResumoConversa(int $conversaId): void
    {
        $conversas = new ConversaRepository();
        $conversa = $conversas->encontrar($conversaId);
        if ($conversa !== null) {
            $tentativas = (int) $conversa['resumo_tentativas'] + 1;
            $conversas->atualizar($conversaId, ['resumo_tentativas' => $tentativas, 'resumo_pendente' => $tentativas >= 3 ? 0 : 1]);
        }
    }

    // ---- Apoio ----------------------------------------------------------------------------

    /** Cria a atividade da timeline para a mensagem (só se a conversa já tem contato ou empresa). Devolve o id ou null. */
    private function atividadeDaMensagem(array $conversa, array $mensagem): ?int
    {
        if ($conversa['contato_id'] === null && $conversa['empresa_id'] === null) {
            return null;
        }
        $canal = (string) $conversa['canal'];
        $entrada = $mensagem['direcao'] === 'entrada';
        $assunto = $canal === 'email' && trim((string) $conversa['assunto']) !== ''
            ? mb_substr((string) $conversa['assunto'], 0, 190)
            : Canais::ROTULOS[$canal] . ($entrada ? ' recebido' : ' enviado');
        $r = $this->criar('atividades', [
            'tipo' => Canais::TIPO_ATIVIDADE[$canal], 'direcao' => $mensagem['direcao'], 'assunto' => $assunto,
            'descricao' => mb_substr(Canais::descricao((string) $mensagem['tipo'], $mensagem['texto'] ?: ($mensagem['transcricao'] ?? null)), 0, 4900),
            'empresa_id' => $conversa['empresa_id'] !== null ? (int) $conversa['empresa_id'] : null,
            'contato_id' => $conversa['contato_id'] !== null ? (int) $conversa['contato_id'] : null,
            'data_hora' => (string) $mensagem['data_hora'],
        ], 'sistema');
        return $r->ok ? (int) $r->id : null;
    }

    /** Message-ID (sem o prefixo) da última mensagem de e-mail recebida, para o cabeçalho In-Reply-To. */
    private function ultimoIdEmailRecebido(int $conversaId): ?string
    {
        foreach (array_reverse((new MensagemRepository())->daConversa($conversaId, 50)) as $m) {
            if ($m['direcao'] === 'entrada' && is_string($m['id_externo']) && str_starts_with($m['id_externo'], 'em:')) {
                return substr($m['id_externo'], 3);
            }
        }
        return null;
    }
}
