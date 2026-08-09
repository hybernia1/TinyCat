<?php
declare(strict_types=1);

namespace TinyCat\Tools\DemoImport;

use DateTimeImmutable;
use DateTimeZone;

final readonly class DeterministicGenerator
{
    private int $anchorTimestamp;

    public function __construct(private int $seed, string $anchor)
    {
        $this->anchorTimestamp = (new DateTimeImmutable($anchor, new DateTimeZone('UTC')))->getTimestamp();
    }

    public function integer(string $scope, string $key, int $minimum, int $maximum): int
    {
        if ($maximum <= $minimum) {
            return $minimum;
        }
        $hash = hash('sha256', $this->seed . '|' . $scope . '|' . $key);
        $number = (int) hexdec(substr($hash, 0, 12));

        return $minimum + ($number % ($maximum - $minimum + 1));
    }

    public function chance(string $scope, string $key, int $percent): bool
    {
        return $this->integer($scope, $key, 1, 100) <= max(0, min(100, $percent));
    }

    /**
     * @template T
     * @param non-empty-list<T> $items
     * @return T
     */
    public function pick(string $scope, string $key, array $items): mixed
    {
        return $items[$this->integer($scope, $key, 0, count($items) - 1)];
    }

    /** @return list<int> */
    public function uniqueIntegers(
        string $scope,
        string $key,
        int $count,
        int $minimum,
        int $maximum,
        int $excluded = 0,
    ): array {
        $available = max(0, $maximum - $minimum + 1 - ($excluded >= $minimum && $excluded <= $maximum ? 1 : 0));
        $count = min(max(0, $count), $available);
        $values = [];
        $attempt = 0;

        while (count($values) < $count && $attempt < max(20, $count * 10)) {
            $candidate = $this->integer($scope, $key . ':' . $attempt, $minimum, $maximum);
            $attempt++;
            if ($candidate === $excluded || isset($values[$candidate])) {
                continue;
            }
            $values[$candidate] = true;
        }

        if (count($values) < $count) {
            for ($candidate = $minimum; $candidate <= $maximum && count($values) < $count; $candidate++) {
                if ($candidate !== $excluded) {
                    $values[$candidate] = true;
                }
            }
        }

        return array_map('intval', array_keys($values));
    }

    public function dateBeforeAnchor(string $scope, string $key, int $oldestDays, int $newestDays = 0): string
    {
        $oldest = max($oldestDays, $newestDays);
        $newest = min($oldestDays, $newestDays);
        $timestamp = $this->integer(
            $scope,
            $key,
            $this->anchorTimestamp - ($oldest * 86400),
            $this->anchorTimestamp - ($newest * 86400),
        );

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    public function dateAfter(string $scope, string $key, string $after): string
    {
        $minimum = strtotime($after . ' UTC');
        $minimum = $minimum === false ? $this->anchorTimestamp - 86400 : min($minimum, $this->anchorTimestamp);

        return gmdate('Y-m-d H:i:s', $this->integer($scope, $key, $minimum, $this->anchorTimestamp));
    }
}
