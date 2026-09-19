<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\Repositorios;
use App\Services\ActionExecutor;

/**
 * Upload seguro (MIME real via finfo, limite de tamanho, nome aleatório em /storage/uploads)
 * e download somente por este controller autenticado.
 */
final class AnexoController
{
    private const LIMITE_BYTES = 10 * 1024 * 1024;

    private const MIMES = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain', 'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /** Documentos ZIP-based que o finfo pode reportar como application/zip. */
    private const EXT_ZIP_OK = ['docx', 'xlsx', 'pptx', 'odt', 'ods'];

    public function enviar(): Response
    {
        $voltar = caminho_seguro($_POST['voltar'] ?? null, url('/'));
        $erro = $this->validarEnvio($_FILES['arquivo'] ?? null, $mime, $original);
        if ($erro !== null) {
            Session::flash('error', $erro);
            return Response::redirecionar($voltar);
        }

        $pasta = rtrim((string) Config::obter('caminhos.uploads'), '/\\') . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
        if (!is_dir($pasta) && !mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            Session::flash('error', 'Não foi possível salvar o arquivo.');
            return Response::redirecionar($voltar);
        }
        $nome = bin2hex(random_bytes(16));
        $destino = $pasta . DIRECTORY_SEPARATOR . $nome;
        if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $destino)) {
            Session::flash('error', 'Não foi possível salvar o arquivo.');
            return Response::redirecionar($voltar);
        }

        $r = (new ActionExecutor())->criar('anexos', [
            'entidade'      => (string) ($_POST['entidade'] ?? ''),
            'registro_id'   => (int) ($_POST['registro_id'] ?? 0),
            'nome_original' => $original,
            'caminho'       => date('Y') . '/' . date('m') . '/' . $nome,
            'mime'          => $mime,
            'tamanho'       => (int) filesize($destino),
        ], 'humano');

        if (!$r->ok) {
            @unlink($destino);
            Session::flash('error', $r->mensagem);
        } else {
            Session::flash('success', "Anexo \"{$original}\" enviado.");
        }
        return Response::redirecionar($voltar);
    }

    public function baixar(array $p): Response
    {
        $anexo = Repositorios::anexos()->encontrar((int) $p['id']);
        if ($anexo === null) {
            return Response::texto('Anexo não encontrado', 404);
        }
        $raiz = realpath((string) Config::obter('caminhos.uploads'));
        $arquivo = $raiz !== false ? realpath($raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $anexo['caminho'])) : false;
        if ($arquivo === false || !str_starts_with($arquivo, $raiz . DIRECTORY_SEPARATOR) || !is_file($arquivo)) {
            return Response::texto('Arquivo indisponível', 404);
        }

        $nome = preg_replace('/[\x00-\x1f"\\\\\/]+/', '_', $anexo['nome_original']) ?: 'arquivo';
        return new Response((string) file_get_contents($arquivo), 200, [
            'Content-Type'           => in_array($anexo['mime'], self::MIMES, true) ? $anexo['mime'] : 'application/octet-stream',
            'Content-Disposition'    => 'attachment; filename="' . $nome . '"; filename*=UTF-8\'\'' . rawurlencode($anexo['nome_original']),
            'Content-Length'         => (string) filesize($arquivo),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=0',
        ]);
    }

    public function arquivar(array $p): Response
    {
        $r = (new ActionExecutor())->arquivar('anexos', (int) $p['id'], 'humano');
        Session::flash($r->ok ? 'success' : 'error', $r->ok ? 'Anexo arquivado.' : $r->mensagem);
        return Response::redirecionar(caminho_seguro($_POST['voltar'] ?? null, url('/')));
    }

    /** Valida o upload; devolve mensagem de erro ou null (e preenche $mime e $original). */
    private function validarEnvio(mixed $arquivo, ?string &$mime, ?string &$original): ?string
    {
        if (!is_array($arquivo) || !isset($arquivo['error'])) {
            return 'Nenhum arquivo enviado.';
        }
        if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'O arquivo excede o tamanho máximo permitido (10 MB).';
        }
        if ($arquivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($arquivo['tmp_name'])) {
            return 'Falha no envio do arquivo. Tente novamente.';
        }
        if ($arquivo['size'] > self::LIMITE_BYTES || $arquivo['size'] <= 0) {
            return 'O arquivo deve ter entre 1 byte e 10 MB.';
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
        $original = mb_substr(basename(str_replace('\\', '/', (string) $arquivo['name'])), 0, 255);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $permitido = in_array($mime, self::MIMES, true) || ($mime === 'application/zip' && in_array($ext, self::EXT_ZIP_OK, true));
        if (!$permitido) {
            return 'Tipo de arquivo não permitido.';
        }
        if ($mime === 'application/zip') {
            $mime = 'application/octet-stream';
        }
        return null;
    }
}
