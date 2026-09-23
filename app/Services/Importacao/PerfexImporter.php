<?php

declare(strict_types=1);

namespace App\Services\Importacao;

use App\Repositories\Repositorios;
use App\Services\ActionExecutor;
use App\Services\Audit;

/**
 * Migração de dados do Perfex CRM (suporte.fera.net.br) para o CRM Lárbous. Ferramenta de uso único
 * (`scripts/importar-perfex.php`), fora do fluxo normal da aplicação — mas toda escrita ainda passa pelo
 * ActionExecutor (validação + auditoria). As únicas exceções deliberadas são os campos marcados `sis` no
 * Schema (código/número/status/datas de sistema) e `criado_em`/`atualizado_em`: para preservar a data real
 * de cada registro, em vez de "hoje", são gravados direto pelo Repository logo após o `criar()`, com uma
 * entrada extra em log_auditoria registrando o ajuste. Decisões de mapeamento documentadas em docs/DECISOES.md.
 */
final class PerfexImporter
{
    private const ORIGEM = 'sistema';

    /** userid do Perfex a ignorar: é a própria Agência Lárbous, não um cliente. */
    private const CLIENTES_IGNORADOS = [1];

    private ActionExecutor $exec;
    private DumpSqlReader $dump;
    private bool $simular;

    /** @var array<string,int> */
    private array $relatorio = [];
    /** @var list<string> */
    private array $avisos = [];

    // ---- mapas de apoio: entidade => [nome-chave => id] (origens, areas, contrato_tipos, tags) ----
    private array $mapasApoio = ['origens' => [], 'areas' => [], 'contrato_tipos' => [], 'tags' => [], 'motivos_perda' => []];
    private array $etapaPorNome = [];
    private array $etapaPorTipo = []; // 'aberta'|'ganho'|'perdido' => primeira etapa desse tipo no pipeline padrão
    private ?int $pipelineId = null;

    // ---- lookups do Perfex (id => nome/valor) ----
    private array $leadsStatusNome = [];   // id => nome
    private array $leadsSourceNome = [];   // id => nome
    private array $departamentoNome = [];  // id => nome
    private array $tipoContratoNomePerfex = []; // id => nome
    private array $grupoClienteNome = [];  // id => nome
    /** relid|fieldto => [fieldid => valor] */
    private array $customFieldValores = [];
    private array $customFieldDef = []; // id => ['fieldto'=>, 'slug'=>, 'name'=>]

    // ---- mapas perfex id => lárbous id ----
    private array $empresaPorCliente = [];   // tblclients.userid => empresas.id
    private array $empresaPorLead = [];      // tblleads.id (não convertidos) => empresas.id
    private array $contatoPorPerfex = [];    // tblcontacts.id => contatos.id
    private array $negocioPorLead = [];      // tblleads.id => negocios.id
    private array $propostaInfo = [];        // tblproposals.id => ['id'=>, 'empresa_id'=>, 'negocio_id'=>, 'contato_id'=>]
    private array $estimativaInfo = [];      // tblestimates.id => idem
    private array $contratoInfo = [];        // tblcontracts.id => ['id'=>,'empresa_id'=>]
    private array $projetoInfo = [];         // tblprojects.id => ['empresa_id'=>, 'nome'=>]
    private array $ticketInfo = [];          // tbltickets.ticketid => ['empresa_id'=>,'contato_id'=>,'chamado_id'=>]
    private array $leadPorId = [];            // tblleads.id => linha
    private array $clienteUseridPorLead = []; // tblleads.id => tblclients.userid (via tblclients.leadid; client_id do lead não é confiável nesse dump)
    private array $contatoPrincipalPorCliente = []; // tblclients.userid => contatos.id (contato is_primary)
    private array $clienteDataCriacao = [];   // tblclients.userid => datecreated (data/hora)

    public function __construct(DumpSqlReader $dump, ActionExecutor $exec, bool $simular = false)
    {
        $this->dump = $dump;
        $this->exec = $exec;
        $this->simular = $simular;
    }

    public function executar(): array
    {
        $this->carregarLookups();
        $this->prepararApoio();
        $this->importarEmpresasClientes();
        $this->importarLeadsNaoConvertidos();
        $this->importarPropostasEEstimativas();
        $this->importarContratos();
        $this->importarProjetos();
        $this->importarTarefas();
        $this->importarTickets();
        $this->importarNotas();
        $this->importarCobrancas();
        $this->importarCustos();

        return ['contagens' => $this->relatorio, 'avisos' => $this->avisos];
    }

    // =====================================================================================
    // Apoio
    // =====================================================================================

    private function carregarLookups(): void
    {
        foreach ($this->dump->linhas('tblleads_status') as $l) {
            $this->leadsStatusNome[(int) $l['id']] = (string) $l['name'];
        }
        foreach ($this->dump->linhas('tblleads_sources') as $l) {
            $this->leadsSourceNome[(int) $l['id']] = (string) $l['name'];
        }
        foreach ($this->dump->linhas('tbldepartments') as $l) {
            $this->departamentoNome[(int) $l['departmentid']] = (string) $l['name'];
        }
        foreach ($this->dump->linhas('tblcontracts_types') as $l) {
            $this->tipoContratoNomePerfex[(int) $l['id']] = (string) $l['name'];
        }
        foreach ($this->dump->linhas('tblcustomers_groups') as $l) {
            $this->grupoClienteNome[(int) $l['id']] = (string) $l['name'];
        }
        foreach ($this->dump->linhas('tblcustomfields') as $l) {
            $this->customFieldDef[(int) $l['id']] = ['fieldto' => $l['fieldto'], 'slug' => $l['slug'], 'name' => $l['name']];
        }
        foreach ($this->dump->linhas('tblcustomfieldsvalues') as $l) {
            $chave = $l['fieldto'] . ':' . $l['relid'];
            $this->customFieldValores[$chave][(int) $l['fieldid']] = $l['value'];
        }
        foreach ($this->dump->linhas('tblleads') as $l) {
            $this->leadPorId[(int) $l['id']] = $l;
        }
        // client_id do lead não é usado nesse dump (sempre 0); quem indica a conversão é tblclients.leadid.
        foreach ($this->dump->linhas('tblclients') as $c) {
            if ($c['leadid'] !== null) {
                $this->clienteUseridPorLead[(int) $c['leadid']] = (int) $c['userid'];
            }
            $this->clienteDataCriacao[(int) $c['userid']] = $c['datecreated'];
        }
    }

    /** Valor de um campo extra do Perfex pelo slug (mais legível que o id). */
    private function customField(string $fieldto, int $relid, string $slug): ?string
    {
        $chave = $fieldto . ':' . $relid;
        foreach ($this->customFieldValores[$chave] ?? [] as $fieldId => $valor) {
            if (($this->customFieldDef[$fieldId]['slug'] ?? null) === $slug && $valor !== '') {
                return (string) $valor;
            }
        }
        return null;
    }

    private function prepararApoio(): void
    {
        $pipeline = Repositorios::pipelines()->padrao();
        $this->pipelineId = $pipeline !== null ? (int) $pipeline['id'] : null;
        if ($this->pipelineId !== null) {
            foreach (Repositorios::etapas()->doPipeline($this->pipelineId) as $et) {
                $this->etapaPorNome[$this->chaveNome((string) $et['nome'])] = (int) $et['id'];
                $this->etapaPorTipo[(string) $et['tipo']] ??= (int) $et['id'];
            }
        }

        foreach (Repositorios::para('origens')->todas() as $o) {
            $this->mapasApoio['origens'][$this->chaveNome((string) $o['nome'])] = (int) $o['id'];
        }
        foreach (Repositorios::para('areas')->todas() as $a) {
            $this->mapasApoio['areas'][$this->chaveNome((string) $a['nome'])] = (int) $a['id'];
        }
        foreach (Repositorios::para('contrato_tipos')->todas() as $t) {
            $this->mapasApoio['contrato_tipos'][$this->chaveNome((string) $t['nome'])] = (int) $t['id'];
        }
        foreach (Repositorios::tags()->todas() as $t) {
            $this->mapasApoio['tags'][$this->chaveNome((string) $t['nome'])] = (int) $t['id'];
        }
        foreach (Repositorios::para('motivos_perda')->todas() as $m) {
            $this->mapasApoio['motivos_perda'][$this->chaveNome((string) $m['nome'])] = (int) $m['id'];
        }

        // Origens vindas do Perfex (limpa o prefixo "origem-").
        foreach ($this->leadsSourceNome as $nome) {
            $this->encontrarOuCriarSimples('origens', $this->nomeOrigemLimpo($nome));
        }
        // Áreas: os dois departamentos reais do Perfex + uma área dedicada para os projetos antigos.
        foreach ($this->departamentoNome as $nome) {
            $this->encontrarOuCriarSimples('areas', $nome);
        }
        $this->encontrarOuCriarSimples('areas', 'Projetos (Perfex)');
        // Tipos de contrato do Perfex.
        foreach ($this->tipoContratoNomePerfex as $nome) {
            $this->encontrarOuCriarSimples('contrato_tipos', $nome);
        }
        // Tags a partir dos grupos de cliente do Perfex.
        foreach ($this->grupoClienteNome as $nome) {
            $this->encontrarOuCriarSimples('tags', $nome);
        }
    }

    private function chaveNome(string $nome): string
    {
        return mb_strtolower(trim($nome));
    }

    private function nomeOrigemLimpo(string $nome): string
    {
        $nome = preg_replace('/^origem-/', '', $nome) ?? $nome;
        $mapa = [
            'facebook-ads' => 'Facebook Ads', 'google-ads' => 'Google Ads', 'indicacao' => 'Indicação',
            'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'parceria' => 'Parceria',
            'site' => 'Site', 'whatsapp' => 'WhatsApp',
        ];
        return $mapa[$nome] ?? ucfirst(str_replace('-', ' ', $nome));
    }

    private function encontrarOuCriarSimples(string $entidade, string $nome): int
    {
        $nome = trim($nome);
        $chave = $this->chaveNome($nome);
        if (isset($this->mapasApoio[$entidade][$chave])) {
            return $this->mapasApoio[$entidade][$chave];
        }
        if ($this->simular) {
            return $this->mapasApoio[$entidade][$chave] = -1;
        }
        $r = $this->exec->criar($entidade, ['nome' => $nome], self::ORIGEM);
        if (!$r->ok) {
            $this->avisos[] = "Não foi possível criar {$entidade} '{$nome}': " . implode('; ', $r->erros ?? []);
            return $this->mapasApoio[$entidade][$chave] = -1;
        }
        return $this->mapasApoio[$entidade][$chave] = (int) $r->id;
    }

    private function idOrigem(?string $nome): ?int
    {
        if ($nome === null || trim($nome) === '') {
            return null;
        }
        $id = $this->encontrarOuCriarSimples('origens', $nome);
        return $id > 0 ? $id : null;
    }

    private function idArea(string $nome): ?int
    {
        $id = $this->encontrarOuCriarSimples('areas', $nome);
        return $id > 0 ? $id : null;
    }

    private function idTipoContrato(?string $nome): ?int
    {
        if ($nome === null || trim($nome) === '') {
            return null;
        }
        $id = $this->encontrarOuCriarSimples('contrato_tipos', $nome);
        return $id > 0 ? $id : null;
    }

    private function idsTags(array $nomes): array
    {
        $ids = [];
        foreach ($nomes as $nome) {
            $id = $this->encontrarOuCriarSimples('tags', $nome);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    // =====================================================================================
    // Empresas e contatos (tblclients + tblcontacts) — clientes já convertidos no Perfex
    // =====================================================================================

    private function importarEmpresasClientes(): void
    {
        $contatosPorCliente = [];
        foreach ($this->dump->linhas('tblcontacts') as $c) {
            $contatosPorCliente[(int) $c['userid']][] = $c;
        }

        foreach ($this->dump->linhas('tblclients') as $cli) {
            $userid = (int) $cli['userid'];
            if (in_array($userid, self::CLIENTES_IGNORADOS, true)) {
                continue;
            }

            $lead = $cli['leadid'] !== null ? ($this->leadPorId[(int) $cli['leadid']] ?? null) : null;
            $origemNome = $lead !== null ? $this->nomeOrigemLimpo($this->leadsSourceNome[(int) $lead['source']] ?? '') : null;

            $responsavel = $this->blocoResponsavel($userid);
            $notas = "Importado do Perfex CRM (cliente #{$userid}).";
            if ($responsavel !== null) {
                $notas .= "\n\n{$responsavel}";
            }

            $dados = [
                'nome_fantasia' => $this->textoOuNull($cli['company']) ?? "Cliente #{$userid}",
                'razao_social'  => $this->customField('customers', $userid, 'company_nome_fantasia'),
                'cnpj'          => $this->cnpjOuNull($cli['vat']),
                'telefone'      => $this->telefone($cli['phonenumber']),
                'site'          => $this->textoOuNull($cli['website']),
                'cep'           => strlen(so_digitos((string) $cli['zip'])) === 8 ? so_digitos((string) $cli['zip']) : null,
                'logradouro'    => $this->textoOuNull($cli['address']),
                'cidade'        => $this->textoOuNull($cli['city']),
                'uf'            => $this->ufOuNull($cli['state']),
                'pais'          => 'Brasil',
                'status'        => ((int) $cli['active']) === 1 ? 'cliente' : 'ex_cliente',
                'origem_id'     => $this->idOrigem($origemNome),
                'cliente_desde' => $this->dataOuNull($cli['datecreated']),
                'segmento'      => $this->customField('customers', $userid, 'customers_segmentos_de_mercado'),
                'notas'         => $notas,
            ];

            $id = $this->criarComHistorico('empresas', $dados, $this->dataHoraOuNull($cli['datecreated']) ?? agora(), null);
            if ($id === null) {
                continue;
            }
            $this->empresaPorCliente[$userid] = $id;

            $tags = [];
            foreach ($this->dump->linhas('tblcustomer_groups') as $g) {
                if ((int) $g['customer_id'] === $userid && isset($this->grupoClienteNome[(int) $g['groupid']])) {
                    $tags[] = $this->grupoClienteNome[(int) $g['groupid']];
                }
            }
            if ($tags !== [] && !$this->simular) {
                Repositorios::tags()->substituir('empresas', $id, $this->idsTags($tags));
            }

            foreach ($contatosPorCliente[$userid] ?? [] as $ct) {
                $this->importarContato($ct, $id);
            }
        }
    }

    private function blocoResponsavel(int $userid): ?string
    {
        $nome = $this->customField('customers', $userid, 'company_responsavel_contrato');
        $cpf = $this->customField('customers', $userid, 'company_responsavel_cpf');
        $rg = $this->customField('customers', $userid, 'company_responsavel_rg');
        if ($nome === null && $cpf === null && $rg === null) {
            return null;
        }
        $partes = ['Responsável pelo contrato: ' . ($nome ?? '—')];
        if ($cpf !== null) {
            $partes[] = "CPF {$cpf}";
        }
        if ($rg !== null) {
            $partes[] = "RG {$rg}";
        }
        return implode(' — ', $partes);
    }

    private function importarContato(array $ct, int $empresaId): void
    {
        $id = (int) $ct['id'];
        $nomeCompleto = trim($this->textoOuNull($ct['firstname']) . ' ' . $this->textoOuNull($ct['lastname']) . '');
        $dados = [
            'nome'       => $this->textoOuNull($ct['firstname']) ?? ($nomeCompleto !== '' ? $nomeCompleto : "Contato #{$id}"),
            'sobrenome'  => $this->textoOuNull($ct['lastname']),
            'data_nascimento' => $this->dataOuNull($this->customField('contacts', $id, 'contacts_nascimento')),
            'empresa_id' => $empresaId,
            'cargo'      => $this->textoOuNull($ct['title']),
            'papel_decisao' => ((int) $ct['is_primary']) === 1 ? 'decisor' : null,
            'email'      => $this->emailOuNull($ct['email']),
            'telefone'   => $this->telefone($ct['phonenumber']),
            'status'     => ((int) $ct['active']) === 1 ? 'ativo' : 'inativo',
            'notas'      => "Importado do Perfex CRM (contato #{$id}).",
        ];
        $novoId = $this->criarComHistorico('contatos', $dados, $this->dataHoraOuNull($ct['datecreated']) ?? agora(), null);
        if ($novoId !== null) {
            $this->contatoPorPerfex[$id] = $novoId;
            if (((int) $ct['is_primary']) === 1) {
                $this->contatoPrincipalPorCliente[(int) $ct['userid']] = $novoId;
            }
        }
    }

    // =====================================================================================
    // Leads (tblleads): viram negócio sempre; empresa/contato só quando o lead nunca virou
    // cliente (tblclients.leadid indica quem converteu — client_id do lead não é usado nesse dump).
    // =====================================================================================

    private const MAPA_STATUS_LEAD_ETAPA = [
        'capturado' => 'novo lead',
        'qualificação inicial' => 'qualificado',
        'engajado' => 'reunião',
        'oportunidade' => 'proposta',
        'negociação' => 'negociação',
        'cliente' => 'ganho',
    ];

    private function importarLeadsNaoConvertidos(): void
    {
        foreach ($this->leadPorId as $leadId => $lead) {
            $clienteUserid = $this->clienteUseridPorLead[$leadId] ?? null;
            $convertido = $clienteUserid !== null && isset($this->empresaPorCliente[$clienteUserid]);

            $nomeCompleto = trim((string) ($lead['name'] ?? ''));
            $primeiroEspaco = strpos($nomeCompleto, ' ');
            $primeiroNome = $primeiroEspaco !== false ? substr($nomeCompleto, 0, $primeiroEspaco) : $nomeCompleto;
            $sobrenome = $primeiroEspaco !== false ? substr($nomeCompleto, $primeiroEspaco + 1) : null;

            $perdido = !$convertido && ((int) $lead['lost']) === 1;
            $statusNome = $this->chaveNome($this->leadsStatusNome[(int) $lead['status']] ?? '');
            $etapaNome = $convertido ? 'ganho' : (self::MAPA_STATUS_LEAD_ETAPA[$statusNome] ?? 'novo lead');
            $ganho = $convertido || (!$perdido && $etapaNome === 'ganho');
            // etapa e status precisam bater (regra do ActionExecutor): etapa de tipo ganho/perdido nesses casos,
            // senão a etapa mapeada pelo nome do status do Perfex (sempre "aberta").
            $etapaId = match (true) {
                $perdido => $this->etapaPorTipo['perdido'] ?? null,
                $ganho => $this->etapaPorTipo['ganho'] ?? null,
                default => $this->etapaPorNome[$etapaNome] ?? reset($this->etapaPorNome) ?: null,
            };

            $origemNome = $this->nomeOrigemLimpo($this->leadsSourceNome[(int) $lead['source']] ?? '');
            $criadoEm = $this->dataHoraOuNull($lead['dateadded']) ?? agora();

            if ($convertido) {
                // Já existe empresa/contato (criados a partir de tblclients); só falta o negócio que originou a conversão.
                $empresaId = $this->empresaPorCliente[$clienteUserid];
                $contatoId = $this->contatoPrincipalPorCliente[$clienteUserid] ?? null;
            } else {
                $dadosEmpresa = [
                    'nome_fantasia' => $this->textoOuNull($lead['company']) ?? $primeiroNome ?: "Lead #{$leadId}",
                    'telefone'      => $this->telefone($lead['phonenumber']),
                    'site'          => $this->textoOuNull($lead['website']),
                    'cidade'        => $this->textoOuNull($lead['city']),
                    'uf'            => $this->ufOuNull($lead['state']),
                    'pais'          => 'Brasil',
                    'status'        => $perdido ? 'inativo' : 'lead',
                    'origem_id'     => $this->idOrigem($origemNome),
                    'notas'         => "Importado do Perfex CRM (lead #{$leadId}, nunca convertido em cliente lá).",
                ];
                $empresaId = $this->criarComHistorico('empresas', $dadosEmpresa, $criadoEm, null);
                if ($empresaId === null) {
                    continue;
                }
                $this->empresaPorLead[$leadId] = $empresaId;

                $contatoId = null;
                if ($primeiroNome !== '') {
                    $dadosContato = [
                        'nome' => $primeiroNome, 'sobrenome' => $sobrenome, 'empresa_id' => $empresaId,
                        'email' => $this->emailOuNull($lead['email']), 'telefone' => $this->telefone($lead['phonenumber']),
                        'status' => 'ativo', 'notas' => "Importado do Perfex CRM (lead #{$leadId}).",
                    ];
                    $contatoId = $this->criarComHistorico('contatos', $dadosContato, $criadoEm, null);
                    if ($contatoId !== null) {
                        $this->contatoPorPerfex['lead:' . $leadId] = $contatoId;
                    }
                }
            }

            // valor_fechado é obrigatório para status=ganho; quando o Perfex não registrou lead_value, usa 0
            // (fica visivelmente um placeholder — sem inventar um valor que nunca existiu no Perfex).
            $valorFechado = $ganho ? ($this->reaisOuNull($lead['lead_value']) ?? 0) : null;
            $notasNegocio = $this->textoOuNull($lead['description']);
            if ($ganho && $this->reaisOuNull($lead['lead_value']) === null) {
                $notasNegocio = trim(($notasNegocio !== null ? $notasNegocio . "\n\n" : '') . 'Valor fechado não registrado no Perfex (0 é um placeholder).');
            }
            $dadosNegocio = [
                'titulo' => $this->textoOuNull($lead['title']) ?? ('Oportunidade — ' . ($this->textoOuNull($lead['company']) ?? $primeiroNome)),
                'empresa_id' => $empresaId, 'contato_principal_id' => $contatoId,
                'etapa_id' => $etapaId, 'status' => $perdido ? 'perdido' : ($ganho ? 'ganho' : 'aberto'),
                'origem_id' => $this->idOrigem($origemNome),
                'valor_estimado' => $this->reaisOuNull($lead['lead_value']),
                'valor_fechado' => $valorFechado,
                'notas' => $notasNegocio,
                'motivo_perda_id' => $perdido ? $this->encontrarOuCriarSimples('motivos_perda', 'Outro') : null,
                'detalhe_perda' => $perdido ? 'Perfex: marcado como perdido (motivo não registrado lá).' : null,
            ];
            $camposSistema = ['entrou_etapa_em' => $criadoEm];
            if ($perdido) {
                $camposSistema['data_fechamento'] = $this->dataOuNull($lead['last_status_change']) ?? $this->dataOuNull($lead['dateadded']);
            } elseif ($convertido) {
                // data do cliente (evento real e distinto), não last_status_change (muitos leads têm o mesmo
                // horário ali — artefato de alguma operação em lote no Perfex, não a data real do fechamento).
                $camposSistema['data_fechamento'] = $this->dataOuNull($this->clienteDataCriacao[$clienteUserid] ?? null) ?? $this->dataOuNull($lead['dateadded']);
            } elseif ($ganho) {
                $camposSistema['data_fechamento'] = $this->dataOuNull($lead['date_converted']) ?? $this->dataOuNull($lead['last_status_change']);
            }
            $negocioId = $this->criarComHistorico('negocios', $dadosNegocio, $criadoEm, null, $camposSistema);
            if ($negocioId !== null) {
                $this->negocioPorLead[$leadId] = $negocioId;
                if (!$this->simular) {
                    $codigo = Repositorios::negocios()->proximoCodigo((int) substr($criadoEm, 0, 4));
                    Repositorios::para('negocios')->atualizar($negocioId, ['codigo' => $codigo]);
                }
            }
        }
    }

    /** Resolve empresa/negócio/contato a partir de rel_type/rel_id no estilo Perfex (customer|lead). */
    private function resolverVinculo(?string $relType, int $relId): array
    {
        return match ($relType) {
            'customer' => [
                'empresa_id' => $this->empresaPorCliente[$relId] ?? null,
                'negocio_id' => null,
                'contato_id' => $this->contatoPrincipalPorCliente[$relId] ?? null,
            ],
            'lead' => [
                'empresa_id' => $this->empresaPorCliente[$this->clienteUseridPorLead[$relId] ?? -1]
                    ?? $this->empresaPorLead[$relId] ?? null,
                'negocio_id' => $this->negocioPorLead[$relId] ?? null,
                'contato_id' => $this->contatoPorPerfex['lead:' . $relId] ?? null,
            ],
            default => ['empresa_id' => null, 'negocio_id' => null, 'contato_id' => null],
        };
    }

    /** HTML rico do Perfex (propostas) -> texto simples, coerente com os campos textarea do Lárbous (sem campo HTML lá). */
    private function htmlParaTexto(?string $html, int $limite = 4900): ?string
    {
        $html = (string) ($html ?? '');
        if (trim($html) === '') {
            return null;
        }
        $texto = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $html)), ENT_QUOTES, 'UTF-8');
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n{3,}/u', "\n\n", trim($texto)) ?? $texto;
        if (mb_strlen($texto) > $limite) {
            $texto = mb_substr($texto, 0, $limite) . "\n\n[conteúdo truncado na migração — versão completa só no Perfex]";
        }
        return $texto;
    }

    // =====================================================================================
    // Propostas e estimativas (tblproposals + tblestimates) — os dois módulos viram `propostas`
    // =====================================================================================

    /**
     * subtotal/total de propostas são sempre recalculados a partir dos itens (nunca aceitos como campo direto,
     * nem via patch de sistema — regra de negócio roda antes disso). Pra preservar o valor original do Perfex,
     * cada proposta importada ganha um item único com o subtotal original; assim desconto_valor também é
     * validado contra um subtotal real, não zero.
     */
    private function itemUnico(mixed $subtotalReais): array
    {
        $valor = $this->reaisOuNull($subtotalReais);
        return [[
            'descricao' => 'Itens da proposta (importado do Perfex, sem detalhamento por item)',
            'quantidade' => 1, 'valor_unitario' => $valor ?? 0, 'desconto' => 0, 'recorrente' => 0, 'ordem' => 0,
        ]];
    }

    /** Perfex às vezes tem validade anterior à emissão (dado velho); a regra do Lárbous rejeita isso. */
    private function validadeConsistente(?string $emissao, ?string $validade): ?string
    {
        return ($emissao !== null && $validade !== null && $validade < $emissao) ? null : $validade;
    }

    private function importarPropostasEEstimativas(): void
    {
        foreach ($this->dump->linhas('tblproposals') as $p) {
            $vinculo = $this->resolverVinculo($p['rel_type'] !== null ? (string) $p['rel_type'] : null, (int) ($p['rel_id'] ?? 0));
            $status = match ((int) $p['status']) {
                3 => 'aceita', 2 => 'enviada', 4 => 'recusada', 6 => 'expirada', default => 'rascunho',
            };
            $descontoPercentual = ((string) ($p['discount_type'] ?? '')) === 'percent';
            $criadoEm = $this->dataHoraOuNull($p['datecreated']) ?? agora();
            $emissao = $this->dataOuNull($p['date']);

            $dados = [
                'titulo'        => $this->textoOuNull($p['subject']) ?? "Proposta Perfex #{$p['id']}",
                'negocio_id'    => $vinculo['negocio_id'], 'empresa_id' => $vinculo['empresa_id'], 'contato_id' => $vinculo['contato_id'],
                'data_emissao'  => $emissao,
                'validade'      => $this->validadeConsistente($emissao, $this->dataOuNull($p['open_till'])),
                'desconto_tipo' => $descontoPercentual ? 'percentual' : 'valor',
                'desconto_valor' => $this->reaisOuNull($descontoPercentual ? $p['discount_percent'] : $p['discount_total']),
                'escopo'        => $this->htmlParaTexto($p['content']),
                'observacoes'   => 'Importado do Perfex CRM (proposta #' . $p['id'] . ').',
                'itens'         => $this->itemUnico($p['subtotal']),
            ];
            $camposSistema = [];
            if ($status !== 'rascunho') {
                $camposSistema['enviada_em'] = $criadoEm;
            }
            if ($status === 'aceita') {
                $camposSistema['respondida_em'] = $this->dataHoraOuNull($p['acceptance_date']) ?? $criadoEm;
                $camposSistema['aceite_nome'] = $this->textoOuNull(trim(($p['acceptance_firstname'] ?? '') . ' ' . ($p['acceptance_lastname'] ?? '')));
            } elseif ($status === 'recusada') {
                $camposSistema['respondida_em'] = $criadoEm;
            }
            $camposSistema['status'] = $status;

            $id = $this->criarComHistorico('propostas', $dados, $criadoEm, null, $camposSistema);
            if ($id === null) {
                continue;
            }
            $ano = (int) substr($criadoEm, 0, 4);
            if (!$this->simular) {
                $numero = Repositorios::propostas()->proximoNumero($ano);
                Repositorios::para('propostas')->atualizar($id, ['numero' => $numero]);
            }
            $this->propostaInfo[(int) $p['id']] = ['id' => $id, 'empresa_id' => $vinculo['empresa_id'], 'negocio_id' => $vinculo['negocio_id']];
        }

        foreach ($this->dump->linhas('tblestimates') as $e) {
            $clienteId = (int) $e['clientid'];
            $empresaId = $clienteId > 0 ? ($this->empresaPorCliente[$clienteId] ?? null) : null;
            $status = match ((int) $e['status']) {
                4 => 'aceita', 3 => 'recusada', 5 => 'expirada', 2 => 'enviada', default => 'rascunho',
            };
            $criadoEm = $this->dataHoraOuNull($e['datecreated']) ?? agora();
            $emissao = $this->dataOuNull($e['date']);

            $dados = [
                'titulo'       => 'Orçamento ' . ($this->textoOuNull((string) $e['formatted_number']) ?? "#{$e['id']}"),
                'empresa_id'   => $empresaId, 'contato_id' => $clienteId > 0 ? ($this->contatoPrincipalPorCliente[$clienteId] ?? null) : null,
                'data_emissao' => $emissao, 'validade' => $this->validadeConsistente($emissao, $this->dataOuNull($e['expirydate'])),
                'desconto_tipo' => 'valor', 'desconto_valor' => $this->reaisOuNull($e['discount_total']),
                'observacoes'  => 'Importado do Perfex CRM (estimativa/orçamento #' . $e['id'] . ', módulo separado de Proposta lá).',
                'itens'        => $this->itemUnico($e['subtotal']),
            ];
            $camposSistema = ['status' => $status];
            if ($status !== 'rascunho') {
                $camposSistema['enviada_em'] = $criadoEm;
            }
            if ($status === 'aceita') {
                $camposSistema['respondida_em'] = $this->dataHoraOuNull($e['acceptance_date']) ?? $criadoEm;
            }

            $id = $this->criarComHistorico('propostas', $dados, $criadoEm, null, $camposSistema);
            if ($id === null) {
                continue;
            }
            $ano = (int) substr($criadoEm, 0, 4);
            if (!$this->simular) {
                $numero = Repositorios::propostas()->proximoNumero($ano);
                Repositorios::para('propostas')->atualizar($id, ['numero' => $numero]);
            }
            $this->estimativaInfo[(int) $e['id']] = ['id' => $id, 'empresa_id' => $empresaId, 'negocio_id' => null];
        }
    }

    // =====================================================================================
    // Contratos (tblcontracts)
    // =====================================================================================

    private function importarContratos(): void
    {
        foreach ($this->dump->linhas('tblcontracts') as $c) {
            $clienteId = (int) $c['client'];
            $empresaId = $this->empresaPorCliente[$clienteId] ?? null;
            $criadoEm = $this->dataHoraOuNull($c['dateadded']) ?? agora();
            $assinado = ((int) $c['signed']) === 1;
            $dataFim = $this->dataOuNull($c['dateend']);

            $status = match (true) {
                !$assinado && $this->textoOuNull($c['last_sent_at']) !== null => 'enviado',
                !$assinado => 'rascunho',
                $dataFim !== null && $dataFim < hoje() => 'vencido',
                default => 'ativo',
            };

            $extras = [];
            foreach ([
                'contracts_representante_legal' => 'Representante legal',
                'contracts_cpf_do_representante' => 'CPF do representante',
                'contracts_rg_do_representante' => 'RG do representante',
                'contracts_valor_promocional' => 'Valor promocional',
                'contracts_percentual_desconto' => 'Percentual de desconto',
                'contracts_periodo_desconto' => 'Período de desconto',
            ] as $slug => $rotulo) {
                $v = $this->customField('contracts', (int) $c['id'], $slug);
                if ($v !== null) {
                    $extras[] = "{$rotulo}: {$v}";
                }
            }
            $diaPagamento = $this->customField('contracts', (int) $c['id'], 'contracts_dia_de_pagamento');

            $dados = [
                'titulo'      => $this->textoOuNull($c['subject']) ?? "Contrato Perfex #{$c['id']}",
                'tipo_id'     => $this->idTipoContrato($this->tipoContratoNomePerfex[(int) ($c['contract_type'] ?? 0)] ?? null),
                'empresa_id'  => $empresaId, 'contato_id' => $clienteId > 0 ? ($this->contatoPrincipalPorCliente[$clienteId] ?? null) : null,
                'valor_total' => $this->reaisOuNull($c['contract_value']),
                'recorrencia' => 'unica',
                'dia_vencimento_pagamento' => $diaPagamento !== null && is_numeric($diaPagamento) ? (int) $diaPagamento : null,
                'data_inicio' => $this->dataOuNull($c['datestart']), 'data_fim' => $dataFim,
                'indice_reajuste' => 'nenhum',
                'conteudo'    => $this->textoOuNull((string) $c['content']),
                'notas'       => 'Importado do Perfex CRM (contrato #' . $c['id'] . ($extras !== [] ? ").\n\n" . implode("\n", $extras) : ').'),
            ];
            $camposSistema = ['status' => $status];
            if ($assinado) {
                $camposSistema['assinado_em'] = $this->dataHoraOuNull($c['acceptance_date']) ?? $criadoEm;
                $nomeAssinatura = trim(($c['acceptance_firstname'] ?? '') . ' ' . ($c['acceptance_lastname'] ?? ''));
                if ($nomeAssinatura !== '') {
                    $camposSistema['assinatura_nome'] = $nomeAssinatura;
                }
            }
            if ($status === 'enviado') {
                $camposSistema['enviado_em'] = $this->dataHoraOuNull($c['last_sent_at']);
            }

            $id = $this->criarComHistorico('contratos', $dados, $criadoEm, null, $camposSistema);
            if ($id === null) {
                continue;
            }
            $ano = (int) substr($criadoEm, 0, 4);
            if (!$this->simular) {
                $numero = Repositorios::contratos()->proximoNumero($ano);
                Repositorios::para('contratos')->atualizar($id, ['numero' => $numero]);
            }
            $this->contratoInfo[(int) $c['id']] = ['id' => $id, 'empresa_id' => $empresaId];
        }
    }

    // =====================================================================================
    // Projetos (tblprojects + tblmilestones) — sem entidade própria no Lárbous; viram `chamados`
    // numa área dedicada, com os marcos como checklist.
    // =====================================================================================

    private function importarProjetos(): void
    {
        $milestonesPorProjeto = [];
        foreach ($this->dump->linhas('tblmilestones') as $m) {
            $milestonesPorProjeto[(int) $m['project_id']][] = $m;
        }
        $areaId = $this->idArea('Projetos (Perfex)');

        foreach ($this->dump->linhas('tblprojects') as $p) {
            $clienteId = (int) $p['clientid'];
            $empresaId = $this->empresaPorCliente[$clienteId] ?? null;
            $criadoEm = $this->dataHoraOuNull($p['project_created']) ?? agora();
            // status Perfex: 1 not started, 2 in progress, 3 on hold, 4 finished (padrão observado no módulo).
            $status = match ((int) $p['status']) {
                4 => 'concluido', 3 => 'aguardando', 2 => 'andamento', default => 'aberto',
            };
            $checklist = array_map(
                static fn (array $m): array => ['texto' => (string) $m['name'], 'feito' => (int) $p['status'] === 4 ? 1 : 0],
                $milestonesPorProjeto[(int) $p['id']] ?? []
            );

            $dados = [
                'titulo' => (string) $p['name'], 'descricao' => $this->textoOuNull($p['description']),
                'area_id' => $areaId, 'prioridade' => 'media', 'status' => $status,
                'vencimento' => $this->dataOuNull($p['deadline']),
                'empresa_id' => $empresaId, 'contato_id' => $clienteId > 0 ? ($this->contatoPrincipalPorCliente[$clienteId] ?? null) : null,
                'checklist' => $checklist,
            ];
            $camposSistema = [];
            if ($status === 'concluido') {
                $camposSistema['concluido_em'] = $this->dataHoraOuNull($p['date_finished']) ?? $criadoEm;
            }
            $id = $this->criarComHistorico('chamados', $dados, $criadoEm, null, $camposSistema);
            if ($id === null) {
                continue;
            }
            if (!$this->simular) {
                $codigo = Repositorios::chamados()->proximoCodigo((int) substr($criadoEm, 0, 4));
                Repositorios::para('chamados')->atualizar($id, ['codigo' => $codigo]);
            }
            $this->projetoInfo[(int) $p['id']] = ['empresa_id' => $empresaId, 'nome' => (string) $p['name']];
        }
    }

    // =====================================================================================
    // Tarefas (tbltasks) — vínculo resolvido por rel_type/rel_id (customer/lead/project/proposal/estimate/ticket)
    // =====================================================================================

    private function importarTarefas(): void
    {
        foreach ($this->dump->linhas('tbltasks') as $t) {
            $relType = (string) ($t['rel_type'] ?? '');
            $relId = (int) ($t['rel_id'] ?? 0);
            $prefixo = '';
            $empresaId = $contatoId = $negocioId = null;

            switch ($relType) {
                case 'customer':
                    $empresaId = $this->empresaPorCliente[$relId] ?? null;
                    $contatoId = $this->contatoPrincipalPorCliente[$relId] ?? null;
                    break;
                case 'lead':
                    $v = $this->resolverVinculo('lead', $relId);
                    $empresaId = $v['empresa_id']; $contatoId = $v['contato_id']; $negocioId = $v['negocio_id'];
                    break;
                case 'project':
                    $proj = $this->projetoInfo[$relId] ?? null;
                    $empresaId = $proj['empresa_id'] ?? null;
                    $prefixo = $proj !== null ? "[Projeto: {$proj['nome']}] " : '';
                    break;
                case 'proposal':
                    $pi = $this->propostaInfo[$relId] ?? null;
                    $empresaId = $pi['empresa_id'] ?? null; $negocioId = $pi['negocio_id'] ?? null;
                    break;
                case 'estimate':
                    $ei = $this->estimativaInfo[$relId] ?? null;
                    $empresaId = $ei['empresa_id'] ?? null;
                    break;
                case 'ticket':
                    $ti = $this->ticketInfo[$relId] ?? null;
                    $empresaId = $ti['empresa_id'] ?? null; $contatoId = $ti['contato_id'] ?? null;
                    break;
            }

            $concluida = $t['datefinished'] !== null;
            $criadoEm = $this->dataHoraOuNull($t['dateadded']) ?? agora();
            $titulo = $prefixo . (string) ($t['name'] ?? "Tarefa Perfex #{$t['id']}");
            $dados = [
                'titulo' => mb_strlen($titulo) > 200 ? mb_substr($titulo, 0, 199) . '…' : $titulo,
                'descricao' => $this->htmlParaTexto($t['description']),
                'prioridade' => match ((int) ($t['priority'] ?? 2)) { 4 => 'urgente', 3 => 'alta', 1 => 'baixa', default => 'media' },
                'status' => $concluida ? 'concluida' : 'pendente',
                'vencimento' => $this->dataOuNull($t['duedate']),
                'empresa_id' => $empresaId, 'contato_id' => $contatoId, 'negocio_id' => $negocioId,
            ];
            $camposSistema = $concluida ? ['concluida_em' => $this->dataHoraOuNull($t['datefinished'])] : [];
            $this->criarComHistorico('tarefas', $dados, $criadoEm, null, $camposSistema);
        }
    }

    // =====================================================================================
    // Tickets (tbltickets + tblticket_replies) — viram `chamados`; as respostas, sem thread própria
    // no chamado do Lárbous, entram na timeline da empresa como atividades.
    // =====================================================================================

    private function importarTickets(): void
    {
        $repliesPorTicket = [];
        foreach ($this->dump->linhas('tblticket_replies') as $r) {
            $repliesPorTicket[(int) $r['ticketid']][] = $r;
        }

        foreach ($this->dump->linhas('tbltickets') as $t) {
            $clienteId = (int) $t['userid'];
            $empresaId = $this->empresaPorCliente[$clienteId] ?? null;
            $contatoId = $clienteId > 0 ? ($this->contatoPrincipalPorCliente[$clienteId] ?? null) : null;
            $areaNome = $this->departamentoNome[(int) $t['department']] ?? 'Suporte';
            $criadoEm = $this->dataHoraOuNull($t['date']) ?? agora();
            // status Perfex: 1 Open, 2 In progress, 3 Answered, 4 On Hold, 5 Closed (confirmado no dump: tbltickets_status).
            $status = match ((int) $t['status']) {
                5 => 'concluido', 4 => 'aguardando', 3 => 'aguardando', 2 => 'andamento', default => 'aberto',
            };
            $dados = [
                'titulo' => $this->textoOuNull((string) $t['subject']) ?? "Chamado Perfex #{$t['ticketid']}",
                'descricao' => $this->htmlParaTexto((string) $t['message']),
                'area_id' => $this->idArea($areaNome),
                'prioridade' => match ((int) $t['priority']) { 3 => 'alta', 1 => 'baixa', default => 'media' },
                'status' => $status,
                'empresa_id' => $empresaId, 'contato_id' => $contatoId,
            ];
            $camposSistema = [];
            if ($status === 'concluido') {
                $camposSistema['concluido_em'] = $this->dataHoraOuNull($t['lastreply']) ?? $criadoEm;
            }
            $id = $this->criarComHistorico('chamados', $dados, $criadoEm, null, $camposSistema);
            if ($id === null) {
                continue;
            }
            if (!$this->simular) {
                $codigo = Repositorios::chamados()->proximoCodigo((int) substr($criadoEm, 0, 4));
                Repositorios::para('chamados')->atualizar($id, ['codigo' => $codigo]);
            }
            $this->ticketInfo[(int) $t['ticketid']] = ['empresa_id' => $empresaId, 'contato_id' => $contatoId, 'chamado_id' => $id];

            if ($empresaId === null) {
                continue; // sem empresa vinculada, não dá pra registrar a conversa na timeline
            }
            foreach ($repliesPorTicket[(int) $t['ticketid']] ?? [] as $rep) {
                $quem = $rep['admin'] !== null ? 'Equipe' : ($this->textoOuNull((string) $rep['name']) ?? 'Cliente');
                $dadosAtividade = [
                    'tipo' => 'nota', 'assunto' => "Chamado #{$t['ticketid']}: {$dados['titulo']}",
                    'descricao' => "{$quem}: " . ($this->htmlParaTexto((string) $rep['message']) ?? ''),
                    'data_hora' => $this->dataHoraOuNull($rep['date']) ?? $criadoEm,
                    'empresa_id' => $empresaId, 'contato_id' => $contatoId,
                ];
                $this->criarComHistorico('atividades', $dadosAtividade, $dadosAtividade['data_hora'], null);
            }
        }
    }

    // =====================================================================================
    // Notas (tblnotes) — anotações soltas do Perfex (cliente/lead), viram atividades tipo nota.
    // =====================================================================================

    private function importarNotas(): void
    {
        foreach ($this->dump->linhas('tblnotes') as $n) {
            $relId = (int) $n['rel_id'];
            $empresaId = $contatoId = $negocioId = null;
            if ($n['rel_type'] === 'customer') {
                $empresaId = $this->empresaPorCliente[$relId] ?? null;
                $contatoId = $this->contatoPrincipalPorCliente[$relId] ?? null;
            } elseif ($n['rel_type'] === 'lead') {
                $v = $this->resolverVinculo('lead', $relId);
                $empresaId = $v['empresa_id']; $contatoId = $v['contato_id']; $negocioId = $v['negocio_id'];
            }
            if ($empresaId === null) {
                continue;
            }
            $dataHora = $this->dataHoraOuNull($n['date_contacted']) ?? $this->dataHoraOuNull($n['dateadded']) ?? agora();
            $dados = [
                'tipo' => 'nota', 'descricao' => $this->textoOuNull($n['description']),
                'data_hora' => $dataHora, 'empresa_id' => $empresaId, 'contato_id' => $contatoId, 'negocio_id' => $negocioId,
            ];
            $this->criarComHistorico('atividades', $dados, $dataHora, null);
        }
    }

    // =====================================================================================
    // Cobranças (tblinvoices) — histórico puro: sem asaas_id, sem tentar emitir nada.
    // =====================================================================================

    private function importarCobrancas(): void
    {
        $pagamentosPorFatura = [];
        foreach ($this->dump->linhas('tblinvoicepaymentrecords') as $pg) {
            $id = (int) $pg['invoiceid'];
            if (!isset($pagamentosPorFatura[$id]) || (string) $pg['date'] > $pagamentosPorFatura[$id]) {
                $pagamentosPorFatura[$id] = (string) $pg['date'];
            }
        }

        foreach ($this->dump->linhas('tblinvoices') as $inv) {
            $statusPerfex = (int) $inv['status'];
            if ($statusPerfex === 6) {
                continue; // rascunho no Perfex — nunca foi uma cobrança real
            }
            $clienteId = (int) $inv['clientid'];
            $empresaId = $this->empresaPorCliente[$clienteId] ?? null;
            if ($empresaId === null) {
                continue; // cobrança exige empresa no Lárbous
            }
            // 2 Paid, 3 Partially paid (registrado como pendente — o valor total nem sempre foi recebido), 4 Overdue, 5 Cancelled.
            $status = match ($statusPerfex) {
                2 => 'pago', 4 => 'vencido', 5 => 'cancelado', default => 'pendente',
            };
            $criadoEm = $this->dataHoraOuNull($inv['datecreated']) ?? agora();
            $notas = 'Importado do Perfex CRM (fatura #' . $inv['id'] . ').';
            if ($statusPerfex === 3) {
                $notas .= ' Só parcialmente paga lá — mantida como pendente aqui para não inflar o DRE.';
            }
            $dados = [
                'empresa_id' => $empresaId, 'tipo' => 'avulsa',
                'descricao' => 'Fatura Perfex #' . $inv['id'] . ($inv['formatted_number'] !== null ? " ({$inv['formatted_number']})" : ''),
                'valor' => $this->reaisOuNull($inv['total']),
                'forma_pagamento' => 'indefinido',
                'vencimento' => $this->dataOuNull($inv['duedate']) ?? $this->dataOuNull($inv['date']) ?? hoje(),
                'notas' => $notas,
            ];
            $camposSistema = ['status' => $status];
            if ($status === 'pago' && isset($pagamentosPorFatura[(int) $inv['id']])) {
                $camposSistema['data_pagamento'] = $pagamentosPorFatura[(int) $inv['id']];
            }
            $this->criarComHistorico('cobrancas', $dados, $criadoEm, null, $camposSistema);
        }
    }

    // =====================================================================================
    // Custos (tblexpenses)
    // =====================================================================================

    private function importarCustos(): void
    {
        $categorias = [];
        foreach ($this->dump->linhas('tblexpenses_categories') as $c) {
            $categorias[(int) $c['id']] = (string) $c['name'];
        }

        foreach ($this->dump->linhas('tblexpenses') as $ex) {
            $clienteId = (int) $ex['clientid'];
            $empresaId = $clienteId > 0 ? ($this->empresaPorCliente[$clienteId] ?? null) : null;
            if ($empresaId === null) {
                // `custos` do Lárbous exige empresa ou negócio (é pensado pro DRE por cliente, não bookkeeping
                // geral da agência) — despesas internas do Perfex (pró-labore, hospedagem própria, impostos
                // etc.) não têm onde entrar aqui. Ver docs/DECISOES.md.
                $this->contar('custos_ignorados_sem_cliente');
                continue;
            }
            $criadoEm = $this->dataHoraOuNull($ex['dateadded']) ?? agora();
            $dados = [
                'empresa_id' => $empresaId,
                'descricao' => $this->textoOuNull($ex['expense_name']) ?? $this->textoOuNull($ex['note']) ?? "Despesa Perfex #{$ex['id']}",
                'valor' => $this->reaisOuNull($ex['amount']),
                'data' => $this->dataOuNull($ex['date']) ?? hoje(),
                'categoria' => $categorias[(int) $ex['category']] ?? null,
                'recorrente' => ((int) ($ex['recurring'] ?? 0)) === 1,
            ];
            $this->criarComHistorico('custos', $dados, $criadoEm, null);
        }
    }

    // =====================================================================================
    // Helpers genéricos
    // =====================================================================================

    private function contar(string $chave): void
    {
        $this->relatorio[$chave] = ($this->relatorio[$chave] ?? 0) + 1;
    }

    /**
     * Cria via ActionExecutor e, em seguida, ajusta direto no repositório os campos que `criar()` não aceita
     * (marcados `sis` no Schema) e as datas reais (`criado_em`/`atualizado_em`, sempre "hoje" no criar()).
     * Registra uma entrada de auditoria única com os campos de sistema ajustados, para rastreabilidade.
     */
    private function criarComHistorico(string $entidade, array $dados, string $criadoEm, ?string $atualizadoEm, array $camposSistema = []): ?int
    {
        if ($this->simular) {
            $this->contar($entidade . '_criadas');
            return -1;
        }
        $r = $this->exec->criar($entidade, $dados, self::ORIGEM);
        if (!$r->ok) {
            $detalhe = $r->erros !== []
                ? implode('; ', array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($r->erros), $r->erros))
                : $r->mensagem;
            $this->avisos[] = "Falha ao criar {$entidade} (" . ($dados['titulo'] ?? $dados['nome_fantasia'] ?? $dados['descricao'] ?? '?') . "): {$detalhe}";
            return null;
        }
        $id = (int) $r->id;
        $ajustes = ['criado_em' => $criadoEm, 'atualizado_em' => $atualizadoEm ?? $criadoEm] + $camposSistema;
        Repositorios::para($entidade)->atualizar($id, $ajustes);
        if ($camposSistema !== []) {
            Audit::registrar(self::ORIGEM, $entidade, $id, 'importar_perfex', null, $camposSistema);
        }
        $this->contar($entidade . '_criadas');
        return $id;
    }

    private function dataOuNull(mixed $v): ?string
    {
        $v = (string) ($v ?? '');
        if ($v === '' || str_starts_with($v, '0000-00-00')) {
            return null;
        }
        return substr($v, 0, 10);
    }

    private function dataHoraOuNull(mixed $v): ?string
    {
        $v = (string) ($v ?? '');
        if ($v === '' || str_starts_with($v, '0000-00-00')) {
            return null;
        }
        return strlen($v) === 10 ? $v . ' 00:00:00' : $v;
    }

    private function textoOuNull(mixed $v): ?string
    {
        // Perfex (CodeIgniter antigo) grava alguns campos com entidades HTML escapadas na entrada
        // (ex.: "&amp;" em vez de "&"); decodifica pra não vazar "&amp;" literal pro Lárbous.
        $v = trim(html_entity_decode((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'));
        return $v === '' ? null : $v;
    }

    /** Telefone só dígitos, com um mínimo de sanidade (o schema de empresas/contatos aceita texto livre). */
    private function telefone(mixed $v): ?string
    {
        $v = $this->textoOuNull($v);
        return $v;
    }

    private function cnpjOuNull(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v !== '' && cnpj_valido($v) ? $v : null;
    }

    private function emailOuNull(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL) ? mb_strtolower($v) : null;
    }

    /** UF só é aceita como 2 letras; qualquer outra coisa (nome do estado por extenso, vazio) vira null. */
    private function ufOuNull(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return preg_match('/^[A-Za-z]{2}$/', $v) ? strtoupper($v) : null;
    }

    /** money: reais_para_centavos aceita int|float|string; o leitor do dump já devolve decimal como float. */
    private function reaisOuNull(mixed $v): float|string|null
    {
        return ($v === null || $v === '') ? null : $v;
    }
}
