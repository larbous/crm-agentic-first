<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Metas;
use App\Services\Schema;

/** Metas comerciais (SPEC §4.11, §9): cadastro, lista e detalhe com o progresso calculado na hora. */
final class MetaController extends CrudController
{
    /** @var array<int,array> progresso já calculado por meta (a lista o usa em várias colunas) */
    private array $progressos = [];

    protected function entidade(): string
    {
        return 'metas';
    }

    protected function rota(): string
    {
        return '/metas';
    }

    protected function usaTags(): bool
    {
        return false;
    }

    private function progresso(array $meta): array
    {
        return $this->progressos[(int) $meta['id']] ??= Metas::progresso($meta);
    }

    protected function colunas(): array
    {
        return [
            'tipo'        => ['rotulo' => 'Meta', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => '<a class="font-medium underline-offset-4 hover:underline" href="' . e(url('/metas/' . (int) $l['id'])) . '">'
                    . e(Schema::opcoes('tipo_meta')[$l['tipo']] ?? $l['tipo']) . '</a>',
                'valor' => $l['tipo'],
            ]],
            'periodo'     => ['rotulo' => 'Período', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): string => Schema::opcoes('periodo_meta')[$l['periodo']] ?? $l['periodo']],
            'data_inicio' => ['rotulo' => 'Vigência', 'ordenavel' => true, 'padrao' => true, 'render' => static fn (array $l): array => [
                'html' => e(data_br($l['data_inicio']) . ' a ' . data_br($l['data_fim'])), 'valor' => $l['data_inicio'],
            ]],
            'valor_alvo'  => ['rotulo' => 'Alvo', 'ordenavel' => true, 'padrao' => true, 'alinhar' => 'direita', 'render' => fn (array $l): string => $this->progresso($l)['alvo_txt']],
            'realizado'   => ['rotulo' => 'Realizado', 'padrao' => true, 'alinhar' => 'direita', 'render' => fn (array $l): string => $this->progresso($l)['realizado_txt']],
            'progresso'   => ['rotulo' => 'Progresso', 'padrao' => true, 'render' => fn (array $l): array => [
                'html' => '<div class="grid min-w-32 gap-1">' . meta_barra($this->progresso($l)) . '<span class="text-muted-foreground text-xs">' . $this->progresso($l)['percentual'] . '%</span></div>',
            ]],
            'situacao'    => ['rotulo' => 'Situação', 'padrao' => true, 'render' => fn (array $l): array => ['html' => meta_situacao($this->progresso($l))]],
        ];
    }

    protected function filtros(): array
    {
        return [
            'tipo'     => ['rotulo' => 'Tipo', 'opcoes' => Schema::opcoes('tipo_meta')],
            'periodo'  => ['rotulo' => 'Período', 'opcoes' => Schema::opcoes('periodo_meta')],
            'vigencia' => ['rotulo' => 'Vigência', 'opcoes' => ['vigentes' => 'Vigentes', 'futuras' => 'Futuras', 'encerradas' => 'Encerradas']],
        ];
    }

    protected function abasForm(): array
    {
        return ['dados' => 'Dados da meta'];
    }

    protected function tituloRegistro(array $registro): string
    {
        return Metas::rotulo($registro);
    }

    protected function dadosDetalhe(array $registro): array
    {
        return ['progresso' => Metas::progresso($registro)];
    }

    protected function padroesNovo(): array
    {
        $primeiroDia = date('Y-m-01');
        return parent::padroesNovo() + ['tipo' => 'faturamento', 'periodo' => 'mensal', 'data_inicio' => $primeiroDia];
    }

    /** O valor alvo é dinheiro (faturamento, MRR) ou quantidade, conforme o tipo: tem campo próprio, ver extraAba(). */
    protected function ocultarNoForm(?array $registro): array
    {
        return ['valor_alvo'];
    }

    protected function extraAba(string $grupo, array $valores, array $erros): string
    {
        if ($grupo !== 'dados') {
            return '';
        }
        return '<div class="mt-4 grid gap-4 md:grid-cols-2">' . campo([
            'nome' => 'valor_alvo', 'rotulo' => 'Valor alvo', 'valor' => (string) ($valores['valor_alvo'] ?? ''), 'erro' => $erros['valor_alvo'] ?? null,
            'obrigatorio' => true, 'ajuda' => 'Em reais para faturamento e MRR; quantidade para os demais tipos.',
            'attrs' => ['inputmode' => 'decimal', 'placeholder' => '0'],
        ]) . '</div>'
        . '<p class="text-muted-foreground mt-4 text-sm">O período termina sozinho: mensal = 1 mês, trimestral = 3 meses, anual = 12 meses a partir do início.</p>';
    }

    /** Dinheiro digitado em reais vira centavos; quantidade aceita separador de milhar ("1.000"). */
    protected function prepararEntrada(array $entrada): array
    {
        if (isset($entrada['valor_alvo']) && is_string($entrada['valor_alvo'])) {
            if (Metas::monetaria((string) ($entrada['tipo'] ?? ''))) {
                $centavos = reais_para_centavos($entrada['valor_alvo']);
                $entrada['valor_alvo'] = $centavos !== null ? (string) $centavos : $entrada['valor_alvo'];
            } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', trim($entrada['valor_alvo'])) === 1) {
                $entrada['valor_alvo'] = str_replace('.', '', trim($entrada['valor_alvo']));
            }
        }
        return $entrada;
    }

    protected function valoresParaForm(array $valores): array
    {
        if (isset($valores['valor_alvo']) && Metas::monetaria((string) ($valores['tipo'] ?? '')) && preg_match('/^\d+$/', (string) $valores['valor_alvo']) === 1) {
            $valores['valor_alvo'] = centavos_para_texto((int) $valores['valor_alvo']);
        }
        return $valores;
    }
}
