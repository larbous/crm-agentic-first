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
 * Tipos: texto textarea email tel url int money data datahora vencimento enum bool fk cnpj cpf cep uf cor.
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
        ];
    }

    private static function f(string $t, string $r, string $g, array $x = []): array
    {
        return ['t' => $t, 'r' => $r, 'g' => $g] + $x;
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
            'temperatura'     => ['frio' => 'Frio', 'morno' => 'Morno', 'quente' => 'Quente'],
            'prioridade_neg'  => ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'],
            'tipo_atividade'  => ['nota' => 'Nota', 'ligacao' => 'Ligação', 'whatsapp' => 'WhatsApp', 'email' => 'E-mail', 'reuniao' => 'Reunião', 'visita' => 'Visita', 'proposta' => 'Proposta', 'contrato' => 'Contrato', 'sistema' => 'Sistema'],
            'direcao'         => ['entrada' => 'Entrada', 'saida' => 'Saída'],
            'tipo_tarefa'     => ['ligar' => 'Ligar', 'enviar' => 'Enviar', 'reuniao' => 'Reunião', 'followup' => 'Follow-up', 'interno' => 'Interno', 'outro' => 'Outro'],
            'prioridade_tar'  => ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'],
            'status_tarefa'   => ['pendente' => 'Pendente', 'andamento' => 'Em andamento', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'],
            'recorrencia'     => ['nenhuma' => 'Nenhuma', 'diaria' => 'Diária', 'semanal' => 'Semanal', 'mensal' => 'Mensal', 'anual' => 'Anual'],
            'tipo_etapa'      => ['aberta' => 'Aberta', 'ganho' => 'Ganho', 'perdido' => 'Perdido'],
            'entidade_anexo'  => ['empresas' => 'Empresa', 'contatos' => 'Contato', 'negocios' => 'Negócio'],
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
}
