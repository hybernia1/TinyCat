<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$section = (string) ($sitemapSection ?? 'index');
$page = max(1, (int) ($sitemapPage ?? 1));
$perPage = 1000;
$xmlEscape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$countRows = static function (string $section): int {
    return match ($section) {
        'pages' => 1,
        'authors' => (int) val(
            'SELECT COUNT(*)
                FROM users
                WHERE status = ?',
            ['active']
        ),
        'tags' => (int) val('SELECT COUNT(*) FROM terms'),
        'status' => (int) val(
            'SELECT COUNT(*)
                FROM content c
                INNER JOIN users u ON u.id = c.author_id
                WHERE u.status = ?',
            ['active']
        ),
        default => 0,
    };
};

$pageCount = static function (int $count) use ($perPage): int {
    return max(0, (int) ceil(max(0, $count) / $perPage));
};

if ($section === 'index') {
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: public, max-age=900');
    header('X-Content-Type-Options: nosniff');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    ?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <?php foreach (['pages', 'authors', 'status', 'tags'] as $name):
    $pages = $pageCount($countRows($name));
    for ($part = 1; $part <= $pages; $part++):
?>
    <sitemap>
        <loc><?= $xmlEscape(absolute_url(sitemap_url($name, $part))) ?></loc>
    </sitemap>
<?php endfor; endforeach; ?>
</sitemapindex>
<?php
    return;
}

if (!in_array($section, ['pages', 'authors', 'status', 'tags'], true)) {
    http_response_code(404);
    exit('Sitemap not found.');
}

$offset = ($page - 1) * $perPage;
$rows = match ($section) {
    'pages' => [['path' => '/']],
    'authors' => all(
        'SELECT u.id, u.updated_at AS last_modified
            FROM users u
            WHERE u.status = ?
            ORDER BY u.id ASC
            LIMIT ' . $perPage . ' OFFSET ' . $offset,
        ['active']
    ),
    'tags' => all(
        "SELECT t.id, t.name
            FROM terms t
            ORDER BY t.id ASC
            LIMIT " . $perPage . ' OFFSET ' . $offset
    ),
    default => all(
        'SELECT c.id, c.published_at AS last_modified
            FROM content c
            INNER JOIN users u ON u.id = c.author_id
            WHERE u.status = ?
            ORDER BY c.id ASC
            LIMIT ' . $perPage . ' OFFSET ' . $offset,
        ['active']
    ),
};

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
    if ($section === 'pages') {
        $url = (string) ($row['path'] ?? '/');
    } elseif ($section === 'authors') {
        $url = author_url((int) ($row['id'] ?? 0));
    } elseif ($section === 'tags') {
        $tag = status_tag_normalize((string) ($row['name'] ?? ''));
        $url = $tag !== '' ? tag_url($tag) : '';
    } else {
        $url = status_url((int) ($row['id'] ?? 0));
    }

    if ($url === '') {
        continue;
    }

    $lastModified = (string) ($row['last_modified'] ?? '');
    $timestamp = $lastModified !== '' ? strtotime($lastModified) : false;
?>
    <url>
        <loc><?= $xmlEscape(absolute_url($url)) ?></loc>
<?php if ($timestamp !== false): ?>
        <lastmod><?= $xmlEscape(date('c', $timestamp)) ?></lastmod>
<?php endif; ?>
    </url>
<?php endforeach; ?>
</urlset>
