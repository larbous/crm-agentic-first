<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\Repositorios;
use App\Repositories\SquadRepository;

/**
 * Definição de um formulário de captação (SPEC §4.10): destinos de campo permitidos, tipos de controle e validação
 * da configuração e dos campos. Só entra no formulário público o que estiver aqui: o resto do Schema não é exposto.
 */
final class FormularioDefinicao
{
    public const TIPOS = ['texto', 'email', 'telefone', 'textarea', 'select', 'checkbox', 'numero', 'data'];
    public const MAX_CAMPOS = 40;
    public const LIMITE_ENVIOS_POR_HORA = 5;
    public const ENTIDADES = ['empresa' => 'empresas', 'contato' => 'contatos', 'negocio' => 'negocios'];
    /** Tipos de formulário: captação (cria empresa/contato/negócio) e pesquisa de satisfação/NPS (responde a um envio individual). */
    public const FORMULARIO_TIPOS = ['captacao' => 'Captação', 'pesquisa' => 'Pesquisa NPS'];
    public const GATILHOS = ['manual' => 'Só manual', 'contrato_assinado' => 'Dias depois de um contrato assinado', 'periodica' => 'Periodicamente, para clientes ativos'];
    public const MAX_PERGUNTAS_EXTRAS = 8;

    /** Tipos do Schema que um formulário público consegue preencher. */
    private const TIPOS_SCHEMA = ['texto', 'textarea', 'email', 'tel', 'url', 'int', 'money', 'data', 'enum', 'bool', 'cnpj', 'cpf', 'cep', 'uf'];

    /** Campos que o servidor decide (status, origem, aquisição): nunca vêm do visitante. */
    private const BLOQUEADOS = ['empresa.status', 'empresa.cliente_desde', 'contato.status', 'contato.ultimo_contato_em'];

    /** Negócio: só o que faz sentido um visitante informar (etapa, status e valores finais são do servidor). */
    private const NEGOCIO_PERMITIDOS = [
        'titulo', 'valor_estimado', 'tipo_receita', 'previsao_fechamento', 'temperatura', 'dor_principal', 'objetivo_cliente',
        'orcamento_cliente', 'prazo_desejado', 'criterio_decisao', 'concorrentes', 'notas',
    ];

    /**
     * Destinos disponíveis: destino => [destino, entidade (plural), campo, chave_extra, rotulo, grupo, tipos, padrao, opcoes].
     * `opcoes` (valor => rótulo) preenchido quando as opções vêm do sistema (lista do Schema ou do campo extra).
     * @return array<string,array<string,mixed>>
     */
    public static function destinos(): array
    {
        $saida = [];
        foreach (self::ENTIDADES as $prefixo => $entidade) {
            foreach (Schema::gravaveis($entidade) as $campo => $def) {
                $destino = "{$prefixo}.{$campo}";
                if (!in_array($def['t'], self::TIPOS_SCHEMA, true) || in_array($destino, self::BLOQUEADOS, true) || str_starts_with($campo, 'utm_')) {
                    continue;
                }
                if ($prefixo === 'negocio' && !in_array($campo, self::NEGOCIO_PERMITIDOS, true)) {
                    continue;
                }
                $tipos = self::tiposDoSchema($def['t']);
                $saida[$destino] = [
                    'destino' => $destino, 'entidade' => $entidade, 'campo' => $campo, 'chave_extra' => null,
                    'rotulo' => $def['r'], 'grupo' => ucfirst($prefixo), 'tipos' => $tipos, 'padrao' => $tipos[0],
                    'opcoes' => $def['t'] === 'enum' ? Schema::opcoes($def['op']) : null,
                ];
            }
        }
        // Campos extras: a chave vale para a primeira entidade (empresa, contato, negócio) que a definir.
        foreach (CamposExtras::ENTIDADES as $entidade) {
            foreach (CamposExtras::definicoes($entidade) as $def) {
                $destino = 'extra.' . $def['chave'];
                if (isset($saida[$destino])) {
                    continue;
                }
                $tipos = self::tiposDoExtra($def['tipo']);
                $saida[$destino] = [
                    'destino' => $destino, 'entidade' => $entidade, 'campo' => 'campos_extras', 'chave_extra' => $def['chave'],
                    'rotulo' => $def['rotulo'], 'grupo' => 'Extra · ' . Schema::entidade($entidade)['singular'], 'tipos' => $tipos, 'padrao' => $tipos[0],
                    'opcoes' => $def['tipo'] === 'select' ? array_combine($def['opcoes'], $def['opcoes']) : null,
                ];
            }
        }
        return $saida;
    }

    /**
     * Destinos de um formulário de pesquisa: a nota (escala 0–10), o comentário e até 8 perguntas extras (guardadas em `respostas`).
     * @return array<string,array<string,mixed>>
     */
    public static function destinosPesquisa(): array
    {
        $d = static fn (string $destino, string $rotulo, string $grupo, array $tipos): array => [
            'destino' => $destino, 'entidade' => null, 'campo' => $destino, 'chave_extra' => null, 'rotulo' => $rotulo, 'grupo' => $grupo,
            'tipos' => $tipos, 'padrao' => $tipos[0], 'opcoes' => null,
        ];
        $saida = [
            'pesquisa.nota' => $d('pesquisa.nota', 'Nota de 0 a 10 (NPS)', 'Pesquisa', ['numero']),
            'pesquisa.comentario' => $d('pesquisa.comentario', 'Comentário (por quê?)', 'Pesquisa', ['textarea']),
        ];
        for ($i = 1; $i <= self::MAX_PERGUNTAS_EXTRAS; $i++) {
            $saida["pergunta.{$i}"] = $d("pergunta.{$i}", "Pergunta extra {$i}", 'Perguntas extras', ['texto', 'textarea', 'select', 'checkbox', 'numero']);
        }
        return $saida;
    }

    /** Destinos do tipo de formulário. */
    public static function destinosDe(string $tipo): array
    {
        return $tipo === 'pesquisa' ? self::destinosPesquisa() : self::destinos();
    }

    /** @return list<string> tipos de controle aceitos para um tipo do Schema (o primeiro é o padrão) */
    private static function tiposDoSchema(string $t): array
    {
        return match ($t) {
            'email' => ['email'],
            'tel' => ['telefone'],
            'int', 'money' => ['numero'],
            'data' => ['data'],
            'bool' => ['checkbox'],
            'enum' => ['select'],
            'textarea' => ['textarea', 'texto'],
            default => ['texto', 'select'],
        };
    }

    /** @return list<string> */
    private static function tiposDoExtra(string $tipo): array
    {
        return match ($tipo) {
            'numero' => ['numero'],
            'data' => ['data'],
            'select' => ['select'],
            'checkbox' => ['checkbox'],
            'textarea' => ['textarea', 'texto'],
            default => ['texto'],
        };
    }

    /**
     * Opções (valor => rótulo) de um campo select: do sistema quando o destino as define, senão as escritas no formulário.
     * @param array<string,mixed>|null $destino entrada de destinos()
     */
    public static function opcoesDoCampo(array $campo, ?array $destino): array
    {
        if ($destino !== null && $destino['opcoes'] !== null) {
            return $destino['opcoes'];
        }
        $lista = array_values((array) ($campo['opcoes'] ?? []));
        return $lista === [] ? [] : array_combine($lista, $lista);
    }

    /**
     * Valida a configuração e os campos de um formulário. Devolve os dados normalizados prontos para gravar.
     * @param list<array> $campos campos como enviados pelo construtor
     * @return array{erros:array<string,string>,dados:array,campos:list<array>}
     */
    public static function validar(array $d, array $campos): array
    {
        $erros = [];
        $texto = static fn (string $k, int $max): string => mb_substr(trim((string) ($d[$k] ?? '')), 0, $max + 1);

        $dados = ['nome' => $texto('nome', 160), 'titulo' => $texto('titulo', 200), 'texto_botao' => $texto('texto_botao', 40),
            'mensagem_sucesso' => $texto('mensagem_sucesso', 500), 'redirect_url' => $texto('redirect_url', 500)];
        foreach (['nome' => 160, 'titulo' => 200, 'texto_botao' => 40, 'mensagem_sucesso' => 500, 'redirect_url' => 500] as $k => $max) {
            if (mb_strlen($dados[$k]) > $max) {
                $erros[$k] = "Use no máximo {$max} caracteres.";
            }
        }
        if ($dados['nome'] === '') {
            $erros['nome'] = 'Informe o nome do formulário.';
        }
        $dados['texto_botao'] = $dados['texto_botao'] !== '' ? $dados['texto_botao'] : 'Enviar';
        if ($dados['mensagem_sucesso'] === '') {
            $dados['mensagem_sucesso'] = 'Recebemos suas informações. Em breve entraremos em contato.';
        }
        $dados['titulo'] = $dados['titulo'] !== '' ? $dados['titulo'] : null;
        if ($dados['redirect_url'] === '') {
            $dados['redirect_url'] = null;
        } elseif (!isset($erros['redirect_url']) && !self::urlHttp($dados['redirect_url'])) {
            $erros['redirect_url'] = 'Informe um endereço completo, começando com http:// ou https://.';
        }

        if (($d['tipo'] ?? 'captacao') === 'pesquisa') {
            return self::validarPesquisa($d, $dados, $erros, $campos);
        }
        $dados['tipo'] = 'captacao';

        $origem = (string) ($d['origem_id_padrao'] ?? '');
        $dados['origem_id_padrao'] = $origem === '' ? null : (int) $origem;
        if ($dados['origem_id_padrao'] !== null && !Repositorios::para('origens')->existe($dados['origem_id_padrao'])) {
            $erros['origem_id_padrao'] = 'Origem inexistente.';
        }
        $dados['status_padrao'] = (string) ($d['status_padrao'] ?? 'lead');
        if (!in_array($dados['status_padrao'], ['lead', 'prospect'], true)) {
            $erros['status_padrao'] = 'Escolha Lead ou Prospect.';
        }
        $dados['criar_negocio'] = in_array($d['criar_negocio'] ?? 0, [1, '1', true, 'on'], true) ? 1 : 0;
        $etapa = (string) ($d['etapa_id_padrao'] ?? '');
        $dados['etapa_id_padrao'] = $dados['criar_negocio'] === 1 && $etapa !== '' ? (int) $etapa : null;
        if ($dados['etapa_id_padrao'] !== null) {
            $e = Repositorios::etapas()->encontrar($dados['etapa_id_padrao']);
            if ($e === null || $e['tipo'] !== 'aberta') {
                $erros['etapa_id_padrao'] = 'Escolha uma etapa aberta.';
            }
        }
        $squad = trim((string) ($d['squad_disparado'] ?? ''));
        $dados['squad_disparado'] = $squad === '' ? null : $squad;
        if ($squad !== '' && (new SquadRepository())->porSlug($squad) === null) {
            $erros['squad_disparado'] = 'Squad inexistente.';
        }
        $dados['regra_duplicado'] = (string) ($d['regra_duplicado'] ?? 'tarefa');
        if (!in_array($dados['regra_duplicado'], ['tarefa', 'mesclar', 'criar'], true)) {
            $erros['regra_duplicado'] = 'Regra de duplicado inválida.';
        }
        $dados['ativo'] = in_array($d['ativo'] ?? 1, [1, '1', true, 'on'], true) ? 1 : 0;

        [$camposOk, $errosCampos] = self::validarCampos($campos);
        return ['erros' => $erros + $errosCampos, 'dados' => $dados, 'campos' => $camposOk];
    }

    /**
     * Pesquisa de satisfação: configuração de disparo (gatilho, dias, validade do link, tarefa para detrator) e campos.
     * @return array{erros:array<string,string>,dados:array,campos:list<array>}
     */
    private static function validarPesquisa(array $d, array $dados, array $erros, array $campos): array
    {
        $dados['tipo'] = 'pesquisa';
        if (trim((string) ($d['mensagem_sucesso'] ?? '')) === '') {
            $dados['mensagem_sucesso'] = 'Obrigado pela sua resposta!';
        }
        if ($dados['texto_botao'] === 'Enviar') {
            $dados['texto_botao'] = 'Enviar resposta';
        }
        $gatilho = (string) ($d['gatilho_tipo'] ?? 'manual');
        if (!isset(self::GATILHOS[$gatilho])) {
            $erros['gatilho_tipo'] = 'Escolha um gatilho válido.';
            $gatilho = 'manual';
        }
        $dados['gatilho_tipo'] = $gatilho;
        $dias = trim((string) ($d['gatilho_dias'] ?? ''));
        $dados['gatilho_dias'] = null;
        if ($gatilho !== 'manual') {
            if (!ctype_digit($dias) || (int) $dias < 1 || (int) $dias > 730) {
                $erros['gatilho_dias'] = 'Informe de 1 a 730 dias.';
            } else {
                $dados['gatilho_dias'] = (int) $dias;
            }
        }
        $validade = trim((string) ($d['validade_dias'] ?? '30'));
        if (!ctype_digit($validade) || (int) $validade < 1 || (int) $validade > 365) {
            $erros['validade_dias'] = 'A validade do link vai de 1 a 365 dias.';
            $validade = '30';
        }
        $dados['validade_dias'] = (int) $validade;
        $dados['tarefa_detrator'] = in_array($d['tarefa_detrator'] ?? 0, [1, '1', true, 'on'], true) ? 1 : 0;
        $dados['ativo'] = in_array($d['ativo'] ?? 1, [1, '1', true, 'on'], true) ? 1 : 0;
        // Campos de captação não se aplicam: ficam nos padrões.
        $dados += ['origem_id_padrao' => null, 'status_padrao' => 'lead', 'criar_negocio' => 0, 'etapa_id_padrao' => null, 'squad_disparado' => null, 'regra_duplicado' => 'tarefa'];

        [$camposOk, $errosCampos] = self::validarCampos($campos, self::destinosPesquisa(), 'pesquisa');
        return ['erros' => $erros + $errosCampos, 'dados' => $dados, 'campos' => $camposOk];
    }

    /** @return array{0:list<array>,1:array<string,string>} */
    private static function validarCampos(array $campos, ?array $destinos = null, string $modo = 'captacao'): array
    {
        $erros = [];
        $destinos ??= self::destinos();
        $ok = [];
        $vistos = [];
        if ($campos === []) {
            return [[], ['campos' => 'Adicione ao menos um campo ao formulário.']];
        }
        if (count($campos) > self::MAX_CAMPOS) {
            return [[], ['campos' => 'Use no máximo ' . self::MAX_CAMPOS . ' campos.']];
        }

        foreach (array_values($campos) as $i => $c) {
            $n = $i + 1;
            $chave = "campo_{$n}";
            $c = (array) $c;
            $destino = (string) ($c['campo_destino'] ?? '');
            $def = $destinos[$destino] ?? null;
            if ($def === null) {
                $erros[$chave] = "Campo {$n}: destino inválido ou indisponível.";
                continue;
            }
            if (isset($vistos[$destino])) {
                $erros[$chave] = "Campo {$n}: o destino \"{$def['rotulo']}\" já foi usado em outro campo.";
                continue;
            }
            $vistos[$destino] = true;

            $rotulo = trim((string) ($c['rotulo'] ?? ''));
            if ($rotulo === '' || mb_strlen($rotulo) > 120) {
                $erros[$chave] = "Campo {$n}: informe o rótulo (até 120 caracteres).";
                continue;
            }
            $tipo = (string) ($c['tipo'] ?? $def['padrao']);
            if (!in_array($tipo, $def['tipos'], true)) {
                $erros[$chave] = "Campo {$n} ({$rotulo}): tipo \"{$tipo}\" não serve para {$def['rotulo']}.";
                continue;
            }
            $placeholder = trim((string) ($c['placeholder'] ?? ''));
            $ajuda = trim((string) ($c['ajuda'] ?? ''));
            if (mb_strlen($placeholder) > 120 || mb_strlen($ajuda) > 200) {
                $erros[$chave] = "Campo {$n} ({$rotulo}): texto de exemplo até 120 e ajuda até 200 caracteres.";
                continue;
            }

            $opcoes = [];
            if ($tipo === 'select' && $def['opcoes'] === null) {
                $bruto = $c['opcoes'] ?? [];
                $linhas = is_array($bruto) ? $bruto : preg_split('/\R/u', (string) $bruto);
                foreach ($linhas as $linha) {
                    $linha = trim((string) $linha);
                    if ($linha !== '' && !in_array($linha, $opcoes, true)) {
                        $opcoes[] = $linha;
                    }
                }
                if ($opcoes === [] || count($opcoes) > 50 || max(array_map('mb_strlen', $opcoes)) > 80) {
                    $erros[$chave] = "Campo {$n} ({$rotulo}): informe de 1 a 50 opções, uma por linha, com até 80 caracteres.";
                    continue;
                }
            }
            $largura = (int) ($c['largura'] ?? 12);
            if ($largura < 1 || $largura > 12) {
                $erros[$chave] = "Campo {$n} ({$rotulo}): a largura vai de 1 a 12.";
                continue;
            }
            $ok[] = [
                'campo_destino' => $destino, 'rotulo' => $rotulo, 'tipo' => $tipo, 'placeholder' => $placeholder !== '' ? $placeholder : null,
                'ajuda' => $ajuda !== '' ? $ajuda : null, 'obrigatorio' => in_array($c['obrigatorio'] ?? 0, [1, '1', true, 'on'], true) ? 1 : 0,
                'opcoes' => $opcoes, 'largura' => $largura,
            ];
        }

        if ($erros === [] && $modo === 'pesquisa') {
            $nota = array_filter($ok, static fn (array $c): bool => $c['campo_destino'] === 'pesquisa.nota' && $c['obrigatorio'] === 1);
            if ($nota === []) {
                $erros['campos'] = 'Inclua o campo "Nota de 0 a 10 (NPS)" e marque-o como obrigatório: é o que calcula o índice.';
            }
        } elseif ($erros === []) {
            $identifica = array_filter($ok, static fn (array $c): bool => in_array($c['campo_destino'], ['empresa.nome_fantasia', 'contato.nome'], true) && $c['obrigatorio'] === 1);
            if ($identifica === []) {
                $erros['campos'] = 'Inclua o campo "Nome fantasia" (empresa) ou "Nome" (contato) e marque-o como obrigatório: é o que identifica quem enviou.';
            }
        }
        return [$ok, $erros];
    }

    public static function urlHttp(string $url): bool
    {
        $p = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true);
    }
}
