<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Repositories\Repositorios;
use App\Services\Opcoes;
use App\Services\Schema;

final class EmpresaController extends CrudController
{
    protected function entidade(): string
    {
        return 'empresas';
    }

    protected function rota(): string
    {
        return '/empresas';
    }

    protected function colunas(): array
    {
        $nome = static fn (array $l): array => [
            'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/empresas/' . (int) $l['id'])) . '">' . e($l['nome_fantasia']) . '</a>'
                . ($l['razao_social'] && $l['razao_social'] !== $l['nome_fantasia'] ? '<div class="text-muted-foreground text-xs">' . e($l['razao_social']) . '</div>' : ''),
            'valor' => $l['nome_fantasia'],
        ];
        $simples = static fn (string $campo) => static fn (array $l): string => (string) ($l[$campo] ?? '');

        return [
            'nome_fantasia'    => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => $nome],
            'status'           => ['rotulo' => 'Status', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_status('status_empresa', $l['status'])]],
            'classificacao'    => ['rotulo' => 'Classif.', 'ordenavel' => true, 'padrao' => true, 'render' => $simples('classificacao')],
            'segmento'         => ['rotulo' => 'Segmento', 'ordenavel' => true, 'render' => $simples('segmento')],
            'cidade'           => ['rotulo' => 'Cidade', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => trim(($l['cidade'] ?? '') . ($l['uf'] ? '/' . $l['uf'] : ''))],
            'telefone'         => ['rotulo' => 'Telefone', 'ordenavel' => true, 'render' => static fn (array $l): string => (string) ($l['whatsapp'] ?: $l['telefone'])],
            'email_geral'      => ['rotulo' => 'E-mail', 'ordenavel' => true, 'render' => $simples('email_geral')],
            'origem_nome'      => ['rotulo' => 'Origem', 'ordenavel' => true, 'padrao' => true, 'render' => $simples('origem_nome')],
            'tags'             => ['rotulo' => 'Tags', 'padrao' => true, 'render' => static fn (array $l, array $ctx): array => ['html' => chips_tags($ctx['tags'][(int) $l['id']] ?? [])]],
            'mrr'              => ['rotulo' => 'MRR', 'ordenavel' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => ['html' => e(moeda((int) $l['mrr'])), 'valor' => (int) $l['mrr']]],
            'ltv'              => ['rotulo' => 'LTV', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => ['html' => e(moeda((int) $l['ltv'])), 'valor' => (int) $l['ltv']]],
            'ticket_potencial' => ['rotulo' => 'Ticket potencial', 'ordenavel' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): string => $l['ticket_potencial'] !== null ? moeda((int) $l['ticket_potencial']) : ''],
            'criado_em'        => ['rotulo' => 'Criada em', 'ordenavel' => true, 'render' => static fn (array $l): string => data_br($l['criado_em'])],
        ];
    }

    protected function filtros(): array
    {
        return [
            'status'        => ['rotulo' => 'Status', 'opcoes' => Schema::opcoes('status_empresa')],
            'classificacao' => ['rotulo' => 'Classificação', 'opcoes' => Schema::opcoes('classificacao')],
            'origem_id'     => ['rotulo' => 'Origem', 'opcoes' => Opcoes::para('origens')],
        ];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados', 'contato' => 'Contato', 'endereco' => 'Endereço', 'digital' => 'Digital', 'aquisicao' => 'Aquisição', 'extras' => 'Extras'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return $registro['nome_fantasia'];
    }

    protected function dadosDetalhe(array $registro): array
    {
        $id = (int) $registro['id'];
        return [
            'contatos'   => Repositorios::contatos()->porEmpresa($id),
            'negocios'   => Repositorios::negocios()->porEmpresa($id),
            'tarefas'    => Repositorios::tarefas()->porVinculo('empresa_id', $id),
            'atividades' => Repositorios::atividades()->timeline('empresa_id', $id),
            'propostas'  => Repositorios::propostas()->ultimasPor('empresa_id', $id),
            'contratos'  => Repositorios::contratos()->por('empresa_id', $id),
            'cobrancas'  => Repositorios::cobrancas()->daEmpresa($id),
            'custos'     => Repositorios::custos()->daEmpresa($id),
        ];
    }

    protected function padroesNovo(): array
    {
        return parent::padroesNovo() + ['status' => 'lead', 'pais' => 'Brasil'];
    }

    public function converter(array $p): Response
    {
        $r = $this->executor()->converterCliente((int) $p['id'], 'humano');
        $this->flash($r);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/empresas/' . (int) $p['id'])));
    }
}
