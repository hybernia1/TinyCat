<?php
declare(strict_types=1);

use TinyCat\Extension\Assets;
use TinyCat\Extension\Registry;
use TinyCat\Sitemap;

define('TINYCAT', true);
require_once dirname(__DIR__, 2) . '/App/bootstrap.php';

$expect = static function (bool $condition, string $message = 'Expectation failed.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expectFailure = static function (callable $callback): void {
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException('Expected operation was accepted.');
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            $removeTree($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
};

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tinycat-extension-capabilities-' . bin2hex(random_bytes(8));
$publishedFiles = [];

try {
    mkdir($temporaryRoot . DIRECTORY_SEPARATOR . 'assets', 0777, true);
    file_put_contents($temporaryRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'pages.css', "/* sample */\n.custom-page { color: red; }\n");
    file_put_contents($temporaryRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'pages.js', "// sample\ndocument.documentElement.dataset.customPages = 'ready';\n");

    Registry::register('sample', [
        'root' => $temporaryRoot,
        'sitemap' => [
            'count' => static fn (): int => 1,
            'entries' => static fn (int $limit, int $offset): array => $offset === 0 && $limit > 0
                ? [['url' => '/page/example', 'last_modified' => '2026-08-05 12:00:00']]
                : [],
        ],
        'assets' => static fn (string $path): array => $path === '/page/example'
            ? ['styles' => ['assets/pages.css'], 'scripts' => ['assets/pages.js']]
            : [],
    ]);

    $expect(Sitemap::sections() === ['pages', 'authors', 'status', 'tags', 'sample']);
    $expect(Sitemap::hasSection('sample'));
    $expect(Sitemap::count('sample') === 1);
    $expect(Sitemap::entries('sample', 1000, 0) === [[
        'url' => '/page/example',
        'last_modified' => '2026-08-05 12:00:00',
    ]]);
    $expect(sitemap_url('sample', 2) === '/sitemap-sample-2.xml');
    $expect(sitemap_url('Not Valid', 1) === '');

    $expect(Assets::forPath('/unrelated') === ['styles' => [], 'scripts' => []]);
    $assets = Assets::forPath('/page/example/');
    $expect(count($assets['styles']) === 1 && count($assets['scripts']) === 1);

    foreach ([...$assets['styles'], ...$assets['scripts']] as $url) {
        $expect(str_starts_with($url, '/cache/assets/ext-sample-'));
        $fileName = rawurldecode(basename($url));
        $file = Cache::file($fileName, 'assets');
        $expect(is_file($file), 'Published extension asset is missing.');
        $publishedFiles[] = $file;
    }

    $expectFailure(static fn (): string => Assets::url('sample', '../outside.css', 'css'));
    $expectFailure(static fn (): string => Assets::url('sample', '/assets/pages.css', 'css'));
    $expectFailure(static fn (): string => Assets::url('sample', 'assets/pages.js', 'css'));

    $expectFailure(static fn (): null => Registry::register('pages', [
        'sitemap' => [
            'count' => static fn (): int => 0,
            'entries' => static fn (): array => [],
        ],
    ]));

    $expectFailure(static fn (): null => Registry::register('index', [
        'sitemap' => [
            'count' => static fn (): int => 0,
            'entries' => static fn (): array => [],
        ],
    ]));

    Registry::register('invalidmap', [
        'sitemap' => [
            'count' => static fn (): int => 1,
            'entries' => static fn (): array => [['url' => 'https://example.com/outside']],
        ],
    ]);
    $expectFailure(static fn (): array => Sitemap::entries('invalidmap', 1000, 0));

    ob_start();
    $sitemapSection = 'sample';
    $sitemapPage = 1;
    require base_path('Public/sitemap.php');
    $xml = (string) ob_get_clean();
    $expect(str_contains($xml, '<loc>') && str_contains($xml, '/page/example</loc>'));
    $expect(str_contains($xml, '<lastmod>2026-08-05T12:00:00'));

    $routeRegex = (new ReflectionMethod(Core::class, 'routeRegex'))->invoke(
        null,
        '/{sitemap_file:' . Sitemap::FILE_PATTERN . '}'
    );
    $expect(preg_match($routeRegex, '/sitemap-sample-section-12.xml') === 1);

    echo "PASS extension sitemap and asset capabilities\n";
} finally {
    foreach ($publishedFiles as $file) {
        @unlink($file);
    }
    $removeTree($temporaryRoot);
}
