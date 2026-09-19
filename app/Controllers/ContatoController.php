<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\Repositorios;
use App\Services\Opcoes;
use App\Services\Schema;

final class ContatoController extends CrudController
{
    protected function entidade(): string
    {
        return 'contatos';
    }

    protected function rota(): string
    {
        return '/contatos';
    }

    protected function colunas(): array
    {
        $simples = static fn (string $campo) => static fn (array $l): string => (string) ($l[$campo] ?? '');
        return [
            'nome'               => ['rotulo' => 'Nome', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/contatos/' . (int) $l['id'])) . '">' . e($l['nome_completo']) . '</a>',
                'valor' => $l['nome_completo'],
            ]],
            'empresa_nome'       => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['empresa_id'] ? link_para('/empresas/' . (int) $l['empresa_id'], (string) $l['empresa_nome']) : '', 'valor' => (string) $l['empresa_nome'],
            ]],
            'cargo'              => ['rotulo' => 'Cargo', 'ordenavel' => true, 'padrao' => true, 'render' => $simples('cargo')],
            'email'              => ['rotulo' => 'E-mail', 'ordenavel' => true, 'padrao' => true, 'render' => $simples('email')],
            'whatsapp'           => ['rotulo' => 'WhatsApp / telefone', 'padrao' => true, 'render' => static fn (array $l): string => (string) ($l['whatsapp'] ?: $l['telefone'])],
            'status'             => ['rotulo' => 'Status', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_status('status_contato', $l['status'])]],
            'ultimo_contato_em'  => ['rotulo' => 'Último contato', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => data_br($l['ultimo_contato_em'])],
            'proximo_contato_em' => ['rotulo' => 'Próximo contato', 'ordenavel' => true, 'render' => static fn (array $l): string => data_br($l['proximo_contato_em'])],
            'origem_nome'        => ['rotulo' => 'Origem', 'ordenavel' => true, 'render' => $simples('origem_nome')],
            'tags'               => ['rotulo' => 'Tags', 'render' => static fn (array $l, array $ctx): array => ['html' => chips_tags($ctx['tags'][(int) $l['id']] ?? [])]],
            'criado_em'          => ['rotulo' => 'Criado em', 'ordenavel' => true, 'render' => static fn (array $l): string => data_br($l['criado_em'])],
        ];
    }

    protected function filtros(): array
    {
        return [
            'status'        => ['rotulo' => 'Status', 'opcoes' => Schema::opcoes('status_contato')],
            'empresa_id'    => ['rotulo' => 'Empresa', 'opcoes' => Opcoes::para('empresas')],
            'papel_decisao' => ['rotulo' => 'Papel', 'opcoes' => Schema::opcoes('papel_decisao')],
            'origem_id'     => ['rotulo' => 'Origem', 'opcoes' => Opcoes::para('origens')],
        ];
    }

    protected function abasForm(): array
    {
        return ['pessoal' => 'Pessoal', 'profissional' => 'Profissional', 'canais' => 'Canais', 'lgpd' => 'LGPD', 'relacionamento' => 'Relacionamento', 'extras' => 'Extras'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return $registro['nome_completo'];
    }

    protected function padroesNovo(): array
    {
        return parent::padroesNovo() + ['status' => 'ativo'];
    }

    protected function dadosDetalhe(array $registro): array
    {
        $id = (int) $registro['id'];
        return [
            'negocios'   => Repositorios::negocios()->porContato($id),
            'tarefas'    => Repositorios::tarefas()->porVinculo('contato_id', $id),
            'atividades' => Repositorios::atividades()->timeline('contato_id', $id),
        ];
    }
}
