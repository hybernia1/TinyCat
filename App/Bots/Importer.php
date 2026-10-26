<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use SimpleXMLElement;

use Throwable;
use RuntimeException;
use PDOException;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Importer
{
    private const FEED_HISTORY_KEEP = 500;
    private const CLEANUP_INTERVAL = 3600;
    private const CLEANUP_BATCH = 1000;
    private const ERROR_RUN_RETENTION_DAYS = 14;
    private const ORPHAN_HISTORY_RETENTION_DAYS = 14;
    private const SUCCESSFUL_RUNS_PER_SOURCE_KEEP = 50;

    private function __construct()
    {
    }

    /**
     * @return array<string, array{runner: callable(array<string, mixed>): array<string, mixed>, options: array<string, int>, admin: array<string, string>}>
     */
    public static function scheduledTasks(): array
    {
        return [
            'feeds' => [
                'runner' => static fn (array $context): array => self::runScheduledTask($context),
                'options' => ['bot_limit' => 20],
                'admin' => [
                    'icon' => 'rss',
                    'title' => 'cron.tasks.feeds',
                    'help' => 'cron.tasks.feeds_help',
                    'schedule' => 'cron.tasks.feeds_schedule',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredTables(): array
    {
        return [
            'bot_sources',
            'bot_feed_history',
            'bot_source_runs',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminNavigation(): array
    {
        return [
            'icon' => 'rss',
            'label' => \TinyCat\Support\Localization::t('bots.title'),
            'children' => [
                ['href' => '/admin/bots/accounts', 'icon' => 'users', 'label' => \TinyCat\Support\Localization::t('bots.accounts_title')],
                ['href' => '/admin/bots/list', 'icon' => 'list-unordered', 'label' => \TinyCat\Support\Localization::t('bots.sources_title')],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function runScheduledTask(array $context): array
    {
        $limit = max(1, min(100, (int) ($context['options']['bot_limit'] ?? 20)));
        $results = self::runDueSources($limit);
        $cleanup = self::cleanupDatabase();
        $summary = [];

        foreach ($results as $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            $summary[$status] = (int) ($summary[$status] ?? 0) + 1;
        }

        return [
            'ok' => true,
            'status' => 'completed',
            'limit' => $limit,
            'count' => count($results),
            'summary' => $summary,
            'results' => $results,
            'cleanup' => $cleanup,
        ];
    }

    private static function normalizeFeedUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $portNumber = isset($parts['port']) ? (int) $parts['port'] : 0;
        $isDefaultPort = ($scheme === 'http' && $portNumber === 80)
            || ($scheme === 'https' && $portNumber === 443);
        $port = $portNumber > 0 && !$isDefaultPort ? ':' . $portNumber : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    public static function feedSourceHash(string $url): string
    {
        $url = self::normalizeFeedUrl($url);
        return $url !== '' ? hash('sha256', $url) : '';
    }

    public static function sourceDuplicateExists(string $feedUrl, int $excludeId = 0): bool
    {
        $feedHash = self::feedSourceHash($feedUrl);

        if ($feedHash === '') {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM bot_sources WHERE feed_hash = ?';
        $params = [$feedHash];

        if ($excludeId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }

        return (int) \TinyCat\Database\Connection::value($sql, $params) > 0;
    }

    public static function isDuplicateSourceException(Throwable $exception): bool
    {
        return $exception instanceof PDOException
            && (string) $exception->getCode() === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * @param list<string> $itemHashes
     * @return array<string, true>
     */
    private static function feedHistoryHashes(int $botUserId, string $feedHash, array $itemHashes): array
    {
        $itemHashes = array_values(array_unique(array_filter($itemHashes, static fn (mixed $hash): bool => preg_match('/^[a-f0-9]{64}$/', (string) $hash) === 1)));
        if ($botUserId < 1 || !preg_match('/^[a-f0-9]{64}$/', $feedHash) || $itemHashes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($itemHashes), '?'));
        $rows = \TinyCat\Database\Connection::all(
            'SELECT item_hash
             FROM bot_feed_history
             WHERE bot_user_id = ? AND feed_hash = ? AND item_hash IN (' . $placeholders . ')',
            array_merge([$botUserId, $feedHash], $itemHashes)
        );

        return array_fill_keys(array_column($rows, 'item_hash'), true);
    }

    private static function recordFeedHistory(
        int $botUserId,
        string $feedHash,
        string $itemHash,
        string $createdAt = ''
    ): void
    {
        if ($botUserId < 1 || $feedHash === '' || $itemHash === '') {
            return;
        }

        try {
            $data = [
                'bot_user_id' => $botUserId,
                'feed_hash' => $feedHash,
                'item_hash' => $itemHash,
                'created_at' => $createdAt !== '' ? $createdAt : \TinyCat\Support\Dates::dateDb(),
            ];
            \TinyCat\Database\Connection::insert('bot_feed_history', $data);
        } catch (Throwable) {
            // The global history key is intentionally immutable and race-safe.
        }

        self::pruneFeedHistory($botUserId, $feedHash);
    }

    private static function pruneFeedHistory(int $botUserId, string $feedHash, int $keep = self::FEED_HISTORY_KEEP): void
    {
        if ($botUserId < 1 || !preg_match('/^[a-f0-9]{64}$/', $feedHash)) {
            return;
        }

        $keep = max(10, min(1000, $keep));
        \TinyCat\Database\Connection::exec(
            'DELETE FROM bot_feed_history
                WHERE bot_user_id = ? AND feed_hash = ?
                    AND item_hash NOT IN (
                        SELECT item_hash FROM (
                            SELECT item_hash
                            FROM bot_feed_history
                            WHERE bot_user_id = ? AND feed_hash = ?
                            ORDER BY created_at DESC, item_hash DESC
                            LIMIT ' . $keep . '
                        ) recent_items
                    )',
            [$botUserId, $feedHash, $botUserId, $feedHash]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function cleanupDatabase(): array
    {
        $now = time();
        $lastRun = max(0, (int) \TinyCat\Settings\Store::setting('bots.cleanup_last_run', 0));
        if ($lastRun > $now - self::CLEANUP_INTERVAL) {
            return ['due' => false, 'changed' => 0, 'has_more' => false];
        }

        $batch = self::CLEANUP_BATCH;
        $errorsBefore = date('Y-m-d H:i:s', $now - self::ERROR_RUN_RETENTION_DAYS * 86400);
        $historyBefore = date('Y-m-d H:i:s', $now - self::ORPHAN_HISTORY_RETENTION_DAYS * 86400);
        $results = [];

        try {
            $results['old_errors'] = \TinyCat\Database\Connection::exec(
                'DELETE FROM bot_source_runs WHERE status = ? AND started_at < ? LIMIT ' . $batch,
                ['error', $errorsBefore]
            );
            $results['orphan_history'] = \TinyCat\Database\Connection::exec(
                'DELETE FROM bot_feed_history
                 WHERE created_at < ?
                    AND NOT EXISTS (
                        SELECT 1 FROM bot_sources
                        WHERE bot_sources.bot_user_id = bot_feed_history.bot_user_id
                            AND bot_sources.feed_hash = bot_feed_history.feed_hash
                    )
                 LIMIT ' . $batch,
                [$historyBefore]
            );
            $results['successful_run_cap'] = self::pruneSuccessfulRunsPerSource($batch);
        } catch (Throwable $exception) {
            return [
                'due' => true,
                'changed' => array_sum($results),
                'has_more' => false,
                'error' => $exception->getMessage(),
            ];
        }

        $changed = array_sum($results);
        $hasMore = array_any($results, static fn (int $count): bool => $count >= $batch);
        if (!$hasMore) {
            \TinyCat\Settings\Store::setSetting('bots.cleanup_last_run', $now, 'int', 'bots');
        }

        return [
            'due' => true,
            'changed' => $changed,
            'has_more' => $hasMore,
            'results' => $results,
        ];
    }

    private static function pruneSuccessfulRunsPerSource(int $batch): int
    {
        $changed = 0;
        $keep = self::SUCCESSFUL_RUNS_PER_SOURCE_KEEP;

        foreach (\TinyCat\Database\Connection::all('SELECT id FROM bot_sources ORDER BY id ASC') as $source) {
            $sourceId = (int) ($source['id'] ?? 0);
            if ($sourceId < 1 || $changed >= $batch) {
                break;
            }

            $remaining = max(1, $batch - $changed);
            $changed += \TinyCat\Database\Connection::exec(
                'DELETE FROM bot_source_runs
                 WHERE source_id = ? AND status <> ?
                    AND id NOT IN (
                        SELECT id FROM (
                            SELECT id
                            FROM bot_source_runs
                            WHERE source_id = ? AND status <> ?
                            ORDER BY started_at DESC, id DESC
                            LIMIT ' . $keep . '
                        ) retained_runs
                    )
                 LIMIT ' . $remaining,
                [$sourceId, 'error', $sourceId, 'error']
            );
        }

        return $changed;
    }

    public static function defaultSourceTemplate(): string
    {
        return "{{title}}\n\n{{description}}\n\n{{url}}";
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findSource(int $id): ?array
    {
        return $id > 0
            ? \TinyCat\Database\Connection::one(
                'SELECT bs.*, u.username
                 FROM bot_sources bs
                 LEFT JOIN users u ON u.id = bs.bot_user_id AND u.role = ?
                 WHERE bs.id = ?
                 LIMIT 1',
                ['bot', $id]
            )
            : null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public static function sources(?int $botUserId = null): array
    {
        $sql = 'SELECT bs.*, u.username FROM bot_sources bs INNER JOIN users u ON u.id = bs.bot_user_id WHERE u.role = ?';
        $params = ['bot'];

        if ($botUserId !== null && $botUserId > 0) {
            $sql .= ' AND bs.bot_user_id = ?';
            $params[] = $botUserId;
        }

        return \TinyCat\Database\Connection::all($sql . ' ORDER BY u.username ASC, bs.name ASC, bs.id ASC', $params);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function sourceResource(array $source): array
    {
        return [
            'id' => (int) ($source['id'] ?? 0),
            'bot_user_id' => (int) ($source['bot_user_id'] ?? 0),
            'bot_username' => (string) ($source['username'] ?? ''),
            'name' => (string) ($source['name'] ?? ''),
            'feed_url' => (string) ($source['feed_url'] ?? ''),
            'interval_minutes' => (int) ($source['interval_minutes'] ?? 60),
            'post_template' => (string) ($source['post_template'] ?? ''),
            'enabled' => (bool) ($source['enabled'] ?? false),
            'last_checked_at' => (string) ($source['last_checked_at'] ?? ''),
            'last_imported_at' => (string) ($source['last_imported_at'] ?? ''),
            'last_imported_label' => !empty($source['last_imported_at']) ? \TinyCat\Support\Dates::displayDateTime((string) $source['last_imported_at']) : '',
            'next_run_at' => (string) ($source['next_run_at'] ?? ''),
            'next_run_label' => !empty($source['next_run_at']) ? \TinyCat\Support\Dates::displayDateTime((string) $source['next_run_at']) : '',
            'last_error' => (string) ($source['last_error'] ?? ''),
        ];
    }

    private static function createSourceRun(int $sourceId): int
    {
        if ($sourceId < 1) {
            return 0;
        }

        try {
            return (int) \TinyCat\Database\Connection::insert('bot_source_runs', [
                'source_id' => $sourceId,
                'status' => 'running',
                'started_at' => \TinyCat\Support\Dates::dateDb(),
            ]);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function finishSourceRun(int $runId, string $status, int $itemsSeen = 0, string $error = ''): void
    {
        if ($runId < 1) {
            return;
        }

        try {
            \TinyCat\Database\Connection::update('bot_source_runs', [
                'status' => $status,
                'items_seen' => max(0, $itemsSeen),
                'error' => $error !== '' ? self::feedText($error, 500) : null,
            ], ['id' => $runId]);
        } catch (Throwable) {
            // Run history is diagnostic and must never break the import.
        }
    }

    public static function deleteSource(int $sourceId): void
    {
        if ($sourceId < 1) {
            return;
        }

        \TinyCat\Database\Transactions::run(static function () use ($sourceId): void {
            \TinyCat\Database\Connection::delete('bot_source_runs', ['source_id' => $sourceId]);
            \TinyCat\Database\Connection::delete('bot_sources', ['id' => $sourceId]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseFeed(string $xml): array
    {
        if ($xml === '' || !function_exists('simplexml_load_string')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$feed instanceof SimpleXMLElement) {
            return [];
        }

        $nodes = isset($feed->channel->item) ? $feed->channel->item : $feed->entry;
        $items = [];

        foreach ($nodes as $node) {
            $namespaces = $node->getNamespaces(true);
            $link = trim((string) $node->link);

            if ($node->getName() === 'entry') {
                foreach ($node->link as $linkNode) {
                    $attributes = $linkNode->attributes();
                    $rel = strtolower((string) ($attributes['rel'] ?? 'alternate'));

                    if ($rel === '' || $rel === 'alternate') {
                        $link = trim((string) ($attributes['href'] ?? $linkNode));
                        break;
                    }
                }
            }

            $creator = '';
            if (isset($namespaces['dc'])) {
                $creator = trim((string) $node->children($namespaces['dc'])->creator);
            }
            if ($creator === '') {
                $creator = trim((string) ($node->author->name ?? $node->author));
            }

            $categories = [];
            foreach ($node->category as $category) {
                $value = trim((string) ($category['term'] ?? $category));
                if ($value !== '') {
                    $categories[] = $value;
                }
            }

            $title = self::feedText((string) $node->title, 500);
            $descriptionSource = (string) ($node->description ?: $node->summary ?: $node->content);
            $description = self::feedDescriptionText($descriptionSource, 1200);
            $guid = trim((string) ($node->guid ?: $node->id ?: $link));
            $published = trim((string) ($node->pubDate ?: $node->published ?: $node->updated));

            if ($guid === '' || ($title === '' && $link === '')) {
                continue;
            }

            $timestamp = $published !== '' ? strtotime($published) : false;
            $items[] = [
                'guid' => $guid,
                'title' => $title,
                'description' => $description,
                'url' => \TinyCat\Status\Metadata::isSafeRemoteUrl($link) ? $link : '',
                'image_url' => self::feedImageUrl($node, $namespaces, $descriptionSource),
                'author' => self::feedText($creator, 200),
                'categories' => array_values(array_unique($categories)),
                'published_at' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null,
                '_timestamp' => $timestamp !== false ? $timestamp : 0,
            ];

            if (count($items) >= 100) {
                break;
            }
        }

        usort($items, static fn (array $a, array $b): int => ((int) $a['_timestamp']) <=> ((int) $b['_timestamp']));
        return $items;
    }

    private static function feedText(string $value, int $limit): string
    {
        $value = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function feedDescriptionText(string $value, int $limit): string
    {
        $value = preg_replace_callback(
            '~<a\b([^>]*)>(.*?)</a\s*>~is',
            static function (array $match): string {
                $label = (string) $match[2];
                $videoUrl = self::feedHtmlVideoUrl((string) $match[1], 'href');
                if ($videoUrl === '') {
                    return $label;
                }

                $labelText = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                foreach (\TinyCat\Status\LinkParser::extract($labelText) as $labelLink) {
                    if ((string) ($labelLink['normalized_url'] ?? '') === $videoUrl) {
                        return $label;
                    }
                }

                return $label . ' ' . $videoUrl;
            },
            $value
        ) ?? $value;
        $value = preg_replace_callback(
            '~<(?:iframe|embed)\b([^>]*)>(?:\s*</iframe\s*>)?~is',
            static fn (array $match): string => self::feedHtmlVideoUrl((string) $match[1], 'src'),
            $value
        ) ?? $value;
        $value = \TinyCat\Support\Text::strip_html_tags_preserving_text(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace_callback(
            \TinyCat\Status\LinkParser::pattern(),
            static function (array $match): string {
                [$url, $tail] = \TinyCat\Status\LinkParser::splitTail((string) ($match[0] ?? ''));
                $videoUrl = self::supportedVideoUrl($url);
                return ($videoUrl !== '' ? $videoUrl : '') . $tail;
            },
            $value
        ) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = preg_replace('/\s+([.,;:!?])/u', '$1', $value) ?? $value;
        $value = preg_replace('/(?:^|\s)The post\b.*?\bappeared first on\s*[.!?]*\s*$/iu', '', $value) ?? $value;
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }

    private static function feedHtmlVideoUrl(string $attributes, string $name): string
    {
        $name = preg_quote($name, '~');
        if (preg_match('~\b' . $name . '\s*=\s*(["\'])(.*?)\1~is', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode((string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('~\b' . $name . '\s*=\s*([^\s>]+)~i', $attributes, $match) === 1) {
            return self::supportedVideoUrl(html_entity_decode(trim((string) $match[1], "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private static function supportedVideoUrl(string $url): string
    {
        $link = \TinyCat\Status\LinkParser::fromRaw(trim($url));
        return ($link['link_type'] ?? '') === 'video' ? (string) ($link['normalized_url'] ?? '') : '';
    }

    /**
     * @param array<string, mixed> $namespaces
     */
    private static function feedImageUrl(SimpleXMLElement $node, array $namespaces, string $description = ''): string
    {
        $candidates = [];

        if (isset($namespaces['media'])) {
            $media = $node->children((string) $namespaces['media']);
            foreach (['content', 'thumbnail'] as $element) {
                foreach ($media->{$element} as $image) {
                    $candidates[] = (string) ($image->attributes()['url'] ?? '');
                }
            }
        }

        foreach ($node->enclosure as $enclosure) {
            $attributes = $enclosure->attributes();
            $type = strtolower((string) ($attributes['type'] ?? ''));
            if ($type === '' || str_starts_with($type, 'image/')) {
                $candidates[] = (string) ($attributes['url'] ?? '');
            }
        }

        if (preg_match('~<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~is', $description, $match) === 1) {
            $candidates[] = (string) $match[2];
        } elseif (preg_match('~<img\b[^>]*\bsrc\s*=\s*([^\s>]+)~is', $description, $match) === 1) {
            $candidates[] = trim((string) $match[1], "\"'");
        }

        foreach ($candidates as $candidate) {
            $url = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strlen($url) <= 2048 && \TinyCat\Status\Metadata::isSafeRemoteUrl($url)) {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item
     */
    private static function renderPost(array $source, array $item): string
    {
        $categories = array_values(array_map(static fn (mixed $category): string => (string) $category, (array) ($item['categories'] ?? [])));
        $values = [
            '{{title}}' => (string) ($item['title'] ?? ''),
            '{{description}}' => (string) ($item['description'] ?? ''),
            '{{url}}' => (string) ($item['url'] ?? ''),
            '{{author}}' => (string) ($item['author'] ?? ''),
            '{{source}}' => (string) ($source['name'] ?? ''),
            '{{categories}}' => implode(', ', $categories),
            '#{categories}' => self::feedCategoryTags($categories),
        ];
        $body = trim(preg_replace("/\n{3,}/", "\n\n", strtr((string) ($source['post_template'] ?? self::defaultSourceTemplate()), $values)) ?? '');
        return function_exists('mb_substr') ? mb_substr($body, 0, 2000, 'UTF-8') : substr($body, 0, 2000);
    }

    /**
     * @param list<string> $categories
     */
    private static function feedCategoryTags(array $categories): string
    {
        $tags = [];

        foreach ($categories as $category) {
            $tag = \TinyCat\Status\Tags::status_tag_normalize((string) $category);
            if ($tag !== '') {
                $tags[$tag] = '#' . $tag;
            }
        }

        return implode(' ', array_values($tags));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function runDueSources(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $sources = \TinyCat\Database\Connection::all(
            'SELECT bs.*, u.username, u.status AS user_status
                FROM bot_sources bs
                INNER JOIN users u ON u.id = bs.bot_user_id
                WHERE bs.enabled = 1 AND u.role = ? AND u.status = ?
                    AND (bs.next_run_at IS NULL OR bs.next_run_at <= ?)
                ORDER BY COALESCE(bs.next_run_at, bs.created_at) ASC, bs.id ASC
                LIMIT ' . $limit,
            ['bot', 'active', \TinyCat\Support\Dates::dateDb()]
        );
        $results = [];

        foreach ($sources as $source) {
            $results[] = self::runSource($source);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function runSource(array $source, bool $force = false): array
    {
        $sourceId = (int) ($source['id'] ?? 0);
        $botUserId = (int) ($source['bot_user_id'] ?? 0);
        $feedUrl = (string) ($source['feed_url'] ?? '');
        $interval = max(5, min(43200, (int) ($source['interval_minutes'] ?? 60)));
        $now = \TinyCat\Support\Dates::dateDb();
        $next = date('Y-m-d H:i:s', time() + $interval * 60);
        $claimed = $force
            ? \TinyCat\Database\Connection::exec(
                'UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1',
                [$next, $now, $sourceId]
            )
            : \TinyCat\Database\Connection::exec(
                'UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?)',
                [$next, $now, $sourceId, $now]
            );

        if ($sourceId < 1 || $claimed < 1) {
            return ['source_id' => $sourceId, 'status' => 'skipped'];
        }

        $runId = self::createSourceRun($sourceId);
        $itemsSeen = 0;

        try {
            $response = \TinyCat\Http\Session::withoutLock(static fn (): ?array => \TinyCat\Status\Metadata::fetchDocument((string) ($source['feed_url'] ?? '')));
            if ($response === null) {
                throw new RuntimeException('RSS feed could not be downloaded.');
            }

            $items = self::parseFeed((string) ($response['body'] ?? ''));
            $itemsSeen = count($items);
            if ($items === []) {
                throw new RuntimeException('RSS feed contains no usable items.');
            }

            $feedHash = self::feedSourceHash($feedUrl);
            $itemHashes = array_map(static fn (array $item): string => hash('sha256', (string) ($item['guid'] ?? '')), $items);
            $historyItemHashes = self::feedHistoryHashes($botUserId, $feedHash, $itemHashes);

            foreach ($items as $item) {
                $itemGuid = (string) ($item['guid'] ?? '');
                $hash = hash('sha256', $itemGuid);
                if (isset($historyItemHashes[$hash])) {
                    continue;
                }

                $body = self::renderPost($source, $item);
                if ($body === '') {
                    throw new RuntimeException('Post template produced an empty post.');
                }

                $publishedAt = \TinyCat\Support\Dates::dateDb();
                $contentId = (int) \TinyCat\Database\Connection::insert('content', [
                    'body' => $body,
                    'author_id' => $botUserId,
                    'published_at' => $publishedAt,
                ]);
                \TinyCat\Status\Tags::status_sync_tags($contentId, \TinyCat\Status\Tags::status_tags_from_text($body));
                $feedLink = \TinyCat\Status\LinkParser::fromRaw((string) ($item['url'] ?? ''));
                \TinyCat\Status\Links::status_sync_links(
                    $contentId,
                    \TinyCat\Status\Links::status_links_from_text($body),
                    (string) ($feedLink['url_hash'] ?? ''),
                    (string) ($item['image_url'] ?? '')
                );
                self::recordFeedHistory(
                    $botUserId,
                    $feedHash,
                    $hash,
                    $publishedAt
                );
                $historyItemHashes[$hash] = true;
                \TinyCat\Database\Connection::update('bot_sources', ['last_imported_at' => $publishedAt], ['id' => $sourceId]);
                self::finishSourceRun($runId, 'posted', $itemsSeen);

                return ['source_id' => $sourceId, 'status' => 'posted', 'content_id' => $contentId];
            }

            self::finishSourceRun($runId, 'current', $itemsSeen);
            return ['source_id' => $sourceId, 'status' => 'current'];
        } catch (Throwable $exception) {
            $error = self::feedText($exception->getMessage(), 500);
            \TinyCat\Database\Connection::update('bot_sources', ['last_error' => $error], ['id' => $sourceId]);
            self::finishSourceRun($runId, 'error', $itemsSeen, $error);
            return ['source_id' => $sourceId, 'status' => 'error', 'error' => $error];
        }
    }
}
