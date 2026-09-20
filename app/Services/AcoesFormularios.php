<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\FormularioRepository;
use App\Repositories\Repositorios;
use App\Repositories\SubmissaoRepository;

/**
 * Formulários de captação (SPEC §4.10) pelo ActionExecutor: salvar/arquivar a definição (configuração, sem
 * log_auditoria, como agentes e squads) e receber um envio público, que cria/mescla empresa, contato e negócio com
 * origem `formulario:<id>` (essas escritas são auditadas normalmente) e dispara `formulario.submetido`.
 */
trait AcoesFormularios
{
    /** Vínculo (formulario_id, submissao_id) gravado em empresas e negócios criados durante um envio. */
    private ?array $vinculoFormulario = null;

    private const UTMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * Cria (sem $id) ou edita um formulário e troca seus campos. A chave pública é gerada na criação e nunca muda.
     * @param list<array> $campos
     */
    public function salvarFormulario(?int $id, array $dados, array $campos): Resultado
    {
        $v = FormularioDefinicao::validar($dados, $campos);
        if ($v['erros'] !== []) {
            return Resultado::falha($v['erros']);
        }
        $repo = new FormularioRepository();

        return $this->transacao(function () use ($repo, $id, $v): Resultado {
            $agora = agora();
            if ($id === null) {
                do {
                    $chave = bin2hex(random_bytes(16));
                } while ($repo->chaveExiste($chave));
                $formularioId = $repo->inserir($v['dados'] + ['chave' => $chave, 'criado_em' => $agora, 'atualizado_em' => $agora]);
            } else {
                if ($repo->encontrar($id) === null) {
                    return Resultado::erroGeral('Formulário não encontrado.');
                }
                $formularioId = $id;
                $repo->atualizar($id, $v['dados'] + ['atualizado_em' => $agora]);
            }
            $repo->substituirCampos($formularioId, $v['campos']);
            return Resultado::sucesso($formularioId, "Formulário \"{$v['dados']['nome']}\" salvo.");
        });
    }

    public function arquivarFormulario(int $id): Resultado
    {
        $repo = new FormularioRepository();
        $f = $repo->encontrar($id);
        if ($f === null) {
            return Resultado::erroGeral('Formulário não encontrado.');
        }
        $agora = agora();
        $repo->atualizar($id, ['arquivado_em' => $agora, 'atualizado_em' => $agora]);
        return Resultado::sucesso($id, "Formulário \"{$f['nome']}\" arquivado. O link público deixou de funcionar.");
    }

    /**
     * Processa um envio do formulário público.
     * $entrada: valores por `c<id do campo>`. $meta: ip, user_agent, pagina_origem, referer, honeypot (bool), utm (array).
     * Falha com `_limite` (limite de envios por IP/hora) ou com erros por campo (`c<id>`); nada é gravado nesses casos.
     * Sucesso: id da submissão e, em `registro`, status (processada|duplicada|spam) e ids criados.
     */
    public function receberFormulario(array $formulario, array $entrada, array $meta = []): Resultado
    {
        $formId = (int) $formulario['id'];
        $origem = "formulario:{$formId}";
        $submissoes = new SubmissaoRepository();
        $ip = mb_substr((string) ($meta['ip'] ?? ''), 0, 45);

        if ($ip !== '' && $submissoes->contarDoIpDesde($ip, date('Y-m-d H:i:s', time() - 3600)) >= FormularioDefinicao::LIMITE_ENVIOS_POR_HORA) {
            return Resultado::falha(['_limite' => 'Recebemos muitos envios deste endereço. Tente novamente mais tarde.']);
        }

        $campos = (new FormularioRepository())->campos($formId);
        $destinos = FormularioDefinicao::destinos();
        [$lidos, $erros] = $this->lerEnvioFormulario($campos, $destinos, $entrada);

        $utm = [];
        foreach (self::UTMS as $k) {
            $x = trim((string) ($meta['utm'][$k] ?? ''));
            $utm[$k] = $x !== '' ? mb_substr($x, 0, 190) : null;
        }
        $base = [
            'formulario_id' => $formId, 'ip' => $ip !== '' ? $ip : null,
            'user_agent' => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 255) ?: null,
            'pagina_origem' => mb_substr((string) ($meta['pagina_origem'] ?? ''), 0, 500) ?: null,
            'referer' => mb_substr((string) ($meta['referer'] ?? ''), 0, 500) ?: null,
            'criado_em' => agora(),
        ] + $utm;

        // Robô (campo-isca preenchido): guarda como spam e responde como se tivesse dado certo.
        if (!empty($meta['honeypot'])) {
            $sid = $submissoes->inserir($base + ['dados' => $this->snapshotEnvio($lidos), 'status' => 'spam']);
            return Resultado::sucesso($sid, (string) $formulario['mensagem_sucesso'], ['status' => 'spam']);
        }
        if ($erros !== []) {
            return Resultado::falha($erros);
        }

        return $this->transacao(function () use ($formulario, $lidos, $base, $utm, $origem, $submissoes, $destinos): Resultado {
            $formId = (int) $formulario['id'];
            $sid = $submissoes->inserir($base + ['dados' => $this->snapshotEnvio($lidos), 'status' => 'processada']);
            $this->vinculoFormulario = ['formulario_id' => $formId, 'submissao_id' => $sid];
            try {
                $r = $this->processarEnvio($formulario, $lidos, $utm, $origem, $sid, $destinos);
            } finally {
                $this->vinculoFormulario = null;
            }
            return $r;
        });
    }

    /**
     * Lê e valida os valores do envio. Devolve a lista de campos lidos (com valor normalizado, null = vazio) e os erros por `c<id>`.
     * @return array{0:list<array>,1:array<string,string>}
     */
    private function lerEnvioFormulario(array $campos, array $destinos, array $entrada): array
    {
        $lidos = [];
        $erros = [];
        foreach ($campos as $c) {
            $def = $destinos[$c['campo_destino']] ?? null;
            if ($def === null) {
                continue; // destino removido (ex.: campo extra arquivado): o campo não aparece na página
            }
            $chave = 'c' . $c['id'];
            $bruto = $entrada[$chave] ?? null;
            $obrigatorio = (int) $c['obrigatorio'] === 1;
            $valor = null;
            $rotuloValor = null;

            if ($c['tipo'] === 'checkbox') {
                $marcado = in_array($bruto, ['1', 1, true, 'on'], true);
                $valor = $marcado ? 1 : null;
                $rotuloValor = $marcado ? 'Sim' : null;
                if (!$marcado && $obrigatorio) {
                    $erros[$chave] = 'Marque esta opção para continuar.';
                }
            } else {
                $texto = is_string($bruto) ? trim($bruto) : '';
                if (!mb_check_encoding($texto, 'UTF-8') || mb_strlen($texto) > ($c['tipo'] === 'textarea' ? 5000 : 500)) {
                    $erros[$chave] = 'Valor inválido ou longo demais.';
                } elseif ($texto === '') {
                    if ($obrigatorio) {
                        $erros[$chave] = 'Preencha este campo.';
                    }
                } elseif ($c['tipo'] === 'select') {
                    $opcoes = FormularioDefinicao::opcoesDoCampo($c, $def);
                    if (!array_key_exists($texto, $opcoes)) {
                        $erros[$chave] = 'Escolha uma das opções.';
                    } else {
                        $valor = $texto;
                        $rotuloValor = (string) $opcoes[$texto];
                    }
                } elseif ($c['tipo'] === 'email' && filter_var($texto, FILTER_VALIDATE_EMAIL) === false) {
                    $erros[$chave] = 'Informe um e-mail válido.';
                } else {
                    $valor = $c['tipo'] === 'email' ? mb_strtolower($texto) : $texto;
                }
            }
            $lidos[] = ['campo' => $c, 'def' => $def, 'chave' => $chave, 'valor' => $valor, 'texto' => $rotuloValor ?? ($valor !== null ? (string) $valor : null)];
        }
        return [$lidos, $erros];
    }

    /** JSON gravado na submissão: o que a pessoa respondeu, com os rótulos da época. */
    private function snapshotEnvio(array $lidos): string
    {
        $lista = [];
        foreach ($lidos as $l) {
            if ($l['texto'] !== null) {
                $lista[] = ['rotulo' => $l['campo']['rotulo'], 'destino' => $l['campo']['campo_destino'], 'valor' => $l['texto']];
            }
        }
        return json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Aplica as regras de duplicado e grava. Roda dentro da transação de receberFormulario. */
    private function processarEnvio(array $formulario, array $lidos, array $utm, string $origem, int $sid, array $destinos): Resultado
    {
        $submissoes = new SubmissaoRepository();
        $reg = ['empresa' => [], 'contato' => [], 'negocio' => []];
        $extras = ['empresas' => [], 'contatos' => [], 'negocios' => []];
        $mapa = [];   // destino => chave do campo (para devolver erros ao campo certo)
        $resumo = [];
        $prefixos = array_flip(FormularioDefinicao::ENTIDADES);
        foreach ($lidos as $l) {
            $mapa[$l['campo']['campo_destino']] = $l['chave'];
            if ($l['valor'] === null) {
                continue;
            }
            $resumo[] = "{$l['campo']['rotulo']}: {$l['texto']}";
            if ($l['def']['chave_extra'] !== null) {
                $extras[$l['def']['entidade']][$l['def']['chave_extra']] = $l['valor'];
            } else {
                $reg[$prefixos[$l['def']['entidade']]][$l['def']['campo']] = $l['valor'];
            }
        }
        foreach ($extras as $entidade => $valores) {
            if ($valores !== []) {
                $reg[$prefixos[$entidade]]['campos_extras'] = $valores;
            }
        }

        $regra = (string) $formulario['regra_duplicado'];
        [$contato, $empresa, $por] = $this->localizarDuplicadoFormulario($reg);
        $duplicado = $por !== null;
        $agir = $duplicado ? $regra : 'criar';
        $origemId = $formulario['origem_id_padrao'] !== null ? (int) $formulario['origem_id_padrao'] : null;
        $empresaId = $empresa['id'] ?? null;
        $contatoId = $contato['id'] ?? null;
        $negocioId = null;
        $mensagemFalha = null;

        // Grava e devolve o id, ou registra a falha (erros mapeados de volta para os campos do formulário).
        $gravar = function (string $entidade, string $prefixo, array $dados) use ($origem, $mapa, &$mensagemFalha): ?int {
            $r = $this->criar($entidade, $dados, $origem);
            if (!$r->ok) {
                $mensagemFalha = $this->errosParaCampos($r, $prefixo, $mapa);
                return null;
            }
            return (int) $r->id;
        };
        $preencher = function (string $entidade, string $prefixo, array $atual, array $novos) use ($origem, $mapa, &$mensagemFalha): bool {
            $vazios = $this->somenteVazios($atual, $novos);
            if ($vazios === []) {
                return true;
            }
            $r = $this->atualizar($entidade, (int) $atual['id'], $vazios, $origem);
            if (!$r->ok) {
                $mensagemFalha = $this->errosParaCampos($r, $prefixo, $mapa);
            }
            return $r->ok;
        };

        if ($agir === 'tarefa') {
            $quem = $contato['nome_completo'] ?? $empresa['nome_fantasia'] ?? 'contato';
            $r = $this->criar('tarefas', [
                'titulo' => 'Revisar envio duplicado: ' . mb_substr((string) $quem, 0, 120),
                'descricao' => "O formulário \"{$formulario['nome']}\" recebeu dados de alguém que já está no CRM (igual por {$por}).\n\n" . implode("\n", $resumo),
                'tipo' => 'followup', 'prioridade' => 'media', 'vencimento' => hoje(),
                'empresa_id' => $empresaId, 'contato_id' => $contatoId,
            ], $origem);
            if (!$r->ok) {
                return Resultado::falha(['_' => $r->mensagem]);
            }
        } else {
            $utmEntrada = array_filter($utm, static fn ($v) => $v !== null);

            // Empresa
            if ($agir === 'mesclar' && $empresa !== null) {
                $empresa = Repositorios::empresas()->encontrar((int) $empresa['id']);
                if (!$preencher('empresas', 'empresa', $empresa, $reg['empresa'] + $utmEntrada)) {
                    return Resultado::falha($mensagemFalha);
                }
            } else {
                $dados = $reg['empresa'] + ['status' => (string) $formulario['status_padrao']] + $utmEntrada;
                if ($origemId !== null) {
                    $dados['origem_id'] = $origemId;
                }
                if (!isset($dados['nome_fantasia'])) {
                    $dados['nome_fantasia'] = $this->nomeDoContatoEnvio($reg['contato']) ?? 'Contato do formulário';
                }
                if (isset($dados['cnpj']) && Repositorios::empresas()->porCnpj(so_digitos((string) $dados['cnpj'])) !== null) {
                    unset($dados['cnpj']); // regra "criar" com CNPJ já cadastrado: cria sem o CNPJ, que é único
                }
                $empresaId = $gravar('empresas', 'empresa', $dados);
                if ($empresaId === null) {
                    return Resultado::falha($mensagemFalha);
                }
                $empresa = Repositorios::empresas()->encontrar($empresaId);
            }
            $empresaId = (int) $empresa['id'];

            // Contato
            if ($agir === 'mesclar' && $contato !== null) {
                $contato = Repositorios::contatos()->encontrar((int) $contato['id']);
                $novos = $reg['contato'] + ($contato['empresa_id'] === null ? ['empresa_id' => $empresaId] : []);
                if (!$preencher('contatos', 'contato', $contato, $novos)) {
                    return Resultado::falha($mensagemFalha);
                }
                $contatoId = (int) $contato['id'];
            } elseif ($reg['contato'] !== []) {
                $dados = $reg['contato'] + ['empresa_id' => $empresaId];
                if ($origemId !== null) {
                    $dados['origem_id'] = $origemId;
                }
                if (!isset($dados['nome'])) {
                    $dados['nome'] = isset($dados['email']) ? explode('@', (string) $dados['email'])[0] : (string) $empresa['nome_fantasia'];
                }
                $contatoId = $gravar('contatos', 'contato', $dados);
                if ($contatoId === null) {
                    return Resultado::falha($mensagemFalha);
                }
            }

            // Negócio
            if ((int) $formulario['criar_negocio'] === 1) {
                $dados = $reg['negocio'] + $utmEntrada + ['empresa_id' => $empresaId];
                $dados['titulo'] ??= mb_substr("{$formulario['nome']} — {$empresa['nome_fantasia']}", 0, 200);
                if ($contatoId !== null) {
                    $dados['contato_principal_id'] = $contatoId;
                }
                if ($origemId !== null) {
                    $dados['origem_id'] = $origemId;
                }
                if ($formulario['etapa_id_padrao'] !== null) {
                    $dados['etapa_id'] = (int) $formulario['etapa_id_padrao'];
                }
                $negocioId = $gravar('negocios', 'negocio', $dados);
                if ($negocioId === null) {
                    return Resultado::falha($mensagemFalha);
                }
            }

            // Registro na timeline
            $r = $this->criar('atividades', [
                'tipo' => 'nota', 'assunto' => 'Formulário: ' . $formulario['nome'],
                'descricao' => ($duplicado ? "Envio de alguém já cadastrado (igual por {$por}); dados vazios foram completados.\n\n" : '') . implode("\n", $resumo),
                'empresa_id' => $empresaId, 'contato_id' => $contatoId, 'negocio_id' => $negocioId,
            ], $origem);
            if (!$r->ok) {
                return Resultado::falha(['_' => $r->mensagem]);
            }
        }

        $status = $duplicado && $regra !== 'criar' ? 'duplicada' : 'processada';
        $submissoes->atualizar($sid, ['status' => $status, 'empresa_id' => $empresaId, 'contato_id' => $contatoId, 'negocio_id' => $negocioId]);

        $registro = $empresaId !== null ? (Repositorios::empresas()->encontrar((int) $empresaId) ?? []) : [];
        $this->enfileirar('formulario.submetido', [
            'entidade' => $empresaId !== null ? 'empresas' : 'contatos', 'id' => (int) ($empresaId ?? $contatoId), 'origem' => $origem,
            'registro' => $registro + ['contato_id' => $contatoId, 'negocio_id' => $negocioId],
            'formulario_id' => (int) $formulario['id'], 'submissao_id' => $sid, 'status' => $status, 'squad' => $formulario['squad_disparado'],
        ]);

        return Resultado::sucesso($sid, (string) $formulario['mensagem_sucesso'], [
            'status' => $status, 'empresa_id' => $empresaId, 'contato_id' => $contatoId, 'negocio_id' => $negocioId,
        ]);
    }

    /**
     * Procura quem já existe, comparando na ordem: e-mail → WhatsApp/telefone → CNPJ → domínio do site.
     * @return array{0:?array,1:?array,2:?string} contato, empresa e o critério que casou (null = não há duplicado)
     */
    private function localizarDuplicadoFormulario(array $reg): array
    {
        $contatos = Repositorios::contatos();
        $empresas = Repositorios::empresas();
        $c = $reg['contato'];
        $e = $reg['empresa'];
        $contato = null;
        $empresa = null;
        $por = null;

        foreach (array_filter([$c['email'] ?? null, $e['email_geral'] ?? null]) as $email) {
            $contato ??= $contatos->porEmail((string) $email);
            $empresa ??= $empresas->porEmailGeral((string) $email);
        }
        if ($contato !== null || $empresa !== null) {
            $por = 'e-mail';
        }

        if ($por === null) {
            foreach (array_filter([$c['whatsapp'] ?? null, $c['telefone'] ?? null, $e['whatsapp'] ?? null, $e['telefone'] ?? null]) as $tel) {
                $d = so_digitos((string) $tel);
                $contato ??= $contatos->porTelefone($d);
                $empresa ??= $empresas->porTelefone($d);
            }
            if ($contato !== null || $empresa !== null) {
                $por = 'WhatsApp/telefone';
            }
        }
        if ($por === null && isset($e['cnpj'])) {
            $empresa = $empresas->porCnpj(so_digitos((string) $e['cnpj']));
            $por = $empresa !== null ? 'CNPJ' : null;
        }
        if ($por === null) {
            $dominio = dominio_de((string) ($e['site'] ?? $e['dominio'] ?? ''));
            $empresa = $dominio !== '' ? $empresas->porDominio($dominio) : null;
            $por = $empresa !== null ? 'domínio do site' : null;
        }

        if ($contato !== null && $empresa === null && $contato['empresa_id'] !== null) {
            $empresa = $empresas->encontrar((int) $contato['empresa_id']);
        }
        return [$contato, $empresa, $por];
    }

    /** Dos valores novos, só os que preenchem campo vazio do registro (campos extras: só as chaves ainda ausentes). */
    private function somenteVazios(array $atual, array $novos): array
    {
        $saida = [];
        foreach ($novos as $campo => $valor) {
            if ($campo === 'campos_extras') {
                $ja = CamposExtras::valores($atual['campos_extras'] ?? null);
                $faltam = array_diff_key((array) $valor, $ja);
                if ($faltam !== []) {
                    $saida['campos_extras'] = $faltam;
                }
                continue;
            }
            $vazio = !array_key_exists($campo, $atual) || $atual[$campo] === null || $atual[$campo] === '' || $atual[$campo] === 0 || $atual[$campo] === '0';
            if ($vazio && $valor !== null && $valor !== '') {
                $saida[$campo] = $valor;
            }
        }
        return $saida;
    }

    /** Traduz os erros de uma entidade para os campos do formulário público (`c<id>`); o que não casar vira erro geral. */
    private function errosParaCampos(Resultado $r, string $prefixo, array $mapa): array
    {
        $saida = [];
        foreach ($r->erros as $campo => $mensagem) {
            $campo = (string) $campo;
            $destino = str_starts_with($campo, 'campos_extras.') ? 'extra.' . substr($campo, 14) : "{$prefixo}.{$campo}";
            if (isset($mapa[$destino])) {
                $saida[$mapa[$destino]] = $mensagem;
            } else {
                $saida['_'] = trim(($saida['_'] ?? '') . ' ' . $mensagem);
            }
        }
        return $saida;
    }

    private function nomeDoContatoEnvio(array $contato): ?string
    {
        $nome = trim(($contato['nome'] ?? '') . ' ' . ($contato['sobrenome'] ?? ''));
        return $nome !== '' ? $nome : null;
    }
}
