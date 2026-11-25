<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** Lazy CSP player sources belonging to an active module. */
final readonly class PlayerOrigins
{
    /** @var \Closure(): list<string> */
    public \Closure $resolve;
    /** @param callable(): list<string> $resolve */
    public function __construct(public string $id, callable $resolve, public int $order = 100) { $this->resolve = \Closure::fromCallable($resolve); }

    /** @param array<mixed> $origins
     * @return list<string> */
    public static function validate(array $origins): array
    {
        foreach ($origins as $origin) {
            if (!is_string($origin) || preg_match('~^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[1-9][0-9]{0,4})?$~D', $origin) !== 1) { throw new \InvalidArgumentException('Invalid CSP player origin.'); }
            $parts = parse_url($origin);
            if ($parts === false || !isset($parts['host']) || !filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) { throw new \InvalidArgumentException('Invalid CSP player origin.'); }
        }
        return array_values(array_unique($origins));
    }

    /** @return list<string> */
    public static function current(): array
    {
        $origins = [];
        foreach (Registrations::current()->entries->playerOrigins as $source) { array_push($origins, ...self::validate(($source->resolve)())); }
        return array_values(array_unique($origins));
    }
}
