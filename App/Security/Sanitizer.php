<?php
declare(strict_types=1);

namespace TinyCat\Security;

use DOMText;

use DOMDocument;
use DOMNode;
use DOMElement;

final class Sanitizer
{
    public static function sanitize_html(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            return \TinyCat\Support\Html::e(\TinyCat\Support\Text::strip_html_tags_preserving_text($value));
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><div id="tinycat-html-root">' . $value . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $root = $loaded ? $document->getElementById('tinycat-html-root') : null;
        if (!$root instanceof DOMElement) {
            return \TinyCat\Support\Html::e(\TinyCat\Support\Text::strip_html_tags_preserving_text($value));
        }

        $html = '';
        foreach ($root->childNodes as $node) {
            $html .= \TinyCat\Security\Sanitizer::sanitize_html_node($node);
        }

        return $html;
    }

    public static function sanitize_html_node(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return \TinyCat\Support\Html::e((string) $node->nodeValue);
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        $discardContents = [
            'base', 'button', 'embed', 'form', 'iframe', 'input', 'link', 'math', 'meta',
            'object', 'option', 'script', 'select', 'style', 'svg', 'template', 'textarea', 'title',
        ];
        if (in_array($tag, $discardContents, true)) {
            return '';
        }

        $contents = '';
        foreach ($node->childNodes as $child) {
            $contents .= \TinyCat\Security\Sanitizer::sanitize_html_node($child);
        }

        if ($tag === 'a') {
            $href = \TinyCat\Security\Sanitizer::sanitize_html_url((string) $node->getAttribute('href'));
            if ($href === '') {
                return $contents;
            }

            $title = trim((string) $node->getAttribute('title'));
            $attributes = ' href="' . \TinyCat\Support\Html::e($href) . '"';
            if ($title !== '') {
                $attributes .= ' title="' . \TinyCat\Support\Html::e($title) . '"';
            }
            if (strtolower(trim((string) $node->getAttribute('target'))) === '_blank') {
                $attributes .= ' target="_blank" rel="noopener noreferrer"';
            }

            return '<a' . $attributes . '>' . $contents . '</a>';
        }

        if (in_array($tag, ['br', 'hr'], true)) {
            return '<' . $tag . '>';
        }

        if (!in_array($tag, [
            'blockquote', 'code', 'em', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ol', 'p', 'pre', 'strong', 'ul',
        ], true)) {
            return $contents;
        }

        return '<' . $tag . '>' . $contents . '</' . $tag . '>';
    }

    public static function sanitize_html_url(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x20\x7f]+/u', '', $value);
        if ($value === '' || str_starts_with($value, '//') || str_starts_with($value, '\\')) {
            return '';
        }

        if (str_starts_with($value, '#')) {
            return $value;
        }
        if (str_starts_with($value, '/')) {
            return str_contains($value, '\\') ? '' : $value;
        }

        $parts = parse_url($value);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === '') {
            return !str_contains($value, ':') ? $value : '';
        }
        if (in_array($scheme, ['http', 'https'], true)) {
            return (string) ($parts['host'] ?? '') !== '' ? $value : '';
        }
        if ($scheme === 'mailto') {
            $address = substr($value, strlen('mailto:'));
            return filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
        }

        return '';
    }
}
