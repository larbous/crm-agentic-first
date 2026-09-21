<?php

declare(strict_types=1);

use App\Repositories\FormularioRepository;
use App\Repositories\PesquisaRepository;
use App\Services\Nps;

/** Componentes das pesquisas NPS (Fase 12): selos, link individual e o painel da empresa. */

/** Selo da nota: "9 · Promotor" (verde), "7 · Neutro" (amarelo), "3 · Detrator" (vermelho). */
function badge_nps(?int $nota, ?string $categoria): string
{
    if ($nota === null || $categoria === null) {
        return '';
    }
    $variante = ['promotor' => 'success', 'neutro' => 'warning', 'detrator' => 'destructive'][$categoria] ?? 'secondary';
    return badge($nota . ' · ' . (Nps::CATEGORIAS[$categoria] ?? $categoria), $variante);
}

function badge_pesquisa_status(array $p): string
{
    $rotulos = ['pendente' => 'Pendente', 'respondida' => 'Respondida', 'expirada' => 'Expirada', 'cancelada' => 'Cancelada'];
    $variantes = ['pendente' => 'info', 'respondida' => 'success', 'expirada' => 'warning', 'cancelada' => 'secondary'];
    $status = (string) $p['status'];
    // Pendente com o prazo já vencido (o worker ainda não passou): mostra como expirada.
    if ($status === 'pendente' && strtotime((string) $p['expira_em']) < time()) {
        $status = 'expirada';
    }
    return badge($rotulos[$status] ?? $status, $variantes[$status] ?? 'secondary');
}

/** Endereço público do link individual da pesquisa. */
function pesquisa_link(array $p): string
{
    return url_publica('/nps/' . $p['token']);
}

/** Mensagem pronta para colar no WhatsApp ou no e-mail. */
function pesquisa_mensagem(array $p): string
{
    $primeiro = trim(explode(' ', trim((string) ($p['contato_nome'] ?? '')))[0] ?? '');
    return ($primeiro !== '' ? "Olá, {$primeiro}! " : 'Olá! ') . 'Poderia responder uma pesquisa rápida sobre o nosso trabalho? Leva menos de 1 minuto: ' . pesquisa_link($p);
}

/** wa.me com a mensagem pronta (null se o contato não tem WhatsApp). Número só com dígitos, com DDI 55 quando faltar. */
function pesquisa_url_whatsapp(array $p): ?string
{
    $numero = preg_replace('/\D/', '', (string) ($p['contato_whatsapp'] ?? '')) ?? '';
    if ($numero === '') {
        return null;
    }
    if (strlen($numero) <= 11) {
        $numero = '55' . $numero;
    }
    return 'https://wa.me/' . $numero . '?text=' . rawurlencode(pesquisa_mensagem($p));
}

/** mailto: com assunto e corpo prontos (null se o contato não tem e-mail). */
function pesquisa_url_email(array $p): ?string
{
    $email = trim((string) ($p['contato_email'] ?? ''));
    if ($email === '') {
        return null;
    }
    return 'mailto:' . rawurlencode($email) . '?subject=' . rawurlencode('Pesquisa rápida sobre o nosso trabalho') . '&body=' . rawurlencode(pesquisa_mensagem($p));
}

/** Aba "Pesquisas" da empresa: histórico e criação de um envio manual. */
function pesquisas_empresa(int $empresaId, string $voltar): string
{
    $linhas = [];
    foreach ((new PesquisaRepository())->daEmpresa($empresaId, 10) as $p) {
        $linhas[] = [
            'pesquisa' => ['html' => link_para('/pesquisas/' . (int) $p['id'], (string) $p['formulario_nome'])],
            'quando' => datahora_br((string) ($p['respondida_em'] ?? $p['criado_em'])),
            'contato' => (string) ($p['contato_nome'] ?? ''),
            'nota' => ['html' => $p['status'] === 'respondida' ? badge_nps((int) $p['nota'], (string) $p['categoria']) : badge_pesquisa_status($p)],
        ];
    }
    $ativas = (new FormularioRepository())->pesquisasAtivas();
    $criar = $ativas === []
        ? '<p class="text-muted-foreground text-sm">Nenhuma pesquisa ativa. Crie uma em ' . link_para('/formularios', 'Formulários') . ' (Nova pesquisa NPS).</p>'
        : '<form method="post" action="' . e(url('/pesquisas')) . '" class="mb-3 flex flex-wrap items-end gap-2">' . csrf_field()
            . '<input type="hidden" name="empresa_id" value="' . $empresaId . '"><input type="hidden" name="voltar" value="' . e($voltar) . '">'
            . '<div class="field min-w-56"><label for="pesq-form">Enviar pesquisa</label>' . select('formulario_id', $ativas, null, ['id' => 'pesq-form']) . '</div>'
            . botao('Criar link', ['tipo' => 'submit', 'variante' => 'outline', 'icone' => 'star']) . '</form>';
    return $criar . ($linhas !== []
        ? tabela(['pesquisa' => 'Pesquisa', 'quando' => 'Data', 'contato' => 'Contato', 'nota' => 'Nota / situação'], $linhas)
        : vazio('Sem pesquisas', 'Esta empresa ainda não recebeu nenhuma pesquisa de satisfação.', ['icone' => 'star']));
}
