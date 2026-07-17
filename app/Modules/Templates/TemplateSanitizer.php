<?php

declare(strict_types=1);

namespace App\Modules\Templates;

use DOMDocument;
use DOMElement;
use DOMNode;

final class TemplateSanitizer
{
    private const MAX_HTML_BYTES = 262144;
    private const MAX_CSS_BYTES = 131072;
    private const ALLOWED_TAGS = [
        'a', 'article', 'aside', 'b', 'blockquote', 'br', 'caption', 'code', 'div', 'em',
        'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'i', 'img', 'li',
        'main', 'ol', 'p', 'pre', 'section', 'small', 'span', 'strong', 'table', 'tbody',
        'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];
    private const DROP_WITH_CONTENT = [
        'applet', 'audio', 'base', 'button', 'canvas', 'embed', 'form', 'frame', 'frameset',
        'iframe', 'input', 'link', 'meta', 'noscript', 'object', 'option', 'script', 'select',
        'source', 'style', 'svg', 'textarea', 'video',
    ];
    private const ALLOWED_ATTRIBUTES = [
        'align', 'alt', 'class', 'colspan', 'height', 'href', 'id', 'rel', 'role', 'rowspan',
        'src', 'style', 'target', 'title', 'valign', 'width',
    ];

    public function sanitizeHtml(string $html): string
    {
        $html = trim(substr($html, 0, self::MAX_HTML_BYTES));

        if ($html === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<div id="mytakii-template-root">' . $html . '</div>';
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return '';
        }

        $root = $document->getElementById('mytakii-template-root');

        if (!$root instanceof DOMElement) {
            return '';
        }

        foreach (iterator_to_array($root->childNodes) as $child) {
            $this->sanitizeNode($child);
        }

        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result);
    }

    public function sanitizeCss(string $css): string
    {
        $css = substr($css, 0, self::MAX_CSS_BYTES);
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $css = preg_replace('/@(?:import|charset|namespace)[^;]*;?/i', '', $css) ?? '';
        $css = preg_replace('/(?:expression|behavior|-moz-binding)\s*:[^;}]*;?/i', '', $css) ?? '';
        $css = preg_replace('/expression\s*\([^)]*\)/i', '', $css) ?? '';
        $css = preg_replace('/url\s*\([^)]*\)/i', 'none', $css) ?? '';
        $css = str_ireplace(['</style', '<script', '</script'], '', $css);

        return trim($css);
    }

    private function sanitizeNode(DOMNode $node): void
    {
        if (!$node instanceof DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            $this->unwrap($node);

            return;
        }

        $attributes = [];

        foreach ($node->attributes ?? [] as $attribute) {
            $attributes[] = strtolower($attribute->name);
        }

        foreach ($attributes as $attributeName) {
            $value = (string) $node->getAttribute($attributeName);
            $isDataAttribute = str_starts_with($attributeName, 'data-');
            $isAriaAttribute = str_starts_with($attributeName, 'aria-');

            if (str_starts_with($attributeName, 'on')
                || (!$isDataAttribute && !$isAriaAttribute && !in_array($attributeName, self::ALLOWED_ATTRIBUTES, true))) {
                $node->removeAttribute($attributeName);

                continue;
            }

            if (in_array($attributeName, ['href', 'src'], true) && !$this->safeUrl($value, $attributeName === 'src')) {
                $node->removeAttribute($attributeName);

                continue;
            }

            if ($attributeName === 'style') {
                $style = $this->sanitizeCss($value);

                if ($style === '') {
                    $node->removeAttribute('style');
                } else {
                    $node->setAttribute('style', $style);
                }
            }

            if ($attributeName === 'target' && $value !== '_blank') {
                $node->removeAttribute('target');
            }
        }

        if ($tag === 'a' && $node->getAttribute('target') === '_blank') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->sanitizeNode($child);
        }
    }

    private function safeUrl(string $url, bool $imageSource): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $compact = preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '';

        if ($compact === '') {
            return false;
        }

        if (preg_match('/\A{{[a-zA-Z0-9_.-]+}}\z/', $compact) === 1) {
            return true;
        }

        if ($imageSource && preg_match('#\Adata:image/(?:png|jpe?g|gif|webp);base64,[a-z0-9+/=]+\z#i', $compact) === 1) {
            return true;
        }

        if (str_starts_with($compact, '/') || (!$imageSource && str_starts_with($compact, '#'))) {
            return !str_starts_with($compact, '//');
        }

        $scheme = strtolower((string) parse_url($compact, PHP_URL_SCHEME));

        return $imageSource
            ? in_array($scheme, ['http', 'https', 'cid'], true)
            : in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    private function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;

        if ($parent === null) {
            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }
}
