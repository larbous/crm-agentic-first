<?php

declare(strict_types=1);

namespace App\Services\Canais;

use App\Repositories\ConfiguracaoRepository;
use App\Services\ActionExecutor;
use Throwable;

/** Canal de e-mail: envio por SMTP e coleta da caixa por IMAP (sem extensão imap). */
final class Email
{
    /** Segundos mínimos entre duas conexões ao IMAP (o worker roda a cada minuto). */
    public const INTERVALO_PADRAO = 120;
    public const LIMITE_POR_RODADA = 20;

    /**
     * @return array{ok:bool,id:?string,erro:?string}
     */
    public static function enviar(string $para, string $assunto, string $texto, ?string $respondeA = null): array
    {
        $c = Canais::config('email');
        $de = (string) ($c['de_email'] ?? '');
        $msg = Mime::montar($de, (string) ($c['de_nome'] ?? ''), $para, $assunto, $texto, $respondeA);
        try {
            (new Smtp(Canais::conexao('smtp'), substr(strrchr($de, '@') ?: '@localhost', 1)))->enviar(
                (string) (($c['smtp_usuario'] ?? '') ?: ($c['usuario'] ?? '')), (string) (($c['smtp_senha'] ?? '') ?: ($c['senha'] ?? '')),
                (string) ($c['smtp_seguranca'] ?? 'ssl'), $de, $para, $msg['bruto'],
            );
            return ['ok' => true, 'id' => 'em:' . $msg['message_id'], 'erro' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'id' => null, 'erro' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * Baixa os e-mails novos da caixa e registra na caixa de entrada. Na primeira execução só marca o ponto de partida
     * (o histórico da caixa não é importado). Ignora respostas automáticas, mensagens nossas e, por padrão, remetentes
     * que ainda não são contatos nem empresas. Devolve quantas mensagens entraram.
     */
    public static function coletar(?ActionExecutor $executor = null, ?int $agora = null): int
    {
        if (!Canais::ativo('email')) {
            return 0;
        }
        $config = new ConfiguracaoRepository();
        $agora ??= time();
        $intervalo = max(30, (int) (Canais::valor('email', 'intervalo_seg') ?: self::INTERVALO_PADRAO));
        if ($agora - (int) $config->obter('canais.email.ultimo_poll', '0') < $intervalo) {
            return 0;
        }
        $config->definir('canais.email.ultimo_poll', (string) $agora);

        $c = Canais::config('email');
        $imap = new Imap(Canais::conexao('imap'));
        $importadas = 0;
        try {
            $imap->iniciar((string) $c['usuario'], (string) $c['senha'], (string) ($c['imap_seguranca'] ?? 'ssl'));
            $imap->selecionar('INBOX');
            $ultimo = $config->obter('canais.email.ultimo_uid');
            if ($ultimo === null) {
                $config->definir('canais.email.ultimo_uid', (string) $imap->maiorUid());
                return 0;
            }
            $executor ??= new ActionExecutor();
            $proprios = array_filter([mb_strtolower((string) ($c['de_email'] ?? '')), mb_strtolower((string) ($c['usuario'] ?? ''))]);
            foreach (array_slice($imap->uidsDesde((int) $ultimo), 0, self::LIMITE_POR_RODADA) as $uid) {
                $importadas += self::importar($executor, $imap->baixar($uid), $uid, $proprios, !empty($c['importar_desconhecidos'])) ? 1 : 0;
                $config->definir('canais.email.ultimo_uid', (string) $uid);
            }
        } finally {
            try {
                $imap->sair();
            } catch (Throwable) {
                // a conexão pode já ter caído
            }
        }
        return $importadas;
    }

    private static function importar(ActionExecutor $executor, string $bruto, int $uid, array $proprios, bool $desconhecidos): bool
    {
        $e = Mime::ler($bruto);
        if ($e['de_email'] === '' || $e['automatica'] || in_array($e['de_email'], $proprios, true)) {
            return false;
        }
        if (!$desconhecidos) {
            $ligacao = ContatoResolver::resolver('email', $e['de_email']);
            if ($ligacao['contato_id'] === null && $ligacao['empresa_id'] === null) {
                return false;
            }
        }
        $r = $executor->receberMensagem([
            'canal' => 'email', 'identificador' => $e['de_email'], 'nome' => $e['de_nome'] ?: null, 'assunto' => $e['assunto'] ?: null,
            'id_externo' => 'em:' . ($e['message_id'] ?? 'uid-' . $uid . '-' . substr(hash('sha256', $e['de_email'] . $e['data'] . $e['assunto']), 0, 16)),
            'tipo' => 'texto', 'texto' => $e['texto'], 'midia' => null, 'data_hora' => $e['data'],
        ]);
        return $r->ok && empty($r->registro['duplicada']);
    }
}
