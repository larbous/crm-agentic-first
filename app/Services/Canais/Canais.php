<?php

declare(strict_types=1);

namespace App\Services\Canais;

use App\Core\Config;

/**
 * Configuração e utilidades dos canais da caixa de entrada (Fase 14). Credenciais só em config.local.php:
 *   canais.meta      app_secret, verify_token, versao (padrão v21.0)
 *   canais.whatsapp  token, phone_number_id
 *   canais.instagram token, conta_id
 *   canais.email     imap_host, imap_porta (993), imap_seguranca (ssl|tls|""), usuario, senha,
 *                    smtp_host, smtp_porta (465), smtp_seguranca (ssl|tls|""), smtp_usuario, smtp_senha, de_email, de_nome,
 *                    importar_desconhecidos (false: só remetentes que já são contatos ou empresas)
 */
final class Canais
{
    public const ROTULOS = ['whatsapp' => 'WhatsApp', 'instagram' => 'Instagram', 'email' => 'E-mail'];
    /** Tipo de atividade da timeline de cada canal. */
    public const TIPO_ATIVIDADE = ['whatsapp' => 'whatsapp', 'instagram' => 'instagram', 'email' => 'email'];
    /** Resposta livre só dentro desta janela depois da última mensagem do cliente (WhatsApp e Instagram). */
    public const JANELA_HORAS = 24;

    /** @var (callable(string,array):Conexao)|null fábrica de conexões (testes) */
    private static $fabrica = null;

    public static function definirFabricaConexao(?callable $fabrica): void
    {
        self::$fabrica = $fabrica;
    }

    public static function config(string $canal): array
    {
        $c = Config::obter("canais.{$canal}", []);
        return is_array($c) ? $c : [];
    }

    public static function valor(string $canal, string $chave, mixed $padrao = ''): mixed
    {
        return self::config($canal)[$chave] ?? $padrao;
    }

    /** O canal tem tudo o que precisa para receber e enviar? */
    public static function ativo(string $canal): bool
    {
        $c = self::config($canal);
        $tem = static fn (string ...$chaves): bool => array_reduce($chaves, static fn (bool $ok, string $k): bool => $ok && trim((string) ($c[$k] ?? '')) !== '', true);
        return match ($canal) {
            'whatsapp' => $tem('token', 'phone_number_id'),
            'instagram' => $tem('token', 'conta_id'),
            'email' => $tem('imap_host', 'usuario', 'senha', 'smtp_host', 'de_email'),
            default => false,
        };
    }

    public static function temJanela(string $canal): bool
    {
        return in_array($canal, ['whatsapp', 'instagram'], true);
    }

    /** Conexão IMAP ou SMTP conforme a configuração (ou a fábrica de testes). */
    public static function conexao(string $tipo): Conexao
    {
        $c = self::config('email');
        if (self::$fabrica !== null) {
            return (self::$fabrica)($tipo, $c);
        }
        return $tipo === 'imap'
            ? new SocketConexao((string) $c['imap_host'], (int) ($c['imap_porta'] ?? 993), (string) ($c['imap_seguranca'] ?? 'ssl'))
            : new SocketConexao((string) $c['smtp_host'], (int) ($c['smtp_porta'] ?? 465), (string) ($c['smtp_seguranca'] ?? 'ssl'));
    }

    /** Texto para timeline e prévia: o texto da mensagem ou um rótulo do tipo de mídia. */
    public static function descricao(string $tipo, ?string $texto): string
    {
        $rotulos = ['audio' => '[Áudio]', 'imagem' => '[Imagem]', 'video' => '[Vídeo]', 'documento' => '[Documento]', 'sticker' => '[Figurinha]', 'localizacao' => '[Localização]', 'outro' => '[Mensagem não suportada]'];
        $texto = trim((string) $texto);
        if ($tipo === 'texto') {
            return $texto;
        }
        return ($rotulos[$tipo] ?? '[Mídia]') . ($texto !== '' ? ' ' . $texto : '');
    }

    /** Variações de um número brasileiro (com/sem DDI 55 e com/sem o 9 depois do DDD), para casar com o cadastro. @return list<string> */
    public static function variantesTelefone(string $digitos): array
    {
        $d = preg_replace('/\D/', '', $digitos) ?? '';
        $d = preg_replace('/^55(?=\d{10,11}$)/', '', $d) ?? $d;
        $variantes = [$d];
        if (strlen($d) === 10) {
            $variantes[] = substr($d, 0, 2) . '9' . substr($d, 2);
        } elseif (strlen($d) === 11 && $d[2] === '9') {
            $variantes[] = substr($d, 0, 2) . substr($d, 3);
        }
        return array_values(array_unique(array_filter($variantes, static fn (string $v): bool => strlen($v) >= 8)));
    }
}
