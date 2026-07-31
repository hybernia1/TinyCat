<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$section = (string) ($sitemapSection ?? 'index');
$page = max(1, (int) get('page', 1));
$perPage = 1000;
$xmlEscape = static fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$pageUrl = static function (string $name, int $page): string {
    return '/' . $name . '.xml?page=' . $page;
};

$countRows = static function (string $section): int {
    return match ($section) {
        'authors' => (int) val('SELECT COUNT(*) FROM users WHERE status = ?', ['active']),
        'tags' => (int) val(
            "SELECT COUNT(DISTINCT t.id)
                FROM terms t
                INNER JOIN content_tags ct ON ct.term_id = t.id
                INNER JOIN content c ON c.id = ct.content_id
                INNER JOIN users u ON u.id = c.author_id
                WHERE u.status = ?",
            ['active']
        ),
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
<?php foreach (['authors', 'status', 'tags'] as $name):
    $pages = $pageCount($countRows($name));
    for ($part = 1; $part <= $pages; $part++):
?>
    <sitemap>
        <loc><?= $xmlEscape(absolute_url($pageUrl('sitemap-' . $name, $part))) ?></loc>
    </sitemap>
<?php endfor; endforeach; ?>
</sitemapindex>
<?php
    return;
}

if (!in_array($section, ['authors', 'status', 'tags'], true)) {
    http_response_code(404);
    exit('Sitemap not found.');
}

$offset = ($page - 1) * $perPage;
$rows = match ($section) {
    'authors' => all(
        'SELECT id, updated_at AS last_modified
            FROM users
            WHERE status = ?
            ORDER BY id ASC
            LIMIT ' . $perPage . ' OFFSET ' . $offset,
        ['active']
    ),
    'tags' => all(
        "SELECT t.id, t.name, MAX(c.published_at) AS last_modified
            FROM terms t
            INNER JOIN content_tags ct ON ct.term_id = t.id
            INNER JOIN content c ON c.id = ct.content_id
            INNER JOIN users u ON u.id = c.author_id
            WHERE u.status = ?
            GROUP BY t.id, t.name
            ORDER BY t.id ASC
            LIMIT " . $perPage . ' OFFSET ' . $offset,
        ['active']
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

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');
header('X-Content-Type-Options: nosniff');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($rows as $row):
    if ($section === 'authors') {
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
