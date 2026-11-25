<?php
declare(strict_types=1);
namespace TinyCat\Core\Discovery;

use TinyCat\Core\Modules\Registrations;
use TinyCat\Core\Ui\Site;
use TinyCat\Http\{Document, Web};

/** Session-independent oEmbed transport; providers resolve only local public content. */
final class Oembed
{
    public static function discoveryUrl(string $url, string $format = 'json'): string
    {
        if (!in_array($format, ['json', 'xml'], true)) { throw new \InvalidArgumentException('Unsupported oEmbed format.'); }
        return \TinyCat\Support\LocalUrls::absolute('/oembed') . '?' . http_build_query(['url' => $url, 'format' => $format]);
    }

    public static function localPath(string $url): ?string
    {
        $base = parse_url(\TinyCat\Support\LocalUrls::absolute('/')); $parts = parse_url($url);
        if (!is_array($base) || !is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || strtolower($parts['host'] ?? '') !== strtolower($base['host'] ?? '') || $parts['scheme'] !== ($base['scheme'] ?? '')
            || ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) !== ($base['port'] ?? ($base['scheme'] === 'https' ? 443 : 80))) { return null; }
        $basePath = rtrim($base['path'] ?? '', '/'); $path = $parts['path'] ?? '';
        if ($basePath !== '' && !str_starts_with($path, $basePath . '/')) { return null; }
        return \TinyCat\Http\LocaleRouting::split(substr($path, strlen($basePath)))['path'];
    }

    public static function respond(): void
    {
        \TinyCat\Support\Localization::locale(\TinyCat\Support\Localization::defaultLocale());
        $format = Web::get('format', 'json');
        if (!is_string($format) || !in_array($format, ['json', 'xml'], true)) { Document::jsonError(501, 'Unsupported format.'); return; }
        $url = Web::get('url'); $width = self::dimension('maxwidth', 550); $height = self::dimension('maxheight', 600);
        if (!is_string($url) || $url === '' || $width === null || $height === null) { Document::jsonError(400, 'Invalid oEmbed parameters.'); return; }
        $path = self::localPath($url); $data = null;
        if ($path !== null) { foreach (Registrations::current()->entries->embeds as $source) { $data = ($source->resolve)($path, $width, $height); if ($data !== null) { break; } } }
        if ($data === null) { Document::jsonError(404, 'Content not found.'); return; }
        $data += ['type' => 'rich', 'provider_name' => Site::site_name(), 'provider_url' => \TinyCat\Support\LocalUrls::absolute('/'), 'width' => $width, 'height' => $height, 'cache_age' => 0];
        \TinyCat\Core\Ui\Oembed::respond($data, $format);
    }

    private static function dimension(string $name, int $maximum): ?int
    {
        $value = filter_var(Web::get($name, $maximum), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : min($maximum, $value);
    }

}
