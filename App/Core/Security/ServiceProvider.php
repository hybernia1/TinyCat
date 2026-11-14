<?php
declare(strict_types=1);
namespace TinyCat\Core\Security;

final class ServiceProvider implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Modules\ProvidesManagedSchema
{
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema { return Schema::schema(); }

    public static function initialize(): void
    {
        Captcha::configure(\TinyCat\Core\Runtime\Startup::option(...));
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(settings: Settings::sections(), maintenance: [
            \TinyCat\Database\Retention::task('old_action_limits', 'ip_action_limits', 'bucket_start < ?', static fn (): array => [\TinyCat\Support\Dates::dateDb(time() - RateLimitPolicy::retentionSeconds())], 90),
        ]);
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return ['ip_action_limits'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        // Security provides technical services; domain challenge routes belong to their consumers.
    }
}
