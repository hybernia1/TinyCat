<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

/** Lazy account extensions owned by Mail and optional modules. Defaults support core-only Identity. */
final class Integration
{
    /** @var array<string, callable> */
    private static array $policies = [];
    /** @var array<string, string> */
    private static array $owners = [];

    /** @param array<string, callable> $policies */
    public static function provide(string $owner, array $policies): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $owner) !== 1) { throw new \InvalidArgumentException('Invalid provider owner.'); }
        foreach ($policies as $key => $_) {
            if (!in_array($key, ['register', 'recovery', 'landing', 'profile', 'next'], true)) { throw new \InvalidArgumentException('Unknown account extension.'); }
            if (isset(self::$owners[$key]) && self::$owners[$key] !== $owner) { throw new \LogicException('Conflicting account extension: ' . $key); }
            $policies[$key] = \Closure::fromCallable($policies[$key]);
        }
        foreach ($policies as $key => $_) { self::$owners[$key] = $owner; }
        self::$policies = array_replace(self::$policies, $policies);
    }

    /** @param array<string, callable> $policies */
    public static function configure(array $policies): void { self::$policies = $policies; self::$owners = []; }

    /** @param array<mixed> $input
     * @return array<mixed> */
    public static function register(array $input): array
    {
        return isset(self::$policies['register']) ? (self::$policies['register'])($input) : Registration::create($input, (bool) \TinyCat\Core\Runtime\Startup::option('auth.registration.auto_approve', false), \TinyCat\Support\Localization::locale());
    }

    public static function sendRecovery(string $email): void
    {
        if (isset(self::$policies['recovery'])) { (self::$policies['recovery'])($email); return; }
        throw new \LogicException('Account recovery requires a configured delivery provider.');
    }

    /** @param array<mixed>|null $user */
    public static function landing(?array $user): string
    {
        return isset(self::$policies['landing']) ? (string) (self::$policies['landing'])($user) : (Roles::isAdmin($user) ? '/admin' : '/');
    }

    public static function profileUrl(int $id): ?string
    {
        return \TinyCat\Core\Ui\PublicWeb::enabled() && isset(self::$policies['profile']) ? (string) (self::$policies['profile'])($id) : null;
    }

    public static function normalizeNext(string $url): string
    {
        return isset(self::$policies['next']) ? (string) (self::$policies['next'])($url) : $url;
    }
}
