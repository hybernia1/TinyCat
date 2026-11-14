<?php
declare(strict_types=1);
namespace TinyCat\Core\Text;

/** Explicit rendering hooks; this registry has no storage, publication or user queries. */
final class Policies
{
    /** @var array<string, TextPolicy> */
    private static array $policies = [];
    /** @var array<string, string> */
    private static array $owners = [];

    public static function provide(string $owner, string $name, TextPolicy $policy): void
    {
        if ($owner === '' || preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D', $name) !== 1) { throw new \InvalidArgumentException('Invalid text rendering policy.'); }
        if (isset(self::$owners[$name]) && self::$owners[$name] !== $owner) { throw new \LogicException('Duplicate text-policy owner: ' . $name); }
        self::$owners[$name] = $owner; self::$policies[$name] = $policy;
    }

    public static function forget(string $name): void { unset(self::$policies[$name], self::$owners[$name]); }
    public static function policy(string $name): TextPolicy { return self::$policies[$name] ?? new TextPolicy(); }
}
