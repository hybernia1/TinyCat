<?php
declare(strict_types=1);
namespace TinyCat\Core\Discovery;

use TinyCat\Core\Modules\Registrations;
use TinyCat\Core\Ui\Site;
use TinyCat\Http\{Document, Request, Web};

/** Session-independent oEmbed transport; providers resolve only local public content. */
final class Oembed
{
    public static function localPath(string $url): ?string
    {
        $base = parse_url(Site::absolute_url('/')); $parts = parse_url($url);
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
        \TinyCat\Support\Localization::locale((string) \TinyCat\Core\Runtime\Startup::option('i18n.locale', \TinyCat\Core\Runtime\Startup::option('install.locale', 'en')));
        $format = Web::get('format', 'json');
        if (!is_string($format) || !in_array($format, ['json', 'xml'], true)) { self::error(501, 'Unsupported format.'); return; }
        $url = Web::get('url'); $width = self::dimension('maxwidth', 550); $height = self::dimension('maxheight', 600);
        if (!is_string($url) || $url === '' || $width === null || $height === null) { self::error(400, 'Invalid oEmbed parameters.'); return; }
        $path = self::localPath($url); $data = null;
        if ($path !== null) { foreach (Registrations::current()->entries->embeds as $source) { $data = ($source->resolve)($path, $width, $height); if ($data !== null) { break; } } }
        if ($data === null) { self::error(404, 'Content not found.'); return; }
        $data = ['version' => '1.0', 'type' => 'rich', 'provider_name' => Site::site_name(), 'provider_url' => Site::absolute_url('/'), 'width' => $width, 'height' => $height, 'cache_age' => 0] + $data;
        if ($format === 'xml') {
            $body = '<?xml version="1.0" encoding="UTF-8"?><oembed>';
            foreach ($data as $key => $value) { $body .= '<' . $key . '>' . \TinyCat\Support\Xml::escape((string) $value) . '</' . $key . '>'; }
            Document::respond($body . '</oembed>', 'text/xml', 0);
        } else { Document::respond(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'application/json', 0); }
    }

    private static function dimension(string $name, int $maximum): ?int
    {
        $value = filter_var(Web::get($name, $maximum), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : min($maximum, $value);
    }

    private static function error(int $status, string $message): void
    {
        http_response_code($status); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
        if (Request::current()->method() !== 'HEAD') { echo json_encode(['error' => $message], JSON_THROW_ON_ERROR); }
    }
}
