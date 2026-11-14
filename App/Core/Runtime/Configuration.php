<?php
declare(strict_types=1);

namespace TinyCat\Core\Runtime;

/** Immutable input values; no files, database, settings or session. */
final readonly class Configuration
{
    /** @param array<mixed> $values */
    public function __construct(private array $values = []) {}

    /** @return array<mixed> */
    public function values(): array { return $this->values; }

    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') { return $this->values; }
        if (array_key_exists($key, $this->values)) { return $this->values[$key]; }
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) { return $default; }
            $value = $value[$segment];
        }
        return $value;
    }
}
