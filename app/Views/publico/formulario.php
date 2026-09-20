<?php
/**
 * Página pública do formulário de captação.
 * @var array $formulario
 * @var list<array> $campos
 * @var array $destinos
 * @var array<string,string> $erros por `c<id>` (e `_` para erros gerais)
 * @var array $valores
 * @var array<string,string> $rastreio utm_*, _pagina, _ref
 * @var string $acao
 * @var bool $embed
 */
use App\Services\FormularioDefinicao;

$titulo = (string) ($formulario['titulo'] ?? '');
?>
<main class="fp-pagina">
    <form method="post" action="<?= e($acao) ?>" class="fp-form" novalidate data-formulario-publico>
        <?php if ($titulo !== ''): ?><h1 class="fp-titulo"><?= e($titulo) ?></h1><?php endif; ?>

        <?php if (isset($erros['_'])): ?>
            <?= alerta('Não foi possível enviar', (string) $erros['_'], 'destructive') ?>
        <?php elseif ($erros !== []): ?>
            <?= alerta('Confira os campos indicados', 'Alguns campos precisam de atenção antes de enviar.', 'destructive') ?>
        <?php endif; ?>

        <div class="fp-grade">
            <?php foreach ($campos as $c):
                $nome = 'c' . $c['id'];
                $def = $destinos[$c['campo_destino']];
                $valor = $valores[$nome] ?? '';
                $base = ['nome' => $nome, 'id' => 'f-' . $nome, 'rotulo' => $c['rotulo'], 'obrigatorio' => (int) $c['obrigatorio'] === 1, 'ajuda' => $c['ajuda'] ?: null,
                    'placeholder' => $c['placeholder'] ?: null, 'erro' => $erros[$nome] ?? null];
                ?>
                <div class="fp-campo" style="--span: <?= (int) $c['largura'] ?>">
                    <?php
                    switch ($c['tipo']) {
                        case 'textarea':
                            echo campo($base + ['tipo' => 'textarea', 'valor' => $valor, 'linhas' => 4]);
                            break;
                        case 'checkbox':
                            echo campo($base + ['tipo' => 'checkbox', 'valor' => $valor === '1' || $valor === 1]);
                            break;
                        case 'select':
                            $opcoes = FormularioDefinicao::opcoesDoCampo($c, $def);
                            echo campo($base + ['controle_html' => select($nome, $opcoes, $valor, ['id' => 'f-' . $nome, 'placeholder' => 'Selecione…',
                                'attrs' => ['aria-invalid' => isset($erros[$nome]) ? 'true' : null]])]);
                            break;
                        default:
                            $tipoInput = ['email' => 'email', 'telefone' => 'tel', 'data' => 'date', 'numero' => 'text'][$c['tipo']] ?? 'text';
                            echo campo($base + ['tipo' => $tipoInput, 'valor' => $valor, 'attrs' => $c['tipo'] === 'numero' ? ['inputmode' => 'decimal'] : ($c['tipo'] === 'telefone' ? ['inputmode' => 'tel', 'autocomplete' => 'tel'] : [])]);
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($rastreio as $k => $v): if ($v !== ''): ?>
            <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
        <?php endif; endforeach; ?>
        <div class="sr-only" aria-hidden="true"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="fp-acoes">
            <?= botao((string) $formulario['texto_botao'], ['tipo' => 'submit', 'attrs' => ['data-formulario-enviar' => true]]) ?>
        </div>
    </form>
</main>
