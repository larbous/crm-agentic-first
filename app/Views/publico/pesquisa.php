<?php
/**
 * Página pública da pesquisa de satisfação (link individual).
 * @var array $formulario
 * @var array $pesquisa
 * @var list<array> $campos campos do formulário (FormularioRepository::campos)
 * @var array<string,string> $erros por `c<id>`
 * @var array $valores
 * @var string $acao
 */
$titulo = (string) ($formulario['titulo'] ?? '');
?>
<main class="fp-pagina">
    <form method="post" action="<?= e($acao) ?>" class="fp-form" novalidate data-formulario-publico>
        <?php if ($titulo !== ''): ?><h1 class="fp-titulo"><?= e($titulo) ?></h1><?php endif; ?>
        <?php if ($erros !== []): ?>
            <?= alerta('Confira as respostas', 'Alguns campos precisam de atenção antes de enviar.', 'destructive') ?>
        <?php endif; ?>

        <div class="fp-grade">
            <?php foreach ($campos as $c):
                $nome = 'c' . $c['id'];
                $valor = $valores[$nome] ?? '';
                $base = ['nome' => $nome, 'id' => 'f-' . $nome, 'rotulo' => $c['rotulo'], 'obrigatorio' => (int) $c['obrigatorio'] === 1, 'ajuda' => $c['ajuda'] ?: null,
                    'placeholder' => $c['placeholder'] ?: null, 'erro' => $erros[$nome] ?? null];
                ?>
                <div class="fp-campo" style="--span: <?= (int) $c['largura'] ?>">
                    <?php if ($c['campo_destino'] === 'pesquisa.nota'): ?>
                        <fieldset class="fp-nps-grupo">
                            <legend class="fp-nps-legenda"><?= e($c['rotulo']) ?><?= (int) $c['obrigatorio'] === 1 ? '<span class="text-destructive"> *</span>' : '' ?></legend>
                            <div class="fp-nps" role="radiogroup" aria-label="<?= e($c['rotulo']) ?>">
                                <?php for ($n = 0; $n <= 10; $n++): ?>
                                    <label class="fp-nps-opcao">
                                        <input type="radio" name="<?= e($nome) ?>" value="<?= $n ?>" <?= (string) $valor === (string) $n ? 'checked' : '' ?>>
                                        <span class="fp-nps-nota"><?= $n ?></span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                            <?php if ($c['ajuda']): ?><p class="text-muted-foreground mt-1 text-sm"><?= e($c['ajuda']) ?></p><?php endif; ?>
                            <?php if (isset($erros[$nome])): ?><p class="text-destructive mt-1 text-sm" role="alert"><?= e($erros[$nome]) ?></p><?php endif; ?>
                        </fieldset>
                    <?php else:
                        switch ($c['tipo']) {
                            case 'textarea':
                                echo campo($base + ['tipo' => 'textarea', 'valor' => $valor, 'linhas' => 4]);
                                break;
                            case 'checkbox':
                                echo campo($base + ['tipo' => 'checkbox', 'valor' => $valor === '1' || $valor === 1]);
                                break;
                            case 'select':
                                $opcoes = array_combine((array) $c['opcoes'], (array) $c['opcoes']) ?: [];
                                echo campo($base + ['controle_html' => select($nome, $opcoes, $valor, ['id' => 'f-' . $nome, 'placeholder' => 'Selecione…',
                                    'attrs' => ['aria-invalid' => isset($erros[$nome]) ? 'true' : null]])]);
                                break;
                            default:
                                echo campo($base + ['tipo' => 'text', 'valor' => $valor, 'attrs' => $c['tipo'] === 'numero' ? ['inputmode' => 'decimal'] : []]);
                        }
                    endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="fp-acoes">
            <?= botao((string) $formulario['texto_botao'], ['tipo' => 'submit', 'attrs' => ['data-formulario-enviar' => true]]) ?>
        </div>
    </form>
</main>
