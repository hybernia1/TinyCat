<?php
declare(strict_types=1);

namespace TinyCat\Tools\DemoImport;

use InvalidArgumentException;

final readonly class Options
{
    public function __construct(
        public string $configPath,
        public string $statePath,
        public string $reportPath,
        public string $profile,
        public int $users,
        public int $minPosts,
        public int $maxPosts,
        public int $minComments,
        public int $maxComments,
        public int $minFollows,
        public int $maxFollows,
        public int $minPostLikes,
        public int $maxPostLikes,
        public int $maxCommentLikes,
        public int $batchSize,
        public int $seed,
        public int $maxBatches,
        public string $prefix,
        public string $password,
        public string $anchor,
        public bool $reset,
        public bool $resume,
        public bool $jsonLines,
    ) {
    }

    /** @param list<string> $arguments */
    public static function fromArgv(array $arguments, string $workspace): self
    {
        $raw = self::parse($arguments);

        if (isset($raw['help'])) {
            throw new InvalidArgumentException(self::help());
        }

        $profile = strtolower(trim((string) ($raw['profile'] ?? 'medium')));
        $profiles = [
            'small' => [200, 2, 4, 3, 7, 2, 8, 0, 8, 2, 100],
            'medium' => [3000, 4, 8, 6, 14, 8, 24, 3, 20, 4, 250],
            'large' => [25000, 5, 10, 10, 24, 12, 36, 5, 30, 6, 500],
        ];

        if (!isset($profiles[$profile])) {
            throw new InvalidArgumentException('Profile must be small, medium, or large.');
        }

        [
            $defaultUsers,
            $defaultMinPosts,
            $defaultMaxPosts,
            $defaultMinComments,
            $defaultMaxComments,
            $defaultMinFollows,
            $defaultMaxFollows,
            $defaultMinPostLikes,
            $defaultMaxPostLikes,
            $defaultMaxCommentLikes,
            $defaultBatchSize,
        ] = $profiles[$profile];

        $configPath = self::absolute((string) ($raw['config'] ?? ($workspace . '/config.php')), $workspace);
        if (!is_file($configPath)) {
            throw new InvalidArgumentException('Config file does not exist: ' . $configPath);
        }
        $appRoot = dirname($configPath);
        $statePath = self::absolute((string) ($raw['state'] ?? ($appRoot . '/storage/demo-import-state.json')), $workspace);
        $reportPath = self::absolute((string) ($raw['report'] ?? ($appRoot . '/storage/demo-import-report.json')), $workspace);
        $minPosts = self::integer($raw, 'min-posts', $defaultMinPosts, 1, 50);
        $minComments = self::integer($raw, 'min-comments', $defaultMinComments, 0, 100);
        $minFollows = self::integer($raw, 'min-follows', $defaultMinFollows, 0, 200);
        $minPostLikes = self::integer($raw, 'min-post-likes', $defaultMinPostLikes, 0, 200);
        $prefix = preg_replace('/[^a-z0-9_]+/', '', strtolower(trim((string) ($raw['prefix'] ?? 'bench')))) ?: 'bench';
        $prefix = substr($prefix, 0, 16);
        $password = (string) ($raw['password'] ?? 'tinycat123');

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must contain at least eight characters.');
        }

        $anchor = trim((string) ($raw['anchor'] ?? '2026-08-01 12:00:00'));
        if (strtotime($anchor . ' UTC') === false) {
            throw new InvalidArgumentException('Anchor must be a valid date and time.');
        }

        return new self(
            $configPath,
            $statePath,
            $reportPath,
            $profile,
            self::integer($raw, 'users', $defaultUsers, 1, 100000),
            $minPosts,
            self::integer($raw, 'max-posts', $defaultMaxPosts, $minPosts, 80),
            $minComments,
            self::integer($raw, 'max-comments', $defaultMaxComments, $minComments, 150),
            $minFollows,
            self::integer($raw, 'max-follows', $defaultMaxFollows, $minFollows, 300),
            $minPostLikes,
            self::integer($raw, 'max-post-likes', $defaultMaxPostLikes, $minPostLikes, 300),
            self::integer($raw, 'max-comment-likes', $defaultMaxCommentLikes, 0, 50),
            self::integer($raw, 'batch-size', $defaultBatchSize, 10, 2000),
            self::integer($raw, 'seed', 250, 1, PHP_INT_MAX),
            self::integer($raw, 'max-batches', 0, 0, PHP_INT_MAX),
            $prefix,
            $password,
            $anchor,
            self::boolean($raw, 'reset', true),
            self::boolean($raw, 'resume', true),
            self::boolean($raw, 'jsonl', false),
        );
    }

    /** @return array<string, int|string> */
    public function fingerprintData(): array
    {
        return [
            'import_format' => 1,
            'profile' => $this->profile,
            'users' => $this->users,
            'min_posts' => $this->minPosts,
            'max_posts' => $this->maxPosts,
            'min_comments' => $this->minComments,
            'max_comments' => $this->maxComments,
            'min_follows' => $this->minFollows,
            'max_follows' => $this->maxFollows,
            'min_post_likes' => $this->minPostLikes,
            'max_post_likes' => $this->maxPostLikes,
            'max_comment_likes' => $this->maxCommentLikes,
            'batch_size' => $this->batchSize,
            'seed' => $this->seed,
            'prefix' => $this->prefix,
            'anchor' => $this->anchor,
        ];
    }

    public static function help(): string
    {
        return <<<'TEXT'
TinyCat deterministic demo importer

Usage:
  php tools/demo-import.php --config=/path/config.php [options]

Profiles:
  --profile=small|medium|large        Dataset size preset (default: medium)

Dataset overrides:
  --users=N                          Generated users, excluding admin
  --min-posts=N --max-posts=N        Posts per generated user
  --min-comments=N --max-comments=N  Comments and replies per post
  --min-follows=N --max-follows=N    Follows per generated user
  --min-post-likes=N --max-post-likes=N
  --max-comment-likes=N

Execution:
  --batch-size=N                     Checkpointed source rows per transaction
  --seed=N                           Deterministic dataset seed
  --anchor="YYYY-MM-DD HH:MM:SS"     Stable newest timestamp
  --prefix=bench                     Generated username prefix
  --password=tinycat123              Shared disposable login password
  --reset=1                          Reset benchmark-owned data before import
  --resume=1                         Resume a matching checkpoint
  --max-batches=N                    Stop cleanly after N batches (resume test)
  --state=/path/state.json           Checkpoint path
  --report=/path/report.json         Final report path
  --jsonl=1                          Machine-readable progress events
TEXT;
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string|bool>
     */
    private static function parse(array $arguments): array
    {
        $parsed = [];

        foreach (array_slice($arguments, 1) as $argument) {
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException('Unexpected argument: ' . $argument);
            }
            $option = substr($argument, 2);
            if ($option === '') {
                throw new InvalidArgumentException('Empty option is not allowed.');
            }
            $parts = explode('=', $option, 2);
            $key = $parts[0];
            $value = $parts[1] ?? true;
            $parsed[str_replace('_', '-', strtolower($key))] = $value;
        }

        return $parsed;
    }

    /** @param array<string, string|bool> $values */
    private static function integer(array $values, string $key, int $default, int $minimum, int $maximum): int
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }
        $value = filter_var($values[$key], FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException($key . ' must be between ' . $minimum . ' and ' . $maximum . '.');
        }

        return $value;
    }

    /** @param array<string, string|bool> $values */
    private static function boolean(array $values, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }
        $value = strtolower(trim((string) $values[$key]));
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        throw new InvalidArgumentException($key . ' must be a boolean value.');
    }

    private static function absolute(string $path, string $workspace): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        if ($path === '') {
            throw new InvalidArgumentException('A path option cannot be empty.');
        }
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            $path = rtrim($workspace, '/\\') . DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }
}
