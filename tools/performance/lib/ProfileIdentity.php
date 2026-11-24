<?php
declare(strict_types=1);
namespace TinyCat\Tools\Performance;

/** Seed only isolated benchmark sessions; dispatch still resolves identity itself. */
final class ProfileIdentity
{
    /** @return array{ms: float, queries: list<array{sql: string, ms: float}>} */
    public static function seed(int $actorId, ProfileDatabase $database): array
    {
        if ($actorId < 0) { throw new \RuntimeException('Profiling actor ID must be nonnegative.'); }
        if ($actorId === 0) { return ['ms' => 0.0, 'queries' => []]; }
        $started = hrtime(true);
        $before = count($database->queries);
        if (!\TinyCat\Core\Identity\Authentication::authLogin($actorId)) { throw new \RuntimeException('Profiling account cannot authenticate.'); }
        \TinyCat\Core\Identity\Authentication::reset();
        return ['ms' => round((hrtime(true) - $started) / 1_000_000, 3), 'queries' => array_splice($database->queries, $before)];
    }
}
