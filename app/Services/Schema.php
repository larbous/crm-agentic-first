<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Whitelist de entidades e campos gravaveis (SPEC §4). É a fonte única para validação no ActionExecutor
 * e para gerar formulários e listas. Nada fora daqui é aceito em escrita.
 *
 * Campo: t = tipo, r = rótulo, g = grupo (aba do formulário), req = obrigatório, op = opções (enum),
 * fk = tabela referenciada, max = tamanho máximo, sis = controlado pelo servidor (não aceito em entrada),
 * l = largura no formulário (2 = linha inteira).
 * Tipos: texto textarea email tel url int money data datahora vencimento enum bool fk cnpj cpf cep uf cor extras
 * (extras = JSON de campos definidos pelo operador; ver CamposExtras).
 */
final class Schema
{
    public const ORIGENS_VALIDAS = '/^(humano|ia|sistema|agente:[a-z0-9-]+|formulario:\d+)$/';

    private static ?array $cache = null;

    /** @return array{tabela:string,singular:string,plural:string,genero:string,campos:array<string,array>}|null */
    public static function entidade(string $nome): ?array
    {
        return self::todas()[$nome] ?? null;
    }

    /** @return list<string> */
    public static function nomes(): array
    {
        return array_keys(self::todas());
    }

    /** Campos aceitos em entrada (exclui os controlados pelo servidor). */
    public static function gravaveis(string $entidade): array
    {
        return array_filter(self::entidade($entidade)['campos'] ?? [], static fn (array $c) => empty($c['sis']));
    }

    /** Mantém só os campos gravaveis de um array de entrada (ex.: $_POST). */
    public static function filtrar(string $entidade, array $entrada): array
    {
        return array_intersect_key($entrada, self::gravaveis($entidade));
    }

    private static function todas(): array
    {
        return self::$cache ??= [
            'empresas'      => self::empresas(),
            'contatos'      => self::contatos(),
            'negocios'      => self::negocios(),
            'atividades'    => self::atividades(),
            'tarefas'       => self::tarefas(),
            'anexos'        => self::anexos(),
            'tags'          => self::tags(),
            'origens'       => self::nomeSimples('origens', 'Origem', 'Origens', 'f'),
            'motivos_perda' => self::nomeSimples('motivos_perda', 'Motivo de perda', 'Motivos de perda', 'm'),
            'pipelines'     => self::pipelines(),
            'etapas'        => self::etapas(),
            'servicos'      => self::servicos(),
            'modelos_documento' => self::modelos(),
            'contrato_tipos' => self::nomeSimples('contrato_tipos', 'Tipo de contrato', 'Tipos de contrato', 'm'),
            'propostas'     => self::propostas(),
            'proposta_itens' => self::propostaItens(),
            'contratos'     => self::contratos(),
            'campos_extras_def' => self::camposExtrasDef(),
            'metas'         => self::metas(),
            'areas'         => self::nomeSimples('areas', 'Área', 'Áreas', 'f'),
            'chamados'      => self::chamados(),
            'cobrancas'     => self::cobrancas(),
            'custos'        => self::custos(),
        ];
    }

    private static function f(string $t, string $r, string $g, array $x = []): array
    {
        return ['t' => $t, 'r' => $r, 'g' => $g] + $x;
    }

    /** Chave de um enum a partir da chave ou do rótulo, sem acento/caixa ("Média" → "media"). Null se não existir. */
    public static function chaveDoEnum(string $opcoes, string $valor): ?string
    {
        $alvo = normalizar_busca(trim($valor));
        foreach (self::opcoes($opcoes) as $chave => $rotulo) {
            if (normalizar_busca((string) $chave) === $alvo || normalizar_busca($rotulo) === $alvo) {
                return (string) $chave;
            }
        }
        return null;
    }

    // ---- Opções de enums (valor => rótulo)

    public static function opcoes(string $nome): array
    {
        return [
            'porte'           => ['mei' => 'MEI', 'me' => 'ME', 'epp' => 'EPP', 'medio' => 'Médio', 'grande' => 'Grande'],
            'status_empresa'  => ['lead' => 'Lead', 'prospect' => 'Prospect', 'cliente' => 'Cliente', 'ex_cliente' => 'Ex-cliente', 'inativo' => 'Inativo'],
            'classificacao'   => ['A' => 'A', 'B' => 'B', 'C' => 'C'],
            'papel_decisao'   => ['decisor' => 'Decisor', 'influenciador' => 'Influenciador', 'financeiro' => 'Financeiro', 'tecnico' => 'Técnico', 'usuario' => 'Usuário'],
            'canal'           => ['whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'telefone' => 'Telefone'],
            'base_legal'      => ['consentimento' => 'Consentimento', 'contrato' => 'Contrato', 'legitimo_interesse' => 'Legítimo interesse'],
            'status_contato'  => ['ativo' => 'Ativo', 'inativo' => 'Inativo'],
            'status_negocio'  => ['aberto' => 'Aberto', 'ganho' => 'Ganho', 'perdido' => 'Perdido', 'pausado' => 'Pausado'],
            'tipo_receita'    => ['unico' => 'Único', 'mensal' => 'Mensal', 'anual' => 'Anual'],
            'temperatura'     => ['frio' => 'Frio', 'morno' => 'Morno', 'quente' => 'Quente', 'fervendo' => 'Fervendo'],
            'champ'           => ['confirmado' => 'Confirmado', 'parcial' => 'Parcial', 'nao_identificado' => 'Não identificado'],
            'prioridade_neg'  => ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'],
            'tipo_atividade'  => ['nota' => 'Nota', 'ligacao' => 'Ligação', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'instagram' => 'Instagram', 'reuniao' => 'Reunião', 'visita' => 'Visita', 'proposta' => 'Proposta', 'contrato' => 'Contrato', 'sistema' => 'Sistema'],
            'direcao'         => ['entrada' => 'Entrada', 'saida' => 'Saída'],
            'tipo_tarefa'     => ['ligar' => 'Ligar', 'enviar' => 'Enviar', 'reuniao' => 'Reunião', 'followup' => 'Follow-up', 'interno' => 'Interno', 'outro' => 'Outro'],
            'status_chamado'  => ['aberto' => 'Aberto', 'andamento' => 'Em andamento', 'aguardando' => 'Aguardando', 'concluido' => 'Concluído', 'cancelado' => 'Cancelado'],
            'prioridade_tar'  => ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'],
            'status_tarefa'   => ['pendente' => 'Pendente', 'andamento' => 'Em andamento', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'],
            'recorrencia'     => ['nenhuma' => 'Nenhuma', 'diaria' => 'Diária', 'semanal' => 'Semanal', 'mensal' => 'Mensal', 'anual' => 'Anual'],
            'tipo_etapa'      => ['aberta' => 'Aberta', 'ganho' => 'Ganho', 'perdido' => 'Perdido'],
            'categoria_servico' => ['site' => 'Site', 'ecommerce' => 'E-commerce', 'consultoria' => 'Consultoria', 'manutencao' => 'Manutenção', 'hospedagem' => 'Hospedagem', 'trafego' => 'Tráfego pago', 'outro' => 'Outro'],
            'unidade_servico'  => ['projeto' => 'Projeto', 'hora' => 'Hora', 'mes' => 'Mês', 'ano' => 'Ano'],
            'tipo_modelo'      => ['contrato' => 'Contrato', 'proposta' => 'Proposta', 'email' => 'E-mail', 'whatsapp' => 'WhatsApp'],
            'status_proposta'  => ['rascunho' => 'Rascunho', 'enviada' => 'Enviada', 'visualizada' => 'Visualizada', 'aceita' => 'Aceita', 'recusada' => 'Recusada', 'expirada' => 'Expirada'],
            'desconto_tipo'    => ['valor' => 'Valor (R$)', 'percentual' => 'Percentual (%)'],
            'status_contrato'  => ['rascunho' => 'Rascunho', 'enviado' => 'Enviado', 'assinado' => 'Assinado', 'ativo' => 'Ativo', 'vencido' => 'Vencido', 'cancelado' => 'Cancelado', 'renovado' => 'Renovado'],
            'recorrencia_contrato' => ['unica' => 'Única', 'mensal' => 'Mensal', 'anual' => 'Anual'],
            'indice_reajuste'  => ['nenhum' => 'Nenhum', 'ipca' => 'IPCA', 'igpm' => 'IGP-M', 'fixo' => 'Fixo'],
            'entidade_extra'  => ['empresas' => 'Empresa', 'contatos' => 'Contato', 'negocios' => 'Negócio'],
            'tipo_extra'      => ['texto' => 'Texto', 'numero' => 'Número', 'data' => 'Data', 'select' => 'Lista de opções', 'checkbox' => 'Sim/Não', 'url' => 'Link (URL)', 'textarea' => 'Texto longo'],
            'tipo_meta'       => ['faturamento' => 'Faturamento', 'mrr' => 'MRR', 'novos_clientes' => 'Novos clientes', 'propostas_enviadas' => 'Propostas enviadas', 'negocios_ganhos' => 'Negócios ganhos'],
            'periodo_meta'    => ['mensal' => 'Mensal', 'trimestral' => 'Trimestral', 'anual' => 'Anual'],
            'entidade_anexo'  => ['empresas' => 'Empresa', 'contatos' => 'Contato', 'negocios' => 'Negócio', 'propostas' => 'Proposta', 'contratos' => 'Contrato', 'chamados' => 'Chamado'],
            'tipo_cobranca'   => ['avulsa' => 'Avulsa', 'recorrente' => 'Recorrente'],
            'ciclo_cobranca'  => ['mensal' => 'Mensal', 'anual' => 'Anual'],
            'forma_pagamento_asaas' => ['boleto' => 'Boleto', 'pix' => 'Pix', 'cartao' => 'Cartão de crédito', 'indefinido' => 'Cliente escolhe (link de pagamento)'],
            'status_cobranca' => ['pendente' => 'Pendente', 'pago' => 'Pago', 'vencido' => 'Vencido', 'cancelado' => 'Cancelado'],
        ][$nome];
    }

    // ---- Definições

    private static function empresas(): array
    {
        $f = self::f(...);
        return ['tabela' => 'empresas', 'singular' => 'Empresa', 'plural' => 'Empresas', 'genero' => 'f', 'campos' => [
            // Dados (identificação + comercial)
            'nome_fantasia'   => $f('texto', 'Nome fantasia', 'dados', ['req' => true, 'max' => 160]),
            'razao_social'    => $f('texto', 'Razão social', 'dados', ['max' => 200]),
            'cnpj'            => $f('cnpj', 'CNPJ', 'dados'),
            'inscricao_estadual'  => $f('texto', 'Inscrição estadual', 'dados', ['max' => 40]),
            'inscricao_municipal' => $f('texto', 'Inscrição municipal', 'dados', ['max' => 40]),
            'porte'           => $f('enum', 'Porte', 'dados', ['op' => 'porte']),
            'segmento'        => $f('texto', 'Segmento', 'dados', ['max' => 120]),
            'cnae_principal'  => $f('texto', 'CNAE principal', 'dados', ['max' => 120]),
            'data_fundacao'   => $f('data', 'Data de fundação', 'dados'),
            'status'          => $f('enum', 'Status', 'dados', ['op' => 'status_empresa', 'req' => true]),
            'origem_id'       => $f('fk', 'Origem', 'dados', ['fk' => 'origens']),
            'indicado_por'    => $f('texto', 'Indicado por', 'dados', ['max' => 160]),
            'classificacao'   => $f('enum', 'Classificação', 'dados', ['op' => 'classificacao']),
            'faixa_faturamento'  => $f('texto', 'Faixa de faturamento', 'dados', ['max' => 80]),
            'faixa_funcionarios' => $f('texto', 'Faixa de funcionários', 'dados', ['max' => 80]),
            'ticket_potencial'   => $f('money', 'Ticket potencial (R$)', 'dados'),
            'cliente_desde'   => $f('data', 'Cliente desde', 'dados'),
            // Contato
            'email_geral'     => $f('email', 'E-mail geral', 'contato'),
            'telefone'        => $f('tel', 'Telefone', 'contato'),
            'whatsapp'        => $f('tel', 'WhatsApp', 'contato'),
            'site'            => $f('url', 'Site', 'contato'),
            'instagram'       => $f('texto', 'Instagram', 'contato', ['max' => 160]),
            'facebook'        => $f('texto', 'Facebook', 'contato', ['max' => 160]),
            'linkedin'        => $f('texto', 'LinkedIn', 'contato', ['max' => 160]),
            'tiktok'          => $f('texto', 'TikTok', 'contato', ['max' => 160]),
            'youtube'         => $f('texto', 'YouTube', 'contato', ['max' => 160]),
            'google_meu_negocio' => $f('texto', 'Google Meu Negócio', 'contato', ['max' => 255]),
            // Endereço
            'cep'             => $f('cep', 'CEP', 'endereco'),
            'logradouro'      => $f('texto', 'Logradouro', 'endereco', ['l' => 2, 'max' => 200]),
            'numero'          => $f('texto', 'Número', 'endereco', ['max' => 20]),
            'complemento'     => $f('texto', 'Complemento', 'endereco', ['max' => 120]),
            'bairro'          => $f('texto', 'Bairro', 'endereco', ['max' => 120]),
            'cidade'          => $f('texto', 'Cidade', 'endereco', ['max' => 120]),
            'uf'              => $f('uf', 'UF', 'endereco'),
            'pais'            => $f('texto', 'País', 'endereco', ['max' => 80]),
            // Presença digital
            'dominio'         => $f('texto', 'Domínio', 'digital', ['max' => 190]),
            'registrador_dominio' => $f('texto', 'Registrador do domínio', 'digital', ['max' => 120]),
            'vencimento_dominio'  => $f('data', 'Vencimento do domínio', 'digital'),
            'hospedagem_atual'    => $f('texto', 'Hospedagem atual', 'digital', ['max' => 120]),
            'plataforma_site'     => $f('texto', 'Plataforma do site', 'digital', ['max' => 120]),
            'tem_ecommerce'       => $f('bool', 'Tem e-commerce', 'digital'),
            'plataforma_ecommerce' => $f('texto', 'Plataforma do e-commerce', 'digital', ['max' => 120]),
            'erp'                 => $f('texto', 'ERP', 'digital', ['max' => 120]),
            'ferramenta_email_marketing' => $f('texto', 'Ferramenta de e-mail marketing', 'digital', ['max' => 120]),
            'usa_trafego_pago'    => $f('bool', 'Usa tráfego pago', 'digital'),
            'observacoes_digitais' => $f('textarea', 'Observações digitais', 'digital', ['l' => 2]),
            // Aquisição
            'utm_source'      => $f('texto', 'utm_source', 'aquisicao', ['max' => 190]),
            'utm_medium'      => $f('texto', 'utm_medium', 'aquisicao', ['max' => 190]),
            'utm_campaign'    => $f('texto', 'utm_campaign', 'aquisicao', ['max' => 190]),
            'utm_term'        => $f('texto', 'utm_term', 'aquisicao', ['max' => 190]),
            'utm_content'     => $f('texto', 'utm_content', 'aquisicao', ['max' => 190]),
            // Controle
            'notas'           => $f('textarea', 'Notas', 'extras', ['l' => 2]),
            'campos_extras'   => $f('extras', 'Campos extras', 'extras', ['l' => 2]),
            'asaas_customer_id' => $f('texto', 'Cliente no Asaas', 'extras', ['sis' => true]),
        ]];
    }

    private static function contatos(): array
    {
        $f = self::f(...);
        return ['tabela' => 'contatos', 'singular' => 'Contato', 'plural' => 'Contatos', 'genero' => 'm', 'campos' => [
            'nome'            => $f('texto', 'Nome', 'pessoal', ['req' => true, 'max' => 120]),
            'sobrenome'       => $f('texto', 'Sobrenome', 'pessoal', ['max' => 120]),
            'apelido'         => $f('texto', 'Apelido', 'pessoal', ['max' => 80]),
            'cpf'             => $f('cpf', 'CPF', 'pessoal'),
            'data_nascimento' => $f('data', 'Data de nascimento', 'pessoal'),
            'empresa_id'      => $f('fk', 'Empresa', 'profissional', ['fk' => 'empresas']),
            'cargo'           => $f('texto', 'Cargo', 'profissional', ['max' => 120]),
            'departamento'    => $f('texto', 'Departamento', 'profissional', ['max' => 120]),
            'papel_decisao'   => $f('enum', 'Papel na decisão', 'profissional', ['op' => 'papel_decisao']),
            'nivel_hierarquico' => $f('texto', 'Nível hierárquico', 'profissional', ['max' => 80]),
            'email'           => $f('email', 'E-mail', 'canais'),
            'email_secundario' => $f('email', 'E-mail secundário', 'canais'),
            'telefone'        => $f('tel', 'Telefone', 'canais'),
            'whatsapp'        => $f('tel', 'WhatsApp', 'canais'),
            'linkedin'        => $f('texto', 'LinkedIn', 'canais', ['max' => 160]),
            'instagram'       => $f('texto', 'Instagram', 'canais', ['max' => 160]),
            'canal_preferido' => $f('enum', 'Canal preferido', 'canais', ['op' => 'canal']),
            'melhor_horario'  => $f('texto', 'Melhor horário', 'canais', ['max' => 80]),
            'opt_in_marketing' => $f('bool', 'Aceita marketing', 'lgpd'),
            'base_legal'      => $f('enum', 'Base legal', 'lgpd', ['op' => 'base_legal']),
            'data_consentimento' => $f('data', 'Data do consentimento', 'lgpd'),
            'origem_consentimento' => $f('texto', 'Origem do consentimento', 'lgpd', ['max' => 160]),
            'status'          => $f('enum', 'Status', 'relacionamento', ['op' => 'status_contato', 'req' => true]),
            'origem_id'       => $f('fk', 'Origem', 'relacionamento', ['fk' => 'origens']),
            'ultimo_contato_em' => $f('datahora', 'Último contato', 'relacionamento', ['sis' => true]),
            'proximo_contato_em' => $f('data', 'Próximo contato', 'relacionamento'),
            'nivel_relacionamento' => $f('int', 'Nível de relacionamento (1–5)', 'relacionamento', ['min' => 1, 'max' => 5]),
            'interesses'      => $f('textarea', 'Interesses', 'relacionamento', ['l' => 2]),
            'notas'           => $f('textarea', 'Notas', 'extras', ['l' => 2]),
            'campos_extras'   => $f('extras', 'Campos extras', 'extras', ['l' => 2]),
        ]];
    }

    private static function negocios(): array
    {
        $f = self::f(...);
        return ['tabela' => 'negocios', 'singular' => 'Negócio', 'plural' => 'Negócios', 'genero' => 'm', 'campos' => [
            'titulo'          => $f('texto', 'Título', 'basico', ['req' => true, 'max' => 200, 'l' => 2]),
            'codigo'          => $f('texto', 'Código', 'basico', ['sis' => true]),
            'empresa_id'      => $f('fk', 'Empresa', 'basico', ['fk' => 'empresas']),
            'contato_principal_id' => $f('fk', 'Contato principal', 'basico', ['fk' => 'contatos']),
            'decisor_id'      => $f('fk', 'Decisor', 'basico', ['fk' => 'contatos']),
            'pipeline_id'     => $f('fk', 'Pipeline', 'basico', ['fk' => 'pipelines', 'sis' => true]),
            'etapa_id'        => $f('fk', 'Etapa', 'basico', ['fk' => 'etapas']),
            'status'          => $f('enum', 'Status', 'basico', ['op' => 'status_negocio']),
            'origem_id'       => $f('fk', 'Origem', 'basico', ['fk' => 'origens']),
            'valor_estimado'  => $f('money', 'Valor estimado (R$)', 'valores'),
            'valor_fechado'   => $f('money', 'Valor fechado (R$)', 'valores'),
            'tipo_receita'    => $f('enum', 'Tipo de receita', 'valores', ['op' => 'tipo_receita']),
            'valor_recorrente' => $f('money', 'Valor recorrente (R$)', 'valores'),
            'probabilidade'   => $f('int', 'Probabilidade (%)', 'valores', ['min' => 0, 'max' => 100]),
            'previsao_fechamento' => $f('data', 'Previsão de fechamento', 'valores'),
            'data_fechamento' => $f('data', 'Data de fechamento', 'valores', ['sis' => true]),
            'entrou_etapa_em' => $f('datahora', 'Entrou na etapa em', 'valores', ['sis' => true]),
            'temperatura'     => $f('enum', 'Temperatura', 'qualificacao', ['op' => 'temperatura']),
            'prioridade'      => $f('enum', 'Prioridade', 'qualificacao', ['op' => 'prioridade_neg']),
            'dor_principal'   => $f('textarea', 'Dor principal', 'qualificacao', ['l' => 2]),
            'objetivo_cliente' => $f('textarea', 'Objetivo do cliente', 'qualificacao', ['l' => 2]),
            'orcamento_cliente' => $f('texto', 'Orçamento do cliente', 'qualificacao', ['max' => 120]),
            'prazo_desejado'  => $f('texto', 'Prazo desejado', 'qualificacao', ['max' => 120]),
            'criterio_decisao' => $f('texto', 'Critério de decisão', 'qualificacao', ['max' => 255]),
            'concorrentes'    => $f('texto', 'Concorrentes', 'qualificacao', ['max' => 255]),
            'champ_desafios'  => $f('enum', 'CHAMP · Desafios (dor confirmada)', 'qualificacao', ['op' => 'champ']),
            'champ_autoridade' => $f('enum', 'CHAMP · Autoridade (decisor)', 'qualificacao', ['op' => 'champ']),
            'champ_dinheiro'  => $f('enum', 'CHAMP · Dinheiro (orçamento)', 'qualificacao', ['op' => 'champ']),
            'champ_prioridade' => $f('enum', 'CHAMP · Prioridade (urgência)', 'qualificacao', ['op' => 'champ']),
            'champ_resumo'    => $f('textarea', 'CHAMP · Justificativa', 'qualificacao', ['l' => 2]),
            'champ_pontos'    => $f('int', 'CHAMP · Pontuação', 'qualificacao', ['sis' => true]),
            'champ_avaliado_em' => $f('datahora', 'CHAMP · Avaliado em', 'qualificacao', ['sis' => true]),
            'proximo_passo'   => $f('texto', 'Próximo passo', 'andamento', ['max' => 255, 'l' => 2]),
            'proximo_passo_em' => $f('data', 'Próximo passo em', 'andamento'),
            'motivo_perda_id' => $f('fk', 'Motivo da perda', 'andamento', ['fk' => 'motivos_perda']),
            'detalhe_perda'   => $f('textarea', 'Detalhe da perda', 'andamento', ['l' => 2]),
            'utm_source'      => $f('texto', 'utm_source', 'aquisicao', ['max' => 190]),
            'utm_medium'      => $f('texto', 'utm_medium', 'aquisicao', ['max' => 190]),
            'utm_campaign'    => $f('texto', 'utm_campaign', 'aquisicao', ['max' => 190]),
            'utm_term'        => $f('texto', 'utm_term', 'aquisicao', ['max' => 190]),
            'utm_content'     => $f('texto', 'utm_content', 'aquisicao', ['max' => 190]),
            'notas'           => $f('textarea', 'Notas', 'extras', ['l' => 2]),
            'campos_extras'   => $f('extras', 'Campos extras', 'extras', ['l' => 2]),
        ]];
    }

    private static function atividades(): array
    {
        $f = self::f(...);
        return ['tabela' => 'atividades', 'singular' => 'Atividade', 'plural' => 'Atividades', 'genero' => 'f', 'campos' => [
            'tipo'        => $f('enum', 'Tipo', 'dados', ['op' => 'tipo_atividade', 'req' => true]),
            'assunto'     => $f('texto', 'Assunto', 'dados', ['max' => 200]),
            'descricao'   => $f('textarea', 'Descrição', 'dados', ['l' => 2]),
            'data_hora'   => $f('datahora', 'Data e hora', 'dados'),
            'duracao_min' => $f('int', 'Duração (min)', 'dados', ['min' => 0, 'max' => 100000]),
            'direcao'     => $f('enum', 'Direção', 'dados', ['op' => 'direcao']),
            'resultado'   => $f('texto', 'Resultado', 'dados', ['max' => 200]),
            'empresa_id'  => $f('fk', 'Empresa', 'vinculos', ['fk' => 'empresas']),
            'contato_id'  => $f('fk', 'Contato', 'vinculos', ['fk' => 'contatos']),
            'negocio_id'  => $f('fk', 'Negócio', 'vinculos', ['fk' => 'negocios']),
        ]];
    }

    private static function tarefas(): array
    {
        $f = self::f(...);
        return ['tabela' => 'tarefas', 'singular' => 'Tarefa', 'plural' => 'Tarefas', 'genero' => 'f', 'campos' => [
            'titulo'      => $f('texto', 'Título', 'dados', ['req' => true, 'max' => 200, 'l' => 2]),
            'descricao'   => $f('textarea', 'Descrição', 'dados', ['l' => 2]),
            'tipo'        => $f('enum', 'Tipo', 'dados', ['op' => 'tipo_tarefa', 'req' => true]),
            'prioridade'  => $f('enum', 'Prioridade', 'dados', ['op' => 'prioridade_tar', 'req' => true]),
            'status'      => $f('enum', 'Status', 'dados', ['op' => 'status_tarefa', 'req' => true]),
            'vencimento'  => $f('vencimento', 'Vencimento', 'dados'),
            'lembrete_em' => $f('datahora', 'Lembrete em', 'dados'),
            'recorrencia' => $f('enum', 'Recorrência', 'dados', ['op' => 'recorrencia', 'req' => true]),
            'concluida_em' => $f('datahora', 'Concluída em', 'dados', ['sis' => true]),
            'empresa_id'  => $f('fk', 'Empresa', 'vinculos', ['fk' => 'empresas']),
            'contato_id'  => $f('fk', 'Contato', 'vinculos', ['fk' => 'contatos']),
            'negocio_id'  => $f('fk', 'Negócio', 'vinculos', ['fk' => 'negocios']),
            'contrato_id' => $f('fk', 'Contrato', 'vinculos', ['fk' => 'contratos']),
        ]];
    }

    /** Chamados (Fase 13): demanda de execução por área, com checklist (JSON) e código CH-AAAA-NNNN. */
    private static function chamados(): array
    {
        $f = self::f(...);
        return ['tabela' => 'chamados', 'singular' => 'Chamado', 'plural' => 'Chamados', 'genero' => 'm', 'campos' => [
            'codigo'      => $f('texto', 'Código', 'dados', ['sis' => true]),
            'titulo'      => $f('texto', 'Título', 'dados', ['req' => true, 'max' => 200, 'l' => 2]),
            'descricao'   => $f('textarea', 'Descrição', 'dados', ['l' => 2]),
            'area_id'     => $f('fk', 'Área', 'dados', ['fk' => 'areas', 'req' => true]),
            'prioridade'  => $f('enum', 'Prioridade', 'dados', ['op' => 'prioridade_tar', 'req' => true]),
            'status'      => $f('enum', 'Status', 'dados', ['op' => 'status_chamado', 'req' => true]),
            'vencimento'  => $f('vencimento', 'Prazo de entrega', 'dados'),
            'resolucao'   => $f('textarea', 'Resolução (o que foi entregue)', 'dados', ['l' => 2]),
            'concluido_em' => $f('datahora', 'Concluído em', 'dados', ['sis' => true]),
            'empresa_id'  => $f('fk', 'Empresa', 'vinculos', ['fk' => 'empresas']),
            'contato_id'  => $f('fk', 'Contato', 'vinculos', ['fk' => 'contatos']),
            'negocio_id'  => $f('fk', 'Negócio', 'vinculos', ['fk' => 'negocios']),
            'contrato_id' => $f('fk', 'Contrato', 'vinculos', ['fk' => 'contratos']),
            'checklist'   => $f('checklist', 'Checklist', 'checklist'),
        ]];
    }

    /** Cobrança (Fase 17): ligada ao Asaas (avulsa ou recorrente). Status e dados do Asaas são controlados pelo servidor/webhook. */
    private static function cobrancas(): array
    {
        $f = self::f(...);
        return ['tabela' => 'cobrancas', 'singular' => 'Cobrança', 'plural' => 'Cobranças', 'genero' => 'f', 'campos' => [
            'empresa_id'      => $f('fk', 'Empresa', 'dados', ['fk' => 'empresas', 'req' => true]),
            'negocio_id'      => $f('fk', 'Negócio', 'dados', ['fk' => 'negocios']),
            'contrato_id'     => $f('fk', 'Contrato', 'dados', ['fk' => 'contratos']),
            'tipo'            => $f('enum', 'Tipo', 'dados', ['op' => 'tipo_cobranca', 'req' => true]),
            'descricao'       => $f('texto', 'Descrição', 'dados', ['req' => true, 'max' => 200, 'l' => 2]),
            'valor'           => $f('money', 'Valor (R$)', 'dados', ['req' => true]),
            'ciclo'           => $f('enum', 'Ciclo (recorrente)', 'dados', ['op' => 'ciclo_cobranca']),
            'forma_pagamento' => $f('enum', 'Forma de pagamento', 'dados', ['op' => 'forma_pagamento_asaas', 'req' => true]),
            'vencimento'      => $f('data', 'Vencimento', 'dados', ['req' => true]),
            'status'          => $f('enum', 'Status', 'dados', ['op' => 'status_cobranca', 'sis' => true]),
            'notas'           => $f('textarea', 'Notas', 'dados', ['l' => 2]),
            'asaas_customer_id' => $f('texto', 'Cliente no Asaas', 'asaas', ['sis' => true]),
            'asaas_id'        => $f('texto', 'Cobrança/assinatura no Asaas', 'asaas', ['sis' => true]),
            'asaas_tipo'      => $f('texto', 'Tipo no Asaas', 'asaas', ['sis' => true]),
            'url_fatura'      => $f('url', 'Link da fatura', 'asaas', ['sis' => true]),
            'data_pagamento'  => $f('data', 'Data do pagamento', 'asaas', ['sis' => true]),
            'nfe_status'      => $f('texto', 'Situação da NF-e', 'asaas', ['sis' => true, 'max' => 40]),
            'nfe_url'         => $f('url', 'Link da NF-e', 'asaas', ['sis' => true]),
        ]];
    }

    /** Custo (Fase 17): lançamento manual, vinculado a empresa e/ou negócio, usado no cálculo do DRE por cliente. */
    private static function custos(): array
    {
        $f = self::f(...);
        return ['tabela' => 'custos', 'singular' => 'Custo', 'plural' => 'Custos', 'genero' => 'm', 'campos' => [
            'empresa_id' => $f('fk', 'Empresa', 'dados', ['fk' => 'empresas']),
            'negocio_id' => $f('fk', 'Negócio', 'dados', ['fk' => 'negocios']),
            'descricao'  => $f('texto', 'Descrição', 'dados', ['req' => true, 'max' => 200, 'l' => 2]),
            'valor'      => $f('money', 'Valor (R$)', 'dados', ['req' => true]),
            'data'       => $f('data', 'Data', 'dados', ['req' => true]),
            'categoria'  => $f('texto', 'Categoria', 'dados', ['max' => 60]),
            'recorrente' => $f('bool', 'Recorrente (todo mês)', 'dados'),
        ]];
    }

    private static function anexos(): array
    {
        $f = self::f(...);
        return ['tabela' => 'anexos', 'singular' => 'Anexo', 'plural' => 'Anexos', 'genero' => 'm', 'campos' => [
            'entidade'      => $f('enum', 'Entidade', 'dados', ['op' => 'entidade_anexo', 'req' => true]),
            'registro_id'   => $f('int', 'Registro', 'dados', ['req' => true, 'min' => 1]),
            'nome_original' => $f('texto', 'Nome', 'dados', ['req' => true, 'max' => 255]),
            'caminho'       => $f('texto', 'Caminho', 'dados', ['req' => true, 'max' => 120]),
            'mime'          => $f('texto', 'Tipo', 'dados', ['req' => true, 'max' => 120]),
            'tamanho'       => $f('int', 'Tamanho', 'dados', ['req' => true, 'min' => 0]),
        ]];
    }

    private static function tags(): array
    {
        $f = self::f(...);
        return ['tabela' => 'tags', 'singular' => 'Tag', 'plural' => 'Tags', 'genero' => 'f', 'campos' => [
            'nome' => $f('texto', 'Nome', 'dados', ['req' => true, 'max' => 60]),
            'cor'  => $f('cor', 'Cor', 'dados'),
        ]];
    }

    /** Definição dos campos extras (SPEC §4.12). Chave e entidade não mudam depois de criadas. */
    private static function camposExtrasDef(): array
    {
        $f = self::f(...);
        return ['tabela' => 'campos_extras_def', 'singular' => 'Campo extra', 'plural' => 'Campos extras', 'genero' => 'm', 'campos' => [
            'entidade'    => $f('enum', 'Entidade', 'dados', ['op' => 'entidade_extra', 'req' => true]),
            'chave'       => $f('texto', 'Chave', 'dados', ['req' => true, 'max' => 40]),
            'rotulo'      => $f('texto', 'Rótulo', 'dados', ['req' => true, 'max' => 80]),
            'tipo'        => $f('enum', 'Tipo', 'dados', ['op' => 'tipo_extra', 'req' => true]),
            'opcoes'      => $f('textarea', 'Opções (uma por linha)', 'dados', ['max' => 2000]),
            'obrigatorio' => $f('bool', 'Obrigatório', 'dados'),
            'ordem'       => $f('int', 'Ordem', 'dados', ['min' => 0, 'max' => 1000]),
            'ativo'       => $f('bool', 'Ativo', 'dados'),
        ]];
    }

    /** Metas (SPEC §4.11). valor_alvo: centavos (faturamento, mrr) ou quantidade; data_fim é derivada (ver Metas). */
    private static function metas(): array
    {
        $f = self::f(...);
        return ['tabela' => 'metas', 'singular' => 'Meta', 'plural' => 'Metas', 'genero' => 'f', 'campos' => [
            'tipo'        => $f('enum', 'Tipo', 'dados', ['op' => 'tipo_meta', 'req' => true]),
            'periodo'     => $f('enum', 'Período', 'dados', ['op' => 'periodo_meta', 'req' => true]),
            'valor_alvo'  => $f('int', 'Valor alvo', 'dados', ['req' => true, 'min' => 1, 'max' => 100000000000]),
            'data_inicio' => $f('data', 'Início', 'dados', ['req' => true]),
            'data_fim'    => $f('data', 'Fim', 'dados', ['sis' => true]),
        ]];
    }

    private static function nomeSimples(string $tabela, string $singular, string $plural, string $genero): array
    {
        return ['tabela' => $tabela, 'singular' => $singular, 'plural' => $plural, 'genero' => $genero, 'campos' => [
            'nome' => self::f('texto', 'Nome', 'dados', ['req' => true, 'max' => 80]),
        ]];
    }

    private static function pipelines(): array
    {
        return ['tabela' => 'pipelines', 'singular' => 'Pipeline', 'plural' => 'Pipelines', 'genero' => 'm', 'campos' => [
            'nome'   => self::f('texto', 'Nome', 'dados', ['req' => true, 'max' => 80]),
            'padrao' => self::f('bool', 'Padrão', 'dados', ['sis' => true]),
        ]];
    }

    private static function etapas(): array
    {
        $f = self::f(...);
        return ['tabela' => 'etapas', 'singular' => 'Etapa', 'plural' => 'Etapas', 'genero' => 'f', 'campos' => [
            'pipeline_id' => $f('fk', 'Pipeline', 'dados', ['fk' => 'pipelines', 'req' => true]),
            'nome'        => $f('texto', 'Nome', 'dados', ['req' => true, 'max' => 80]),
            'ordem'       => $f('int', 'Ordem', 'dados', ['min' => 0, 'max' => 1000]),
            'probabilidade_padrao' => $f('int', 'Probabilidade padrão (%)', 'dados', ['min' => 0, 'max' => 100, 'req' => true]),
            'cor'         => $f('cor', 'Cor', 'dados'),
            'tipo'        => $f('enum', 'Tipo', 'dados', ['op' => 'tipo_etapa', 'req' => true]),
        ]];
    }

    private static function servicos(): array
    {
        $f = self::f(...);
        return ['tabela' => 'servicos', 'singular' => 'Serviço', 'plural' => 'Serviços', 'genero' => 'm', 'campos' => [
            'nome'              => $f('texto', 'Nome', 'dados', ['req' => true, 'max' => 160]),
            'categoria'         => $f('enum', 'Categoria', 'dados', ['op' => 'categoria_servico', 'req' => true]),
            'unidade'           => $f('enum', 'Unidade de cobrança', 'dados', ['op' => 'unidade_servico', 'req' => true]),
            'preco_base'        => $f('money', 'Preço base (R$)', 'dados'),
            'preco_minimo'      => $f('money', 'Preço mínimo (R$)', 'dados'),
            'prazo_padrao_dias' => $f('int', 'Prazo padrão (dias)', 'dados', ['min' => 0, 'max' => 3650]),
            'recorrente'        => $f('bool', 'Cobrança recorrente', 'dados'),
            'ativo'             => $f('bool', 'Ativo', 'dados'),
            'descricao'         => $f('textarea', 'Descrição', 'dados', ['l' => 2]),
            'entregaveis'       => $f('textarea', 'Entregáveis', 'dados', ['l' => 2]),
        ]];
    }

    private static function modelos(): array
    {
        $f = self::f(...);
        return ['tabela' => 'modelos_documento', 'singular' => 'Modelo', 'plural' => 'Modelos', 'genero' => 'm', 'campos' => [
            'tipo'     => $f('enum', 'Tipo', 'dados', ['op' => 'tipo_modelo', 'req' => true]),
            'nome'     => $f('texto', 'Nome', 'dados', ['req' => true, 'max' => 160]),
            'assunto'  => $f('texto', 'Assunto (e-mail)', 'dados', ['max' => 200]),
            'ativo'    => $f('bool', 'Ativo', 'dados'),
            'conteudo' => $f('textarea', 'Conteúdo', 'dados', ['l' => 2, 'max' => 200000]),
        ]];
    }

    private static function propostas(): array
    {
        $f = self::f(...);
        return ['tabela' => 'propostas', 'singular' => 'Proposta', 'plural' => 'Propostas', 'genero' => 'f', 'campos' => [
            'titulo'        => $f('texto', 'Título', 'basico', ['req' => true, 'max' => 200, 'l' => 2]),
            'negocio_id'    => $f('fk', 'Negócio', 'basico', ['fk' => 'negocios']),
            'empresa_id'    => $f('fk', 'Empresa', 'basico', ['fk' => 'empresas']),
            'contato_id'    => $f('fk', 'Contato', 'basico', ['fk' => 'contatos']),
            'modelo_id'     => $f('fk', 'Modelo', 'basico', ['fk' => 'modelos_documento']),
            'data_emissao'  => $f('data', 'Data de emissão', 'basico'),
            'validade'      => $f('data', 'Validade', 'basico'),
            'numero'        => $f('texto', 'Número', 'basico', ['sis' => true]),
            'versao'        => $f('int', 'Versão', 'basico', ['sis' => true]),
            'status'        => $f('enum', 'Status', 'basico', ['op' => 'status_proposta', 'sis' => true]),
            'enviada_em'    => $f('datahora', 'Enviada em', 'basico', ['sis' => true]),
            'visualizada_em' => $f('datahora', 'Visualizada em', 'basico', ['sis' => true]),
            'respondida_em' => $f('datahora', 'Respondida em', 'basico', ['sis' => true]),
            'subtotal'      => $f('money', 'Subtotal', 'itens', ['sis' => true]),
            'total'         => $f('money', 'Total', 'itens', ['sis' => true]),
            'total_recorrente' => $f('money', 'Total recorrente', 'itens', ['sis' => true]),
            'desconto_tipo' => $f('enum', 'Tipo de desconto', 'condicoes', ['op' => 'desconto_tipo', 'req' => true]),
            'desconto_valor' => $f('money', 'Desconto', 'condicoes'),
            'forma_pagamento'     => $f('texto', 'Forma de pagamento', 'condicoes', ['max' => 160]),
            'parcelas'            => $f('int', 'Parcelas', 'condicoes', ['min' => 1, 'max' => 120]),
            'entrada_percentual'  => $f('int', 'Entrada (%)', 'condicoes', ['min' => 0, 'max' => 100]),
            'prazo_entrega_dias'  => $f('int', 'Prazo de entrega (dias)', 'condicoes', ['min' => 0, 'max' => 3650]),
            'condicoes_pagamento' => $f('textarea', 'Condições de pagamento', 'condicoes', ['l' => 2]),
            'apresentacao'  => $f('textarea', 'Apresentação', 'conteudo', ['l' => 2]),
            'escopo'        => $f('textarea', 'Escopo', 'conteudo', ['l' => 2]),
            'fora_escopo'   => $f('textarea', 'Fora do escopo', 'conteudo', ['l' => 2]),
            'cronograma'    => $f('textarea', 'Cronograma', 'conteudo', ['l' => 2]),
            'garantia'      => $f('textarea', 'Garantia', 'conteudo', ['l' => 2]),
            'observacoes'   => $f('textarea', 'Observações', 'conteudo', ['l' => 2]),
            'token_publico' => $f('texto', 'Token', 'basico', ['sis' => true]),
            'aceite_nome'   => $f('texto', 'Aceite: nome', 'basico', ['sis' => true]),
            'aceite_documento' => $f('texto', 'Aceite: documento', 'basico', ['sis' => true]),
            'aceite_ip'     => $f('texto', 'Aceite: IP', 'basico', ['sis' => true]),
            'motivo_recusa' => $f('texto', 'Motivo da recusa', 'basico', ['sis' => true]),
        ]];
    }

    /** Itens da proposta: só são gravados junto da proposta (chave "itens" do ActionExecutor). */
    private static function propostaItens(): array
    {
        $f = self::f(...);
        return ['tabela' => 'proposta_itens', 'singular' => 'Item', 'plural' => 'Itens', 'genero' => 'm', 'campos' => [
            'servico_id'     => $f('fk', 'Serviço', 'dados', ['fk' => 'servicos']),
            'descricao'      => $f('texto', 'Descrição', 'dados', ['req' => true, 'max' => 300]),
            'quantidade'     => $f('decimal', 'Quantidade', 'dados'),
            'unidade'        => $f('texto', 'Unidade', 'dados', ['max' => 30]),
            'valor_unitario' => $f('money', 'Valor unitário', 'dados'),
            'desconto'       => $f('money', 'Desconto', 'dados'),
            'recorrente'     => $f('bool', 'Recorrente', 'dados'),
            'ordem'          => $f('int', 'Ordem', 'dados', ['min' => 0, 'max' => 1000]),
        ]];
    }

    private static function contratos(): array
    {
        $f = self::f(...);
        return ['tabela' => 'contratos', 'singular' => 'Contrato', 'plural' => 'Contratos', 'genero' => 'm', 'campos' => [
            'titulo'      => $f('texto', 'Título', 'basico', ['req' => true, 'max' => 200, 'l' => 2]),
            'tipo_id'     => $f('fk', 'Tipo de contrato', 'basico', ['fk' => 'contrato_tipos']),
            'empresa_id'  => $f('fk', 'Empresa', 'basico', ['fk' => 'empresas']),
            'contato_id'  => $f('fk', 'Contato', 'basico', ['fk' => 'contatos']),
            'negocio_id'  => $f('fk', 'Negócio', 'basico', ['fk' => 'negocios']),
            'proposta_id' => $f('fk', 'Proposta', 'basico', ['fk' => 'propostas']),
            'modelo_id'   => $f('fk', 'Modelo', 'basico', ['fk' => 'modelos_documento']),
            'numero'      => $f('texto', 'Número', 'basico', ['sis' => true]),
            'status'      => $f('enum', 'Status', 'basico', ['op' => 'status_contrato', 'sis' => true]),
            'valor_total' => $f('money', 'Valor total (R$)', 'valores'),
            'valor_mensal' => $f('money', 'Valor mensal (R$)', 'valores'),
            'recorrencia' => $f('enum', 'Recorrência', 'valores', ['op' => 'recorrencia_contrato', 'req' => true]),
            'dia_vencimento_pagamento' => $f('int', 'Dia de vencimento do pagamento', 'valores', ['min' => 1, 'max' => 31]),
            'forma_pagamento' => $f('texto', 'Forma de pagamento', 'valores', ['max' => 160]),
            'data_inicio' => $f('data', 'Início', 'vigencia'),
            'data_fim'    => $f('data', 'Fim', 'vigencia'),
            'renovacao_automatica' => $f('bool', 'Renovação automática', 'vigencia'),
            'aviso_renovacao_dias' => $f('int', 'Aviso de renovação (dias)', 'vigencia', ['min' => 0, 'max' => 365]),
            'contrato_origem_id' => $f('fk', 'Contrato de origem', 'vigencia', ['fk' => 'contratos', 'sis' => true]),
            'indice_reajuste' => $f('enum', 'Índice de reajuste', 'vigencia', ['op' => 'indice_reajuste', 'req' => true]),
            'percentual_reajuste' => $f('money', 'Reajuste fixo (%)', 'vigencia', ['pct' => true]),
            'data_proximo_reajuste' => $f('data', 'Próximo reajuste', 'vigencia'),
            'multa_rescisoria' => $f('money', 'Multa rescisória (%)', 'vigencia', ['pct' => true]),
            'aviso_previo_dias' => $f('int', 'Aviso prévio (dias)', 'vigencia', ['min' => 0, 'max' => 365]),
            'conteudo'    => $f('html', 'Conteúdo do contrato (HTML)', 'conteudo', ['l' => 2, 'max' => 200000]),
            'clausulas_especiais' => $f('textarea', 'Cláusulas especiais', 'conteudo', ['l' => 2]),
            'notas'       => $f('textarea', 'Notas', 'extras', ['l' => 2]),
            'token_publico' => $f('texto', 'Token', 'basico', ['sis' => true]),
            'enviado_em'  => $f('datahora', 'Enviado em', 'basico', ['sis' => true]),
            'visualizado_em' => $f('datahora', 'Visualizado em', 'basico', ['sis' => true]),
            'assinado_em' => $f('datahora', 'Assinado em', 'basico', ['sis' => true]),
            'assinatura_nome' => $f('texto', 'Assinatura: nome', 'basico', ['sis' => true]),
            'assinatura_documento' => $f('texto', 'Assinatura: documento', 'basico', ['sis' => true]),
            'assinatura_ip' => $f('texto', 'Assinatura: IP', 'basico', ['sis' => true]),
        ]];
    }
}
