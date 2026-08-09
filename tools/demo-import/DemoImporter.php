<?php
declare(strict_types=1);

namespace TinyCat\Tools\DemoImport;

use PDO;
use RuntimeException;
use Throwable;

final class DemoImporter
{
    private const int MILLION_ROW_TARGET = 1_000_000;

    private const array PHASES = [
        'prepare',
        'users',
        'posts',
        'follows',
        'content_links',
        'post_likes',
        'comments',
        'comment_likes',
        'reports',
        'settings',
        'complete',
    ];

    private const array TAGS = [
        'tinycat', 'opensource', 'selfhosted', 'privacy', 'php', 'community', 'socialweb', 'testing',
        'nocloud', 'frontend', 'moderation', 'notifications', 'mobilefirst', 'anonymous', 'feed',
        'performance', 'database', 'comments', 'likes', 'minimalism', 'benchmark', 'apache', 'memcached',
        'latency', 'throughput', 'accessibility', 'security', 'refactor', 'architecture', 'web', 'mysql',
        'release', 'profiling', 'cache', 'backend', 'ux', 'discussion', 'thread', 'reply', 'communitycare',
    ];

    private const array TOPICS = [
        'TinyCat feels faster with a simpler schema',
        'A text-only social feed is easier to read',
        'The best performance work starts with measurement',
        'Small public communities need clear defaults',
        'Mobile-first design exposes every wasted element',
        'Local benchmark data should resemble real usage',
        'Open source social software can stay intentionally small',
        'Indexes matter, but predictable queries matter too',
        'A useful comment thread needs realistic depth',
        'Warm caches should not hide slow database paths',
        'Release candidates deserve repeatable load tests',
        'Operational simplicity is a performance feature',
    ];

    private const array COMMENTS = [
        'This is a useful point for the benchmark.',
        'Agreed, the simpler flow reads better.',
        'This makes sense for a public feed.',
        'The larger thread gives us a better detail-page sample.',
        'This should exercise both cache and persistence paths.',
        'Good test case for comment rendering.',
        'The database shape is easier to reason about now.',
        'A realistic distribution matters more than a flat fixture.',
    ];

    private const array REPLIES = [
        'Exactly, that is the direction.',
        'Yes, and it keeps the thread readable.',
        'This is also easier to moderate.',
        'Good note; the warm-cache run should confirm it.',
        'The nested relation is useful for this comparison.',
        'I would keep this case in the repeatable suite.',
    ];

    private readonly DeterministicGenerator $generator;
    private readonly BatchWriter $writer;
    private readonly Schema $schema;
    private readonly CheckpointStore $checkpoints;
    /** @var array<string, int> */
    private array $termIds = [];

    public function __construct(
        private readonly PDO $database,
        private readonly Options $options,
        private readonly ProgressReporter $progress,
        private readonly string $databaseName,
    ) {
        $this->generator = new DeterministicGenerator($options->seed, $options->anchor);
        $this->writer = new BatchWriter($database, min(500, $options->batchSize));
        $this->schema = new Schema($database);
        $this->checkpoints = new CheckpointStore($options->statePath);
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $this->schema->validate();
        $configuration = ['database' => $this->databaseName, ...$this->options->fingerprintData()];
        $fingerprint = hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
        $state = $this->checkpoints->load(
            $fingerprint,
            $configuration,
            $this->options->reset,
            $this->options->resume,
        );
        $this->progress->event('start', ['configuration' => $configuration, 'resumed' => !$this->options->reset]);

        if ($this->options->reset) {
            $this->progress->event('reset');
            $this->schema->reset();
            $state = $this->freshState($state);
            $this->checkpoints->save($state);
        } elseif (($state['phase'] ?? 'prepare') === 'prepare' && $this->tableCount('users') > 0) {
            throw new RuntimeException('Database contains users but no resumable checkpoint. Use --reset=1.');
        }

        if (($state['complete'] ?? false) === true) {
            $report = $this->report($state);
            $this->progress->event('complete', $report);

            return $report;
        }

        $batchesThisRun = 0;
        $activePhase = '';

        while (($state['phase'] ?? 'complete') !== 'complete') {
            $phase = (string) ($state['phase'] ?? 'prepare');
            if (!in_array($phase, self::PHASES, true)) {
                throw new RuntimeException('Unknown checkpoint phase: ' . $phase);
            }
            if ($phase !== $activePhase) {
                $activePhase = $phase;
                $this->progress->event('phase', ['phase' => $phase]);
            }

            if ($phase === 'prepare') {
                $this->prepare($state);
                $state['phase'] = 'users';
                $state['cursor'] = 0;
                $this->checkpoints->save($state);
                continue;
            }

            $started = hrtime(true);
            $this->database->beginTransaction();
            try {
                $result = $this->batch($phase, (int) ($state['cursor'] ?? 0));
                $this->database->commit();
            } catch (Throwable $exception) {
                if ($this->database->inTransaction()) {
                    $this->database->rollBack();
                }
                throw $exception;
            }

            $durationMs = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
            $state['cursor'] = $result['cursor'];
            $state['batches'] = (int) ($state['batches'] ?? 0) + 1;
            $state['stats'] = $this->mergeStats((array) ($state['stats'] ?? []), $result['stats']);
            $state['peak_memory_bytes'] = max(
                (int) ($state['peak_memory_bytes'] ?? 0),
                memory_get_peak_usage(true),
            );
            $this->checkpoints->save($state);
            $batchesThisRun++;

            $sourceProcessed = max(1, $result['cursor'] - $result['previous_cursor']);
            $sourceRate = $sourceProcessed / max(0.001, $durationMs / 1000);
            $remaining = max(0, $result['total'] - $result['cursor']);
            $this->progress->event('batch', [
                'phase' => $phase,
                'cursor' => $result['cursor'],
                'total' => $result['total'],
                'rows' => $result['rows'],
                'duration_ms' => $durationMs,
                'eta_seconds' => (int) ceil($remaining / max(0.001, $sourceRate)),
                'memory_bytes' => memory_get_usage(true),
                'peak_memory_bytes' => (int) $state['peak_memory_bytes'],
                'stats' => $result['stats'],
            ]);

            if ($result['done']) {
                $state['phase'] = $this->nextPhase($phase);
                $state['cursor'] = 0;
                $this->checkpoints->save($state);
            }

            if ($this->options->maxBatches > 0 && $batchesThisRun >= $this->options->maxBatches) {
                $this->progress->event('paused', [
                    'phase' => $state['phase'],
                    'cursor' => $state['cursor'],
                    'batches' => $state['batches'],
                ]);

                return ['ok' => true, 'complete' => false, 'state' => $state];
            }
        }

        $state['complete'] = true;
        $state['completed_at'] = gmdate('c');
        $state['cursor'] = 0;
        $this->checkpoints->save($state);
        $this->clearFilesystemCache();
        $report = $this->report($state);
        if ($this->options->profile === 'million' && (int) $report['relational_rows'] < self::MILLION_ROW_TARGET) {
            throw new RuntimeException(sprintf(
                'Million profile generated only %d relational rows; expected at least %d.',
                (int) $report['relational_rows'],
                self::MILLION_ROW_TARGET,
            ));
        }
        $this->writeReport($report);
        $this->progress->event('complete', $report);

        return $report;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function freshState(array $state): array
    {
        $state['phase'] = 'prepare';
        $state['cursor'] = 0;
        $state['batches'] = 0;
        $state['stats'] = [];
        $state['peak_memory_bytes'] = memory_get_peak_usage(true);
        $state['started_at'] = gmdate('c');
        $state['updated_at'] = gmdate('c');
        $state['complete'] = false;
        unset($state['completed_at']);

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function prepare(array &$state): void
    {
        $hash = crypt($this->options->password, '$2y$10$TinyCatBenchmark2026XY');
        if (!password_verify($this->options->password, $hash)) {
            throw new RuntimeException('Unable to create deterministic benchmark password hash.');
        }
        $anchor = $this->options->anchor;
        $admin = [[
            $this->options->prefix . '_admin',
            $hash,
            'admin',
            'active',
            'en',
            'TinyCat benchmark administrator.',
            $anchor,
            $anchor,
            $anchor,
            $anchor,
        ]];
        $inserted = $this->writer->insert('users', [
            'username', 'password', 'role', 'status', 'locale', 'bio', 'last_login_at', 'last_seen_at',
            'created_at', 'updated_at',
        ], $admin, false, true);
        if (($inserted['ids'][0] ?? 0) !== 1) {
            throw new RuntimeException('Benchmark admin must receive user ID 1. Reset the dataset.');
        }
        $terms = array_map(static fn (string $tag): array => [$tag], self::TAGS);
        $termResult = $this->writer->insert('terms', ['name'], $terms, true);
        $this->schema->configureSettings();
        $state['stats'] = $this->mergeStats((array) ($state['stats'] ?? []), [
            'admins' => 1,
            'tags' => $termResult['affected'],
        ]);
    }

    /**
     * @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>}
     */
    private function batch(string $phase, int $cursor): array
    {
        return match ($phase) {
            'users' => $this->usersBatch($cursor),
            'posts' => $this->postsBatch($cursor),
            'follows' => $this->followsBatch($cursor),
            'content_links' => $this->contentLinksBatch($cursor),
            'post_likes' => $this->postLikesBatch($cursor),
            'comments' => $this->commentsBatch($cursor),
            'comment_likes' => $this->commentLikesBatch($cursor),
            'reports' => $this->reportsBatch($cursor),
            'settings' => $this->settingsBatch($cursor),
            default => throw new RuntimeException('Unsupported batch phase: ' . $phase),
        };
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function usersBatch(int $cursor): array
    {
        $to = min($this->options->users, $cursor + $this->options->batchSize);
        $hash = crypt($this->options->password, '$2y$10$TinyCatBenchmark2026XY');
        $rows = [];

        for ($index = $cursor + 1; $index <= $to; $index++) {
            $username = $this->username($index + 1);
            $created = $this->generator->dateBeforeAnchor('user-created', (string) $index, 180, 5);
            $lastSeen = $this->generator->dateBeforeAnchor('user-seen', (string) $index, 21, 0);
            $lastLogin = $this->generator->dateAfter('user-login', (string) $index, $created);
            $rows[] = [
                $username,
                $hash,
                'user',
                'active',
                $this->generator->chance('user-locale', (string) $index, 65) ? 'cs' : 'en',
                'Benchmark profile ' . $username . ' with posts, follows, reactions, and nested discussions.',
                $lastLogin,
                $lastSeen,
                $created,
                $lastSeen,
            ];
        }

        $result = $this->writer->insert('users', [
            'username', 'password', 'role', 'status', 'locale', 'bio', 'last_login_at', 'last_seen_at',
            'created_at', 'updated_at',
        ], $rows, false, true);

        return $this->result($cursor, $to, $this->options->users, count($rows), ['users' => $result['affected']]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function postsBatch(int $cursor): array
    {
        $users = $this->usersAfter($cursor);
        $total = $this->maximumId('users', "role = 'user'");
        if ($users === []) {
            return $this->result($cursor, $total, $total, 0, []);
        }
        $termIds = $this->termIds();
        $postRows = [];
        $postTags = [];

        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $count = $this->generator->integer('post-count', (string) $userId, $this->options->minPosts, $this->options->maxPosts);
            for ($post = 1; $post <= $count; $post++) {
                $key = $userId . ':' . $post;
                $created = $this->generator->dateBeforeAnchor('post-date', $key, 60, 0);
                $tagIndexes = $this->generator->uniqueIntegers('post-tags', $key, $this->generator->integer('post-tag-count', $key, 2, 8), 0, count(self::TAGS) - 1);
                $tags = array_map(static fn (int $index): string => self::TAGS[$index], $tagIndexes);
                $topic = $this->generator->pick('post-topic', $key, self::TOPICS);
                $mention = '';
                if ($this->generator->chance('post-mention', $key, 25)) {
                    $mentioned = $this->generator->integer('post-mention-user', $key, 1, $this->options->users + 1);
                    if ($mentioned !== $userId) {
                        $mention = ' @' . $this->username($mentioned);
                    }
                }
                $link = $this->generator->chance('post-link', $key, 14)
                    ? ' https://benchmark.invalid/demo/' . $userId . '-' . $post
                    : '';
                $body = $topic . $mention . '. ' . implode(' ', array_map(static fn (string $tag): string => '#' . $tag, $tags)) . $link;
                $postRows[] = [$body, $userId, $created, $created];
                $postTags[] = $tags;
            }
        }

        $inserted = $this->writer->insert('content', ['body', 'author_id', 'published_at', 'created_at'], $postRows, false, true);
        $tagRows = [];
        foreach ($inserted['ids'] as $offset => $contentId) {
            foreach ($postTags[$offset] as $tag) {
                $tagRows[] = [$contentId, $termIds[$tag]];
            }
        }
        $tagResult = $this->writer->insert('content_tags', ['content_id', 'term_id'], $tagRows, true);
        $last = (int) end($users)['id'];

        return $this->result($cursor, $last, $total, count($postRows) + count($tagRows), [
            'posts' => $inserted['affected'],
            'content_tags' => $tagResult['affected'],
        ]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function followsBatch(int $cursor): array
    {
        $users = $this->usersAfter($cursor);
        $total = $this->maximumId('users', "role = 'user'");
        $allUsers = $this->maximumId('users');
        if ($users === []) {
            return $this->result($cursor, $total, $total, 0, []);
        }
        $rows = [];
        $notifications = [];
        foreach ($users as $user) {
            $follower = (int) $user['id'];
            $count = $this->generator->integer('follow-count', (string) $follower, $this->options->minFollows, $this->options->maxFollows);
            $targets = $this->generator->uniqueIntegers('follow-targets', (string) $follower, $count, 1, $allUsers, $follower);
            foreach ($targets as $target) {
                $created = $this->generator->dateAfter('follow-date', $follower . ':' . $target, (string) $user['created_at']);
                $rows[] = [$target, $follower, $created];
                if ($this->generator->chance('follow-notification', $follower . ':' . $target, 35)) {
                    $notifications[] = $this->notificationRow($target, $follower, null, null, 'follow', 'follow:' . $follower, $created);
                }
            }
        }
        $inserted = $this->writer->insert('user_followers', ['user_id', 'follower_id', 'created_at'], $rows, true);
        $notificationResult = $this->writer->insert('notifications', $this->notificationColumns(), $notifications, true);
        $last = (int) end($users)['id'];

        return $this->result($cursor, $last, $total, count($rows) + count($notifications), [
            'follows' => $inserted['affected'],
            'notifications' => $notificationResult['affected'],
        ]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function contentLinksBatch(int $cursor): array
    {
        $posts = $this->postsAfter($cursor);
        $total = $this->maximumId('content');
        if ($posts === []) {
            return $this->result($cursor, $total, $total, 0, []);
        }
        $links = [];
        $contentIds = [];
        $contentCreated = [];
        foreach ($posts as $post) {
            if (preg_match('~https://benchmark\.invalid/demo/[0-9-]+~', (string) $post['body'], $match) !== 1) {
                continue;
            }
            $url = $match[0];
            $created = (string) $post['created_at'];
            $links[] = [$url, hash('sha256', $url), 'web', 'link', 'TinyCat benchmark link', 'Deterministic local metadata.', $created, $created];
            $contentIds[] = (int) $post['id'];
            $contentCreated[(int) $post['id']] = $created;
        }
        $inserted = $this->writer->insert('links', [
            'normalized_url', 'url_hash', 'provider', 'link_type', 'title', 'description', 'created_at', 'updated_at',
        ], $links, false, true);
        $relations = [];
        foreach ($inserted['ids'] as $offset => $linkId) {
            $contentId = $contentIds[$offset];
            $relations[] = [$contentId, $linkId, 0, $contentCreated[$contentId]];
        }
        $relationResult = $this->writer->insert('content_links', ['content_id', 'link_id', 'position_index', 'created_at'], $relations, true);
        $last = (int) end($posts)['id'];

        return $this->result($cursor, $last, $total, count($links) + count($relations), [
            'links' => $inserted['affected'],
            'content_links' => $relationResult['affected'],
        ]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function postLikesBatch(int $cursor): array
    {
        $posts = $this->postsAfter($cursor);
        $total = $this->maximumId('content');
        $allUsers = $this->maximumId('users');
        if ($posts === []) {
            return $this->result($cursor, $total, $total, 0, []);
        }
        $likes = [];
        $notifications = [];
        foreach ($posts as $post) {
            $contentId = (int) $post['id'];
            $authorId = (int) $post['author_id'];
            $count = $this->generator->integer('post-like-count', (string) $contentId, $this->options->minPostLikes, $this->options->maxPostLikes);
            $actors = $this->generator->uniqueIntegers('post-like-actors', (string) $contentId, $count, 1, $allUsers, $authorId);
            foreach ($actors as $actorId) {
                $created = $this->generator->dateAfter('post-like-date', $contentId . ':' . $actorId, (string) $post['created_at']);
                $likes[] = [$contentId, $actorId, $created];
                if ($this->generator->chance('post-like-notification', $contentId . ':' . $actorId, 35)) {
                    $notifications[] = $this->notificationRow($authorId, $actorId, $contentId, null, 'content_like', 'content_like:' . $contentId . ':' . $actorId, $created);
                }
            }
        }
        $likeResult = $this->writer->insert('content_likes', ['content_id', 'user_id', 'created_at'], $likes, true);
        $notificationResult = $this->writer->insert('notifications', $this->notificationColumns(), $notifications, true);
        $last = (int) end($posts)['id'];

        return $this->result($cursor, $last, $total, count($likes) + count($notifications), [
            'post_likes' => $likeResult['affected'],
            'notifications' => $notificationResult['affected'],
        ]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function commentsBatch(int $cursor): array
    {
        $posts = $this->postsAfter($cursor);
        $total = $this->maximumId('content');
        $allUsers = $this->maximumId('users');
        if ($posts === []) {
            return $this->result($cursor, $total, $total, 0, []);
        }

        $rootRows = [];
        $rootMeta = [];
        $levelCounts = [];
        foreach ($posts as $post) {
            $contentId = (int) $post['id'];
            $commentCount = $this->generator->integer('comment-count', (string) $contentId, $this->options->minComments, $this->options->maxComments);
            $rootCount = $commentCount > 0 ? max(1, (int) ceil($commentCount * 0.30)) : 0;
            $remaining = $commentCount - $rootCount;
            $levelTwo = $remaining > 0 ? max(1, (int) ceil($remaining * 0.45)) : 0;
            $afterTwo = $remaining - $levelTwo;
            $levelThree = $afterTwo > 0 ? max(1, (int) ceil($afterTwo * 0.60)) : 0;
            $levelFour = $afterTwo - $levelThree;
            $levelCounts[$contentId] = [$levelTwo, $levelThree, $levelFour];
            for ($index = 1; $index <= $rootCount; $index++) {
                $key = $contentId . ':root:' . $index;
                $actorId = $this->actor('comment-root-actor', $key, $allUsers, (int) $post['author_id']);
                $created = $this->generator->dateAfter('comment-root-date', $key, (string) $post['created_at']);
                $body = $this->generator->pick('comment-root-body', $key, self::COMMENTS);
                $rootRows[] = [$contentId, null, $actorId, $body, $created];
                $rootMeta[] = ['post' => $post, 'actor_id' => $actorId, 'created_at' => $created];
            }
        }

        $rootResult = $this->writer->insert('content_comments', ['content_id', 'parent_id', 'user_id', 'body', 'created_at'], $rootRows, false, true);
        $rootByPost = [];
        $notifications = [];
        foreach ($rootResult['ids'] as $offset => $commentId) {
            $meta = $rootMeta[$offset];
            $post = $meta['post'];
            $rootByPost[(int) $post['id']][] = ['id' => $commentId, 'actor_id' => $meta['actor_id'], 'created_at' => $meta['created_at']];
            if ((int) $post['author_id'] !== (int) $meta['actor_id']) {
                $notifications[] = $this->notificationRow((int) $post['author_id'], (int) $meta['actor_id'], (int) $post['id'], $commentId, 'content_comment', 'content_comment:' . $commentId, (string) $meta['created_at']);
            }
        }

        $levelTwoRows = [];
        $levelTwoMeta = [];
        foreach ($posts as $post) {
            $contentId = (int) $post['id'];
            [$levelTwo] = $levelCounts[$contentId];
            $parents = $rootByPost[$contentId] ?? [];
            for ($index = 1; $index <= $levelTwo && $parents !== []; $index++) {
                $key = $contentId . ':reply:' . $index;
                $parent = $parents[$this->generator->integer('comment-parent', $key, 0, count($parents) - 1)];
                $actorId = $this->actor('comment-reply-actor', $key, $allUsers, (int) $parent['actor_id']);
                $created = $this->generator->dateAfter('comment-reply-date', $key, (string) $parent['created_at']);
                $body = '@' . $this->username((int) $parent['actor_id']) . ' ' . $this->generator->pick('comment-reply-body', $key, self::REPLIES);
                $levelTwoRows[] = [$contentId, (int) $parent['id'], $actorId, $body, $created];
                $levelTwoMeta[] = ['post' => $post, 'parent' => $parent, 'actor_id' => $actorId, 'created_at' => $created];
            }
        }
        $levelTwoResult = $this->writer->insert('content_comments', ['content_id', 'parent_id', 'user_id', 'body', 'created_at'], $levelTwoRows, false, true);
        $levelTwoByPost = [];
        foreach ($levelTwoResult['ids'] as $offset => $commentId) {
            $meta = $levelTwoMeta[$offset];
            $post = $meta['post'];
            $levelTwoByPost[(int) $post['id']][] = ['id' => $commentId, 'actor_id' => $meta['actor_id'], 'created_at' => $meta['created_at']];
            if ((int) $meta['parent']['actor_id'] !== (int) $meta['actor_id']) {
                $notifications[] = $this->notificationRow((int) $meta['parent']['actor_id'], (int) $meta['actor_id'], (int) $post['id'], $commentId, 'content_comment', 'content_comment_reply:' . $commentId, (string) $meta['created_at']);
            }
        }

        $levelThreeRows = [];
        $levelThreeMeta = [];
        foreach ($posts as $post) {
            $contentId = (int) $post['id'];
            [, $levelThree] = $levelCounts[$contentId];
            $parents = $levelTwoByPost[$contentId] ?? [];
            for ($index = 1; $index <= $levelThree && $parents !== []; $index++) {
                $key = $contentId . ':nested:' . $index;
                $parent = $parents[$this->generator->integer('comment-nested-parent', $key, 0, count($parents) - 1)];
                $actorId = $this->actor('comment-nested-actor', $key, $allUsers, (int) $parent['actor_id']);
                $created = $this->generator->dateAfter('comment-nested-date', $key, (string) $parent['created_at']);
                $body = '@' . $this->username((int) $parent['actor_id']) . ' ' . $this->generator->pick('comment-nested-body', $key, self::REPLIES);
                $levelThreeRows[] = [$contentId, (int) $parent['id'], $actorId, $body, $created];
                $levelThreeMeta[] = ['post' => $post, 'parent' => $parent, 'actor_id' => $actorId, 'created_at' => $created];
            }
        }
        $levelThreeResult = $this->writer->insert('content_comments', ['content_id', 'parent_id', 'user_id', 'body', 'created_at'], $levelThreeRows, false, true);
        $levelThreeByPost = [];
        foreach ($levelThreeResult['ids'] as $offset => $commentId) {
            $meta = $levelThreeMeta[$offset];
            $levelThreeByPost[(int) $meta['post']['id']][] = ['id' => $commentId, 'actor_id' => $meta['actor_id'], 'created_at' => $meta['created_at']];
            if ((int) $meta['parent']['actor_id'] !== (int) $meta['actor_id']) {
                $notifications[] = $this->notificationRow((int) $meta['parent']['actor_id'], (int) $meta['actor_id'], (int) $meta['post']['id'], $commentId, 'content_comment', 'content_comment_nested:' . $commentId, (string) $meta['created_at']);
            }
        }

        $levelFourRows = [];
        $levelFourMeta = [];
        foreach ($posts as $post) {
            $contentId = (int) $post['id'];
            [, , $levelFour] = $levelCounts[$contentId];
            $parents = $levelThreeByPost[$contentId] ?? [];
            for ($index = 1; $index <= $levelFour && $parents !== []; $index++) {
                $key = $contentId . ':deep:' . $index;
                $parent = $parents[$this->generator->integer('comment-deep-parent', $key, 0, count($parents) - 1)];
                $actorId = $this->actor('comment-deep-actor', $key, $allUsers, (int) $parent['actor_id']);
                $created = $this->generator->dateAfter('comment-deep-date', $key, (string) $parent['created_at']);
                $body = '@' . $this->username((int) $parent['actor_id']) . ' ' . $this->generator->pick('comment-deep-body', $key, self::REPLIES);
                $levelFourRows[] = [$contentId, (int) $parent['id'], $actorId, $body, $created];
                $levelFourMeta[] = ['post' => $post, 'parent' => $parent, 'actor_id' => $actorId, 'created_at' => $created];
            }
        }
        $levelFourResult = $this->writer->insert('content_comments', ['content_id', 'parent_id', 'user_id', 'body', 'created_at'], $levelFourRows, false, true);
        foreach ($levelFourResult['ids'] as $offset => $commentId) {
            $meta = $levelFourMeta[$offset];
            if ((int) $meta['parent']['actor_id'] !== (int) $meta['actor_id']) {
                $notifications[] = $this->notificationRow((int) $meta['parent']['actor_id'], (int) $meta['actor_id'], (int) $meta['post']['id'], $commentId, 'content_comment', 'content_comment_deep:' . $commentId, (string) $meta['created_at']);
            }
        }
        $notificationResult = $this->writer->insert('notifications', $this->notificationColumns(), $notifications, true);
        $last = (int) end($posts)['id'];
        $comments = count($rootRows) + count($levelTwoRows) + count($levelThreeRows) + count($levelFourRows);

        return $this->result($cursor, $last, $total, $comments + count($notifications), [
            'comments' => $rootResult['affected'] + $levelTwoResult['affected'] + $levelThreeResult['affected'] + $levelFourResult['affected'],
            'notifications' => $notificationResult['affected'],
        ]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function commentLikesBatch(int $cursor): array
    {
        $comments = $this->commentsAfter($cursor);
        $total = $this->maximumId('content_comments');
        $allUsers = $this->maximumId('users');
        if ($comments === []) {
            return $this->result($cursor, $total, $total, 0, []);
        }
        $likes = [];
        $notifications = [];
        foreach ($comments as $comment) {
            $commentId = (int) $comment['id'];
            $authorId = (int) $comment['user_id'];
            $count = $this->generator->integer('comment-like-count', (string) $commentId, $this->options->minCommentLikes, $this->options->maxCommentLikes);
            $actors = $this->generator->uniqueIntegers('comment-like-actors', (string) $commentId, $count, 1, $allUsers, $authorId);
            foreach ($actors as $actorId) {
                $created = $this->generator->dateAfter('comment-like-date', $commentId . ':' . $actorId, (string) $comment['created_at']);
                $likes[] = [$commentId, $actorId, $created];
                if ($this->generator->chance('comment-like-notification', $commentId . ':' . $actorId, 50)) {
                    $notifications[] = $this->notificationRow($authorId, $actorId, (int) $comment['content_id'], $commentId, 'comment_like', 'comment_like:' . $commentId . ':' . $actorId, $created);
                }
            }
        }
        $likeResult = $this->writer->insert('comment_likes', ['comment_id', 'user_id', 'created_at'], $likes, true);
        $notificationResult = $this->writer->insert('notifications', $this->notificationColumns(), $notifications, true);
        $last = (int) end($comments)['id'];

        return $this->result($cursor, $last, $total, count($likes) + count($notifications), [
            'comment_likes' => $likeResult['affected'],
            'notifications' => $notificationResult['affected'],
        ]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function reportsBatch(int $cursor): array
    {
        $posts = $this->postsAfter($cursor);
        $total = $this->maximumId('content');
        $allUsers = $this->maximumId('users');
        if ($posts === []) {
            return $this->result($cursor, $total, $total, 0, []);
        }
        $rows = [];
        $reasons = ['spam', 'harassment', 'privacy', 'other'];
        foreach ($posts as $post) {
            $contentId = (int) $post['id'];
            if (!$this->generator->chance('report-post', (string) $contentId, 3)) {
                continue;
            }
            $reporter = $this->actor('reporter', (string) $contentId, $allUsers, (int) $post['author_id']);
            $status = $this->generator->pick('report-status', (string) $contentId, ['open', 'resolved', 'dismissed']);
            $created = $this->generator->dateAfter('report-date', (string) $contentId, (string) $post['created_at']);
            $reviewed = $status === 'open' ? null : $this->generator->dateAfter('report-review', (string) $contentId, $created);
            $rows[] = [
                $contentId,
                $reporter,
                $this->generator->pick('report-reason', (string) $contentId, $reasons),
                'Deterministic benchmark moderation sample.',
                $status,
                $created,
                $reviewed,
                $reviewed === null ? null : 1,
                $reviewed === null ? null : 'Reviewed benchmark report.',
            ];
        }
        $inserted = $this->writer->insert('content_reports', [
            'content_id', 'reporter_id', 'reason', 'note', 'status', 'created_at', 'reviewed_at', 'reviewed_by', 'action_note',
        ], $rows, true);
        $last = (int) end($posts)['id'];

        return $this->result($cursor, $last, $total, count($rows), ['reports' => $inserted['affected']]);
    }

    /** @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>} */
    private function settingsBatch(int $cursor): array
    {
        if ($cursor > 0) {
            return $this->result($cursor, 1, 1, 0, []);
        }
        $this->schema->configureSettings();

        return $this->result(0, 1, 1, 5, ['settings' => 5]);
    }

    /** @return list<array<string, mixed>> */
    private function usersAfter(int $cursor): array
    {
        return $this->fetchAfter('users', 'id, username, created_at', "role = 'user'", $cursor);
    }

    /** @return list<array<string, mixed>> */
    private function postsAfter(int $cursor): array
    {
        return $this->fetchAfter('content', 'id, body, author_id, created_at', '1 = 1', $cursor);
    }

    /** @return list<array<string, mixed>> */
    private function commentsAfter(int $cursor): array
    {
        return $this->fetchAfter('content_comments', 'id, content_id, user_id, created_at', '1 = 1', $cursor);
    }

    /** @return list<array<string, mixed>> */
    private function fetchAfter(string $table, string $columns, string $condition, int $cursor): array
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('Unsafe batch table.');
        }
        $statement = $this->database->prepare(
            'SELECT ' . $columns . ' FROM `' . $table . '` WHERE ' . $condition . ' AND id > ? ORDER BY id LIMIT ?'
        );
        $statement->bindValue(1, $cursor, PDO::PARAM_INT);
        $statement->bindValue(2, $this->options->batchSize, PDO::PARAM_INT);
        $statement->execute();

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function maximumId(string $table, string $condition = '1 = 1'): int
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('Unsafe maximum-ID table.');
        }

        $statement = $this->database->query('SELECT COALESCE(MAX(id), 0) FROM `' . $table . '` WHERE ' . $condition);
        if ($statement === false) {
            throw new RuntimeException('Unable to read maximum table ID.');
        }

        return (int) $statement->fetchColumn();
    }

    private function tableCount(string $table): int
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new RuntimeException('Unsafe count table.');
        }

        $statement = $this->database->query('SELECT COUNT(*) FROM `' . $table . '`');
        if ($statement === false) {
            throw new RuntimeException('Unable to count table rows.');
        }

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, int> */
    private function termIds(): array
    {
        if ($this->termIds !== []) {
            return $this->termIds;
        }
        $statement = $this->database->query('SELECT id, name FROM terms ORDER BY id');
        if ($statement === false) {
            throw new RuntimeException('Unable to read deterministic tags.');
        }
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $term) {
            $this->termIds[(string) $term['name']] = (int) $term['id'];
        }
        foreach (self::TAGS as $tag) {
            if (!isset($this->termIds[$tag])) {
                throw new RuntimeException('Missing deterministic tag: ' . $tag);
            }
        }

        return $this->termIds;
    }

    private function actor(string $scope, string $key, int $maximum, int $excluded): int
    {
        $actor = $this->generator->integer($scope, $key, 1, $maximum);
        if ($actor === $excluded) {
            $actor = $actor >= $maximum ? 1 : $actor + 1;
        }

        return $actor;
    }

    private function username(int $userId): string
    {
        if ($userId <= 1) {
            return $this->options->prefix . '_admin';
        }

        return $this->options->prefix . str_pad((string) ($userId - 1), 6, '0', STR_PAD_LEFT);
    }

    /** @return list<string> */
    private function notificationColumns(): array
    {
        return ['user_id', 'actor_id', 'content_id', 'comment_id', 'type', 'notification_key', 'read_at', 'created_at', 'updated_at'];
    }

    /** @return list<mixed> */
    private function notificationRow(
        int $userId,
        int $actorId,
        ?int $contentId,
        ?int $commentId,
        string $type,
        string $key,
        string $created,
    ): array {
        $readAt = $this->generator->chance('notification-read', $key, 35)
            ? $this->generator->dateAfter('notification-read-date', $key, $created)
            : null;

        return [$userId, $actorId, $contentId, $commentId, $type, $key, $readAt, $created, $created];
    }

    /**
     * @param array<string, int> $stats
     * @return array{previous_cursor: int, cursor: int, total: int, rows: int, done: bool, stats: array<string, int>}
     */
    private function result(int $previous, int $cursor, int $total, int $rows, array $stats): array
    {
        return [
            'previous_cursor' => $previous,
            'cursor' => $cursor,
            'total' => $total,
            'rows' => $rows,
            'done' => $cursor >= $total,
            'stats' => $stats,
        ];
    }

    private function nextPhase(string $phase): string
    {
        $index = array_search($phase, self::PHASES, true);
        if (!is_int($index) || !isset(self::PHASES[$index + 1])) {
            return 'complete';
        }

        return self::PHASES[$index + 1];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, int> $delta
     * @return array<string, int>
     */
    private function mergeStats(array $current, array $delta): array
    {
        $stats = [];
        foreach ($current as $key => $value) {
            $stats[(string) $key] = (int) $value;
        }
        foreach ($delta as $key => $value) {
            $stats[$key] = ($stats[$key] ?? 0) + $value;
        }
        ksort($stats);

        return $stats;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function report(array $state): array
    {
        $started = strtotime((string) ($state['started_at'] ?? '')) ?: time();
        $completed = strtotime((string) ($state['completed_at'] ?? '')) ?: time();
        $counts = [];
        foreach ([
            'users', 'content', 'terms', 'content_tags', 'links', 'content_links', 'content_likes',
            'content_comments', 'comment_likes', 'user_followers', 'notifications',
            'content_reports',
        ] as $table) {
            $counts[$table] = $this->tableCount($table);
        }
        $depthStatement = $this->database->query(
            'SELECT COUNT(*) FROM content_comments child
             INNER JOIN content_comments parent ON parent.id = child.parent_id
             INNER JOIN content_comments grandparent ON grandparent.id = parent.parent_id
             WHERE grandparent.parent_id IS NULL'
        );
        if ($depthStatement === false) {
            throw new RuntimeException('Unable to count nested comments.');
        }
        $depth = (int) $depthStatement->fetchColumn();
        $deepStatement = $this->database->query(
            'SELECT COUNT(*) FROM content_comments child
             INNER JOIN content_comments parent ON parent.id = child.parent_id
             INNER JOIN content_comments grandparent ON grandparent.id = parent.parent_id
             WHERE grandparent.parent_id IS NOT NULL'
        );
        if ($deepStatement === false) {
            throw new RuntimeException('Unable to count deep comments.');
        }
        $deep = (int) $deepStatement->fetchColumn();
        $relationalRows = array_sum($counts);

        return [
            'ok' => true,
            'complete' => (bool) ($state['complete'] ?? false),
            'database' => $this->databaseName,
            'profile' => $this->options->profile,
            'fingerprint' => $state['fingerprint'] ?? null,
            'batches' => (int) ($state['batches'] ?? 0),
            'duration_seconds' => max(0, $completed - $started),
            'counts' => $counts,
            'relational_rows' => $relationalRows,
            'nested_level_three_comments' => $depth,
            'nested_level_four_comments' => $deep,
            'memory_limit' => (string) ini_get('memory_limit'),
            'peak_memory_bytes' => max((int) ($state['peak_memory_bytes'] ?? 0), memory_get_peak_usage(true)),
            'stats' => (array) ($state['stats'] ?? []),
            'configuration' => $state['configuration'] ?? [],
            'state_path' => $this->options->statePath,
        ];
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): void
    {
        $directory = dirname($this->options->reportPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create report directory.');
        }
        $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($this->options->reportPath, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write demo-import report.');
        }
    }

    private function clearFilesystemCache(): void
    {
        $root = dirname($this->options->configPath);
        $cache = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cache)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cache, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if (in_array($entry->getFilename(), ['.gitkeep', '.htaccess'], true)) {
                continue;
            }
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
    }
}
