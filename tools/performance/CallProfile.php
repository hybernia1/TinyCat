<?php
declare(strict_types=1);

namespace TinyCat\Tools\Performance;

/** Used only in an instrumented disposable application export. */
final class CallProfile
{
    /** @var list<array{name: string, start: int, children: int}> */
    private static array $stack = [];
    /** @var array<string, array{calls: int, inclusive_ns: int, self_ns: int}> */
    private static array $methods = [];

    public static function enter(string $method): void
    {
        self::$stack[] = ['name' => $method, 'start' => hrtime(true), 'children' => 0];
    }

    public static function leave(): void
    {
        $end = hrtime(true);
        $frame = array_pop(self::$stack);
        if ($frame === null) { throw new \LogicException('Unbalanced profiling scope.'); }
        $elapsed = $end - $frame['start'];
        $method = $frame['name'];
        self::$methods[$method] ??= ['calls' => 0, 'inclusive_ns' => 0, 'self_ns' => 0];
        self::$methods[$method]['calls']++;
        self::$methods[$method]['inclusive_ns'] += $elapsed;
        self::$methods[$method]['self_ns'] += $elapsed - $frame['children'];
        if (self::$stack !== []) { self::$stack[array_key_last(self::$stack)]['children'] += $elapsed; }
    }

    /** @return array<string, array{calls: int, inclusive_ms: float, self_ms: float}> */
    public static function report(): array
    {
        $report = [];
        foreach (self::$methods as $method => $data) {
            $report[$method] = ['calls' => $data['calls'], 'inclusive_ms' => round($data['inclusive_ns'] / 1000000, 3), 'self_ms' => round($data['self_ns'] / 1000000, 3)];
        }
        uasort($report, static fn (array $a, array $b): int => $b['self_ms'] <=> $a['self_ms']);
        return $report;
    }
}
