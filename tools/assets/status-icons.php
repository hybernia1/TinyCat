<?php
declare(strict_types=1);
namespace TinyCat\Tools\Assets;

/** Build decorative CSS masks from the canonical SVG sprite, without runtime tooling. */
final class StatusIcons
{
    public static function css(string $sprite): string
    {
        $document = new \DOMDocument();
        if (!$document->loadXML($sprite, LIBXML_NONET)) { throw new \RuntimeException('Invalid icon sprite.'); }
        $xpath = new \DOMXPath($document);
        $css = "/* BEGIN GENERATED STATUS ICONS: php tools/assets/status-icons.php --write */\n:root {\n";
        foreach (['info' => 'info', 'success' => 'check-circle', 'warning' => 'alert', 'danger' => 'x-circle'] as $tone => $id) {
            $symbol = $xpath->query('//*[local-name()="symbol" and @id="' . $id . '"]')->item(0);
            if (!$symbol instanceof \DOMElement) { throw new \RuntimeException('Missing status icon: ' . $id); }
            $image = new \DOMDocument();
            $svg = $image->createElementNS('http://www.w3.org/2000/svg', 'svg');
            foreach ($symbol->attributes as $attribute) { if ($attribute->name !== 'id') { $svg->setAttribute($attribute->name, $attribute->value); } }
            foreach ($symbol->childNodes as $child) { $svg->appendChild($image->importNode($child, true)); }
            $image->appendChild($svg);
            $markup = str_replace('currentColor', 'black', (string) $image->saveXML($svg));
            $markup = (string) preg_replace('/>\s+</', '><', $markup);
            $css .= '  --status-icon-' . $tone . ': url("data:image/svg+xml,' . rawurlencode($markup) . '");' . "\n";
        }
        return $css . "}\n/* END GENERATED STATUS ICONS */\n";
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $root = dirname(__DIR__, 2);
    $path = $root . '/App/Core/Ui/assets/styles/tokens.css';
    $css = StatusIcons::css((string) file_get_contents($root . '/App/Core/Ui/assets/icons.svg'));
    $source = (string) file_get_contents($path);
    if (in_array('--write', $argv, true)) {
        $source = (string) preg_replace('~/\* BEGIN GENERATED STATUS ICONS:.*?/\* END GENERATED STATUS ICONS \*/\s*~s', '', $source);
        file_put_contents($path, rtrim($source) . "\n\n" . $css);
    } elseif (!str_contains(str_replace("\r\n", "\n", $source), $css)) {
        fwrite(STDERR, "Regenerate status icons: php tools/assets/status-icons.php --write\n"); exit(1);
    }
    echo "PASS canonical SVG status masks\n";
}
