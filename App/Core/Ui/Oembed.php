<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** Present owner-supplied oEmbed metadata without loading content or installation state. */
final class Oembed
{
    /** @param array<string, mixed> $data */
    public static function render(array $data, string $format = 'json'): string
    {
        $data = ['version' => '1.0'] + $data;
        return match ($format) {
            'json' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'xml' => '<?xml version="1.0" encoding="UTF-8"?><oembed>' . \TinyCat\Support\Xml::elements($data) . '</oembed>',
            default => throw new \InvalidArgumentException('Unsupported oEmbed format.'),
        };
    }

    /** @param array<string, mixed> $data */
    public static function respond(array $data, string $format = 'json'): void
    {
        \TinyCat\Http\Document::respond(self::render($data, $format), $format === 'xml' ? 'text/xml' : 'application/json');
    }
}
