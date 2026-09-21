<?php

declare(strict_types=1);

namespace App\Services\Canais;

/**
 * E-mail em texto: lê uma mensagem recebida (cabeçalhos, multipart, base64/quoted-printable, charsets) e monta uma
 * mensagem simples para envio. Só o necessário para a caixa de entrada: texto e cabeçalhos, sem anexos.
 */
final class Mime
{
    /**
     * @return array{message_id:?string,de_email:string,de_nome:string,para:string,assunto:string,data:string,in_reply_to:?string,texto:string,automatica:bool}
     */
    public static function ler(string $bruto): array
    {
        [$cabecalhos, $corpo] = self::separar($bruto);
        [$plano, $html] = self::partes($cabecalhos, $corpo);
        $texto = $plano !== '' ? $plano : self::htmlParaTexto($html);

        [$deEmail, $deNome] = self::endereco((string) ($cabecalhos['from'] ?? ''));
        $para = self::endereco((string) ($cabecalhos['to'] ?? ''))[0];
        $data = strtotime((string) ($cabecalhos['date'] ?? ''));
        $de = mb_strtolower($deEmail);

        return [
            'message_id' => isset($cabecalhos['message-id']) ? trim($cabecalhos['message-id'], " <>\t") : null,
            'de_email' => $de,
            'de_nome' => $deNome,
            'para' => mb_strtolower($para),
            'assunto' => trim(self::decodificarCabecalho((string) ($cabecalhos['subject'] ?? ''))),
            'data' => date('Y-m-d H:i:s', $data !== false ? $data : time()),
            'in_reply_to' => isset($cabecalhos['in-reply-to']) ? trim($cabecalhos['in-reply-to'], " <>\t") : null,
            'texto' => self::semCitacao($texto),
            'automatica' => self::ehAutomatica($cabecalhos, $de),
        ];
    }

    /**
     * Monta uma mensagem de texto UTF-8 (corpo em base64) pronta para o DATA do SMTP.
     * @return array{bruto:string,message_id:string}
     */
    public static function montar(string $deEmail, string $deNome, string $para, string $assunto, string $texto, ?string $respondeA = null): array
    {
        $dominio = substr(strrchr($deEmail, '@') ?: '@localhost', 1);
        $messageId = bin2hex(random_bytes(12)) . '@' . $dominio;
        $codificar = static fn (string $s): string => preg_match('/[^\x20-\x7E]/', $s) === 1 ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
        $cab = [
            'Date: ' . date('r'),
            'From: ' . ($deNome !== '' ? $codificar($deNome) . ' <' . $deEmail . '>' : $deEmail),
            'To: ' . $para,
            'Subject: ' . $codificar(str_replace(["\r", "\n"], ' ', $assunto)),
            'Message-ID: <' . $messageId . '>',
        ];
        if ($respondeA !== null && $respondeA !== '') {
            $cab[] = 'In-Reply-To: <' . trim($respondeA, '<>') . '>';
            $cab[] = 'References: <' . trim($respondeA, '<>') . '>';
        }
        $cab[] = 'MIME-Version: 1.0';
        $cab[] = 'Content-Type: text/plain; charset=UTF-8';
        $cab[] = 'Content-Transfer-Encoding: base64';
        $corpo = chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $texto)), 76, "\r\n");
        return ['bruto' => implode("\r\n", $cab) . "\r\n\r\n" . $corpo, 'message_id' => $messageId];
    }

    /** Remove a citação da mensagem anterior ("Em ... escreveu:", linhas com ">") e a assinatura padrão de aplicativos. */
    public static function semCitacao(string $texto): string
    {
        $linhas = preg_split('/\R/u', str_replace("\r\n", "\n", $texto)) ?: [];
        $saida = [];
        foreach ($linhas as $linha) {
            if (preg_match('/^\s*(Em .{5,120} escreveu:|On .{5,120} wrote:|-{2,}\s*(Original Message|Mensagem original)\s*-{2,}|De:\s.+|From:\s.+)\s*$/iu', $linha) === 1) {
                break;
            }
            if (str_starts_with(ltrim($linha), '>')) {
                continue;
            }
            $saida[] = $linha;
        }
        return trim(implode("\n", $saida)) !== '' ? trim(implode("\n", $saida)) : trim($texto);
    }

    // ---- Interno --------------------------------------------------------------------------

    /** @return array{0:array<string,string>,1:string} cabeçalhos (nome em minúsculas, já desdobrados) e corpo */
    private static function separar(string $bruto): array
    {
        $bruto = str_replace("\r\n", "\n", $bruto);
        $pos = strpos($bruto, "\n\n");
        $bloco = $pos === false ? $bruto : substr($bruto, 0, $pos);
        $corpo = $pos === false ? '' : substr($bruto, $pos + 2);
        $bloco = preg_replace("/\n[ \t]+/", ' ', $bloco) ?? $bloco;
        $cabecalhos = [];
        foreach (explode("\n", $bloco) as $linha) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $linha, $m) === 1) {
                $cabecalhos[strtolower($m[1])] ??= $m[2]; // o primeiro vale (Received etc. repetem)
            }
        }
        return [$cabecalhos, $corpo];
    }

    /** @return array{0:string,1:string} texto simples e HTML da parte (recursivo em multipart) */
    private static function partes(array $cabecalhos, string $corpo): array
    {
        $tipo = strtolower((string) ($cabecalhos['content-type'] ?? 'text/plain'));
        if (str_starts_with($tipo, 'multipart/') && preg_match('/boundary="?([^";\s]+)"?/i', (string) ($cabecalhos['content-type'] ?? ''), $m) === 1) {
            $plano = $html = '';
            foreach (explode('--' . $m[1], $corpo) as $parte) {
                $parte = ltrim($parte, "\n");
                if ($parte === '' || str_starts_with($parte, '--')) {
                    continue;
                }
                [$ch, $co] = self::separar($parte);
                if (str_contains(strtolower((string) ($ch['content-disposition'] ?? '')), 'attachment')) {
                    continue;
                }
                [$p, $h] = self::partes($ch, $co);
                $plano = $plano !== '' ? $plano : $p;
                $html = $html !== '' ? $html : $h;
            }
            return [$plano, $html];
        }
        $texto = self::decodificarCorpo($corpo, $cabecalhos);
        if (str_starts_with($tipo, 'text/html')) {
            return ['', $texto];
        }
        return str_starts_with($tipo, 'text/') ? [$texto, ''] : ['', ''];
    }

    private static function decodificarCorpo(string $corpo, array $cabecalhos): string
    {
        $corpo = match (strtolower(trim((string) ($cabecalhos['content-transfer-encoding'] ?? '7bit')))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $corpo) ?? '', false),
            'quoted-printable' => quoted_printable_decode($corpo),
            default => $corpo,
        };
        $charset = 'UTF-8';
        if (preg_match('/charset="?([A-Za-z0-9_.:-]+)"?/i', (string) ($cabecalhos['content-type'] ?? ''), $m) === 1) {
            $charset = strtoupper($m[1]);
        }
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            try {
                $corpo = mb_convert_encoding($corpo, 'UTF-8', $charset);
            } catch (\ValueError) {
                // charset desconhecido: segue como veio
            }
        }
        return mb_check_encoding($corpo, 'UTF-8') ? $corpo : (string) mb_convert_encoding($corpo, 'UTF-8', 'ISO-8859-1');
    }

    private static function htmlParaTexto(string $html): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace(['~<(script|style)\b.*?</\1>~is', '~<br\s*/?>|</(p|div|li|tr|h[1-6])>~i'], ['', "\n"], $html) ?? $html;
        $texto = str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim(preg_replace(["/[ \t]+/", "/\n{3,}/"], [' ', "\n\n"], $texto) ?? $texto);
    }

    /** Palavras codificadas (=?charset?B|Q?...?=) de um cabeçalho, sem depender de iconv. */
    private static function decodificarCabecalho(string $valor): string
    {
        // Espaço entre duas palavras codificadas seguidas não conta.
        $valor = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $valor) ?? $valor;
        return (string) preg_replace_callback('/=\?([^?\s]+)\?([BbQq])\?([^?]*)\?=/', static function (array $m): string {
            $bytes = strtoupper($m[2]) === 'B' ? (string) base64_decode($m[3], false) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
            $charset = strtoupper(explode('*', $m[1])[0]);
            if ($charset === 'UTF-8' || $charset === 'US-ASCII') {
                return $bytes;
            }
            try {
                return mb_convert_encoding($bytes, 'UTF-8', $charset);
            } catch (\ValueError) {
                return $bytes;
            }
        }, $valor);
    }

    /** @return array{0:string,1:string} [e-mail, nome] */
    private static function endereco(string $bruto): array
    {
        $bruto = self::decodificarCabecalho($bruto);
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>/', $bruto, $m) === 1) {
            return [trim($m[2]), trim($m[1])];
        }
        return [trim(explode(',', $bruto)[0]), ''];
    }

    /** Respostas automáticas, listas e notificações: não entram na caixa de entrada. */
    private static function ehAutomatica(array $cabecalhos, string $de): bool
    {
        $auto = strtolower(trim((string) ($cabecalhos['auto-submitted'] ?? 'no')));
        if ($auto !== '' && $auto !== 'no') {
            return true;
        }
        if (preg_match('/bulk|list|junk/i', (string) ($cabecalhos['precedence'] ?? '')) === 1 || isset($cabecalhos['list-id'])) {
            return true;
        }
        return preg_match('/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster)@/i', $de) === 1;
    }
}
