<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

/** Explicit product policies. Defaults support an Accounts-only assembly. */
final class Integration
{
    /** @var array<string, callable> */
    private static array $policies = [];
    /** @var array<string, string> */
    private static array $owners = [];

    /** @param array<string, callable> $policies */
    public static function provide(string $owner, array $policies): void
    {
        foreach ($policies as $key => $_) {
            if (!in_array($key, ['registration', 'register', 'recovery', 'landing', 'profile', 'url', 'path', 'next', 'privacy', 'image', 'render', 'not_found'], true)) { throw new \InvalidArgumentException('Unknown account extension.'); }
            if (isset(self::$owners[$key]) && self::$owners[$key] !== $owner) { throw new \LogicException('Conflicting account extension: ' . $key); }
            self::$owners[$key] = $owner;
        }
        self::$policies = array_replace(self::$policies, $policies);
    }

    /** @param array<string, callable> $policies */
    public static function configure(array $policies): void { self::$policies = $policies; self::$owners = []; }

    public static function registrationEnabled(): bool
    {
        return isset(self::$policies['registration']) ? (bool) (self::$policies['registration'])() : (bool) \TinyCat\Core\Runtime\Startup::option('auth.registration.enabled', false);
    }

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
        return isset(self::$policies['profile']) ? (string) (self::$policies['profile'])($id) : null;
    }

    public static function localUrl(string $path): string
    {
        return isset(self::$policies['url']) ? (string) (self::$policies['url'])($path) : \TinyCat\Support\LocalUrls::resolve($path);
    }

    public static function unlocalizedPath(string $path): string
    {
        return isset(self::$policies['path']) ? (string) (self::$policies['path'])($path) : \TinyCat\Http\LocaleRouting::split($path)['path'];
    }

    public static function normalizeNext(string $url): string
    {
        return isset(self::$policies['next']) ? (string) (self::$policies['next'])($url) : $url;
    }

    public static function metaImage(): string
    {
        return isset(self::$policies['image']) ? (string) (self::$policies['image'])() : \TinyCat\Core\Ui\Site::site_meta_image_url();
    }

    public static function privacyUrl(): string
    {
        return isset(self::$policies['privacy']) ? (string) (self::$policies['privacy'])() : \TinyCat\Http\LocaleRouting::url('/privacy', \TinyCat\Support\Localization::locale());
    }

    public static function notFound(): void
    {
        if (isset(self::$policies['not_found'])) { (self::$policies['not_found'])(); return; }
        \TinyCat\Core\Runtime\Errors::respond(404);
    }

    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        if (isset(self::$policies['render'])) { (self::$policies['render'])($page, $content); return; }
        \TinyCat\Core\Ui\Page::render($page, $content);
    }
}
