<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * HTML de documentos (contratos): sanitização por lista de permissão. O conteúdo pode vir do operador,
 * de modelos preenchidos com dados de formulários públicos ou de agentes de IA, então nunca é confiável.
 * Sem a extensão dom, todo o conteúdo é tratado como texto (escapado).
 */
final class Html
{
    /** tag => atributos permitidos */
    private const TAGS = [
        'p' => [], 'br' => [], 'hr' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 'sub' => [], 'sup' => [], 'small' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'span' => [], 'div' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['colspan', 'rowspan', 'align'], 'td' => ['colspan', 'rowspan', 'align'],
        'a' => ['href', 'title'],
    ];

    /** Elementos removidos com todo o conteúdo. */
    private const DESCARTAR = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math', 'noscript', 'template', 'head', 'title'];

    public static function sanitizar(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (!class_exists(DOMDocument::class)) {
            return self::textoParaHtml($html);
        }

        $anterior = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="raiz">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $raiz = $doc->getElementById('raiz') ?? $doc->documentElement;
        if ($raiz === null) {
            return self::textoParaHtml($html);
        }
        self::limpar($raiz);

        $saida = '';
        foreach ($raiz->childNodes as $filho) {
            $saida .= (string) $doc->saveHTML($filho);
        }
        return trim($saida);
    }

    private static function limpar(DOMNode $no): void
    {
        foreach (iterator_to_array($no->childNodes) as $filho) {
            if ($filho instanceof DOMElement) {
                $tag = strtolower($filho->tagName);
                if (in_array($tag, self::DESCARTAR, true)) {
                    $no->removeChild($filho);
                    continue;
                }
                self::limpar($filho);
                if (!isset(self::TAGS[$tag])) {
                    // Tag desconhecida: mantém só o conteúdo.
                    while ($filho->firstChild !== null) {
                        $no->insertBefore($filho->firstChild, $filho);
                    }
                    $no->removeChild($filho);
                    continue;
                }
                self::limparAtributos($filho, self::TAGS[$tag]);
            } elseif ($filho->nodeType === XML_COMMENT_NODE || $filho->nodeType === XML_PI_NODE || $filho->nodeType === XML_CDATA_SECTION_NODE) {
                $no->removeChild($filho);
            }
        }
    }

    private static function limparAtributos(DOMElement $el, array $permitidos): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $nome = strtolower($attr->name);
            if (!in_array($nome, $permitidos, true)) {
                $el->removeAttribute($attr->name);
                continue;
            }
            if ($nome === 'href' && !self::urlSegura($attr->value)) {
                $el->removeAttribute($attr->name);
            } elseif (in_array($nome, ['colspan', 'rowspan'], true) && !ctype_digit($attr->value)) {
                $el->removeAttribute($attr->name);
            } elseif ($nome === 'align' && !in_array(strtolower($attr->value), ['left', 'right', 'center', 'justify'], true)) {
                $el->removeAttribute($attr->name);
            }
        }
        if (strtolower($el->tagName) === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function urlSegura(string $url): bool
    {
        $url = trim(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');
        return (bool) preg_match('#^(https?://|mailto:|tel:|/|\#)#i', $url);
    }

    /** Texto simples → HTML com parágrafos e quebras de linha (tudo escapado). */
    public static function textoParaHtml(string $texto): string
    {
        $texto = trim(str_replace(["\r\n", "\r"], "\n", $texto));
        if ($texto === '') {
            return '';
        }
        $paragrafos = preg_split('/\n{2,}/', $texto) ?: [];
        return implode('', array_map(static fn (string $p): string => '<p>' . nl2br(e($p), false) . '</p>', $paragrafos));
    }
}
