<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Router;
use App\Repositories\AuditoriaRepository;
use App\Repositories\Repositorios;
use App\Services\Schema;

/**
 * Cobranças (Fase 17): ligadas ao Asaas (avulsa ou recorrente). Criar já tenta emitir no Asaas; "Emitir" tenta de
 * novo se a primeira tentativa falhou; "Cancelar" cancela no Asaas (se emitida) e localmente.
 */
final class CobrancaController extends CrudController
{
    protected function entidade(): string
    {
        return 'cobrancas';
    }

    protected function rota(): string
    {
        return '/financeiro/cobrancas';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    public static function registrarExtras(Router $r): void
    {
        $r->post('/financeiro/cobrancas/{id:\d+}/emitir', [self::class, 'emitir']);
        $r->post('/financeiro/cobrancas/{id:\d+}/cancelar', [self::class, 'cancelar']);
    }

    protected function colunas(): array
    {
        return [
            'descricao' => ['rotulo' => 'Cobrança', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/financeiro/cobrancas/' . (int) $l['id'])) . '">' . e((string) $l['descricao']) . '</a>', 'valor' => $l['descricao'],
            ]],
            'empresa'   => ['rotulo' => 'Empresa', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => link_para('/empresas/' . (int) $l['empresa_id'], (string) $l['empresa_nome']), 'valor' => (string) $l['empresa_nome']]],
            'tipo'      => ['rotulo' => 'Tipo', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => Schema::opcoes('tipo_cobranca')[$l['tipo']] ?? $l['tipo']],
            'valor'     => ['rotulo' => 'Valor', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => static fn (array $l): array => ['html' => e(moeda((int) $l['valor'])), 'valor' => (int) $l['valor']]],
            'vencimento' => ['rotulo' => 'Vencimento', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => data_br((string) $l['vencimento'])],
            'status'    => ['rotulo' => 'Status', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => ['html' => badge_cobranca((string) $l['status'])]],
            'asaas'     => ['rotulo' => 'Asaas', 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => $l['url_fatura'] ? '<a class="text-primary underline-offset-4 hover:underline" href="' . e((string) $l['url_fatura']) . '" target="_blank" rel="noopener">Ver fatura</a>' : ($l['asaas_id'] ? badge('Emitida', 'info') : badge('Não emitida', 'secondary')),
            ]],
        ];
    }

    protected function filtros(): array
    {
        return [
            'tipo'   => ['rotulo' => 'Tipo', 'opcoes' => Schema::opcoes('tipo_cobranca')],
            'status' => ['rotulo' => 'Status', 'opcoes' => Schema::opcoes('status_cobranca')],
            'forma_pagamento' => ['rotulo' => 'Forma de pagamento', 'opcoes' => Schema::opcoes('forma_pagamento_asaas')],
        ];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return (string) $registro['descricao'];
    }

    protected function dadosDetalhe(array $registro): array
    {
        return [
            'historico' => (new AuditoriaRepository())->listar(['entidade' => 'cobrancas', 'registro_id' => (string) $registro['id']], 1, 30)['linhas'],
        ];
    }

    protected function padroesNovo(): array
    {
        return parent::padroesNovo() + ['tipo' => 'avulsa', 'forma_pagamento' => 'indefinido', 'vencimento' => hoje()];
    }

    // ---- Criar via ActionExecutor::criarCobranca (cria local e já tenta emitir no Asaas) ------------

    public function criar(): Response
    {
        $entrada = $this->prepararEntrada(Schema::filtrar($this->entidade(), $_POST));
        $r = $this->executor()->criarCobranca($entrada, 'humano');
        if ($r->ok) {
            $this->flash($r);
            return Response::redirecionar($this->destinoApos((int) $r->id));
        }
        return $this->renderForm(null, $this->valoresParaForm($entrada), $r->erros, 422);
    }

    // ---- Ações da página da cobrança -----------------------------------------------------

    public function emitir(array $p): Response
    {
        $this->flash($this->executor()->emitirCobranca((int) $p['id'], 'humano'));
        return Response::redirecionar($this->voltarPara((int) $p['id']));
    }

    public function cancelar(array $p): Response
    {
        $this->flash($this->executor()->cancelarCobranca((int) $p['id'], 'humano'));
        return Response::redirecionar($this->voltarPara((int) $p['id']));
    }

    private function voltarPara(int $id): string
    {
        return caminho_seguro($_POST['voltar'] ?? null, url('/financeiro/cobrancas/' . $id));
    }
}
