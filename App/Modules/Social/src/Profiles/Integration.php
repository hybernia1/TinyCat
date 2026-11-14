<?php
declare(strict_types=1);
namespace TinyCat\Social\Profiles;

/** The author directory and relationships do not select a publishing product. */
final class Integration
{
    /** @param array<string, callable> $policies */
    public static function extend(array $policies): void
    {
        if (array_intersect_key(self::$policies, $policies) !== []) { throw new \LogicException('Duplicate user integration provider.'); }
        self::$policies += $policies;
    }
    /** @var array<string, callable> */
    private static array $policies = [];
    /** @param array<string, callable> $policies */
    public static function configure(array $policies): void { self::$policies = $policies; }

    public static function author(?int $id): void
    {
        if (isset(self::$policies['author'])) { (self::$policies['author'])($id); return; }
        \TinyCat\Http\Document::notFound('Author page not configured.');
    }

    /** @return array<string, mixed>|null */
    public static function authorData(?int $id): ?array { return isset(self::$policies['author_data']) ? (self::$policies['author_data'])($id) : null; }

    public static function feed(int $id): void
    {
        if (isset(self::$policies['feed'])) { (self::$policies['feed'])($id); return; }
        \TinyCat\Http\Document::notFound('Author feed not configured.');
    }

    /** @return array<string, int> */
    public static function activity(int $id): array
    {
        return isset(self::$policies['activity']) ? (self::$policies['activity'])($id) : ['posts' => 0, 'likes_given' => 0, 'likes_received' => 0, 'comments' => 0];
    }

    /** @param array<mixed> $actor */
    public static function followed(int $id, array $actor): void { if (isset(self::$policies['followed'])) { (self::$policies['followed'])($id, $actor); } }
}
