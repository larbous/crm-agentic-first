<?php
/**
 * Formulário genérico em abas, montado a partir do Schema.
 * @var string $entidade
 * @var array $schema
 * @var array|null $registro
 * @var string $acao
 * @var string $cancelar
 * @var array $valores
 * @var array $erros
 * @var array $abas grupo => rótulo
 * @var list<string> $ocultar
 * @var array $opcoes
 * @var callable $extraAba
 * @var string $voltar
 */
use App\Services\Schema;

$campos = Schema::gravaveis($entidade);
$errosGerais = $erros['_'] ?? null;

// Aba ativa: a primeira com erro; senão a primeira
$grupoDoErro = [];
foreach ($erros as $campo => $msg) {
    if (isset($campos[$campo])) {
        $grupoDoErro[$campos[$campo]['g']] = true;
    }
}
$paineis = [];
$ativa = 0;
foreach ($abas as $grupo => $rotulo) {
    $temCampos = false;
    foreach ($campos as $nome => $def) {
        if ($def['g'] === $grupo && !in_array($nome, $ocultar, true)) {
            $temCampos = true;
            break;
        }
    }
    $extra = $extraAba($grupo);
    if (!$temCampos && $extra === '') {
        continue;
    }
    if (isset($grupoDoErro[$grupo]) && !isset($primeiroErro)) {
        $primeiroErro = count($paineis);
        $ativa = $primeiroErro;
    }
    $paineis[] = [
        'rotulo' => $rotulo . (isset($grupoDoErro[$grupo]) ? ' •' : ''),
        'html'   => campos_do_schema($entidade, $grupo, $valores, $erros, ['ocultar' => $ocultar, 'opcoes' => $opcoes]) . $extra,
    ];
}
$outrosErros = array_diff_key($erros, $campos, ['_' => 1]);
?>
<div class="page-cabecalho">
    <div>
        <h1 class="page-titulo"><?= e($titulo) ?></h1>
        <?php if ($registro !== null): ?>
            <p class="text-muted-foreground"><?= e($registro['nome_fantasia'] ?? $registro['nome_completo'] ?? $registro['nome'] ?? $registro['titulo'] ?? '') ?></p>
        <?php endif; ?>
    </div>
</div>

<form method="post" action="<?= e($acao) ?>" class="grid gap-4" novalidate data-form-schema>
    <?= csrf_field() ?>
    <?php if ($voltar !== ''): ?><input type="hidden" name="voltar" value="<?= e($voltar) ?>"><?php endif; ?>

    <?php if ($erros !== []): ?>
        <?= alerta('Corrija os campos indicados', $errosGerais ?? implode(' ', array_slice(array_values($erros), 0, 3)) . (count($erros) > 3 ? ' …' : ''), 'destructive') ?>
    <?php endif; ?>

    <?= card(['corpo_html' => abas('abas-form', $paineis, $ativa, ['linha' => true])]) ?>

    <div class="flex flex-wrap items-center gap-2">
        <?= botao('Salvar', ['tipo' => 'submit', 'icone' => 'check']) ?>
        <?= botao('Cancelar', ['href' => $cancelar, 'variante' => 'outline']) ?>
    </div>
</form>
