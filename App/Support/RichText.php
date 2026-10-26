<?php
declare(strict_types=1);
namespace TinyCat\Support;
final class RichText
{
    /** @return list<array<string, mixed>> */
    public static function nodes(string $source): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $document->loadHTML('<?xml encoding="UTF-8"><body>' . $source . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $body = $document->getElementsByTagName('body')->item(0);
        return $body === null ? [] : self::children($body);
    }
    /** @return list<array<string, mixed>> */
    private static function children(\DOMNode $parent): array
    {
        $result = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMText) { $result[] = ['type' => 'text', 'text' => $node->textContent]; continue; }
            if (!$node instanceof \DOMElement || in_array(strtolower($node->tagName), ['script','style','iframe','object'], true)) { continue; }
            $tag = strtolower($node->tagName);
            $children = self::children($node);
            if (!in_array($tag, ['p','br','strong','em','a','ul','ol','li','span','div','small','b','i'], true)) { array_push($result, ...$children); continue; }
            $attributes = [];
            if ($tag === 'a') { $href = \TinyCat\Security\Sanitizer::sanitize_html_url($node->getAttribute('href')); if ($href !== '') { $attributes['href'] = $href; $attributes['rel'] = 'noopener noreferrer'; } }
            $result[] = ['type' => 'element', 'name' => $tag, 'attributes' => $attributes, 'children' => $children];
        }
        return $result;
    }
}
