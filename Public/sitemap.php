<?php
declare(strict_types=1);

use TinyCat\Sitemap;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$section = (string) ($sitemapSection ?? 'index');
$page = max(1, (int) ($sitemapPage ?? 1));
$perPage = 1000;
$xmlEscape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

if ($section === 'index') {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=900');
    header('X-Content-Type-Options: nosniff');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    ?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <?php foreach (Sitemap::sections() as $name):
    $pages = Sitemap::pageCount($name, $perPage);
    for ($part = 1; $part <= $pages; $part++):
?>
    <sitemap>
        <loc><?= $xmlEscape(absolute_url(Sitemap::url($name, $part))) ?></loc>
    </sitemap>
<?php endfor; endforeach; ?>
</sitemapindex>
<?php
    return;
}

if (!Sitemap::hasSection($section)) {
    http_response_code(404);
    exit('Sitemap not found.');
}

$rows = Sitemap::entries($section, $perPage, ($page - 1) * $perPage);
if ($rows === []) {
    http_response_code(404);
    exit('Sitemap page not found.');
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($rows as $row):
    $lastModified = (string) ($row['last_modified'] ?? '');
    $timestamp = $lastModified !== '' ? strtotime($lastModified) : false;
?>
    <url>
        <loc><?= $xmlEscape(absolute_url((string) $row['url'])) ?></loc>
<?php if ($timestamp !== false): ?>
        <lastmod><?= $xmlEscape(date('c', $timestamp)) ?></lastmod>
<?php endif; ?>
    </url>
<?php endforeach; ?>
</urlset>
