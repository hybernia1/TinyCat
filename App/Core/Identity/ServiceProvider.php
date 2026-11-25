<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Participants;
use TinyCat\Core\Identity\Integration;
use TinyCat\Core\Identity\Setup;

final class ServiceProvider implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Identity\ProvidesLifecycle, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Modules\ProvidesSchemas, \TinyCat\Core\Modules\ProvidesSetup
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function validateSetup(array $input): array { return Setup::input($input); }
    /** @param array<string, mixed> $input */
    public static function completeSetup(array $input): void
    {
        $data = Setup::input($input + ['password_confirm' => $input['password'] ?? '']);
        Setup::administrator($data['username'], $data['password'], $data['locale']);
    }
    public static function schemas(): array { return ['tinycat.accounts' => Schema::schema()]; }

    public static function initialize(): void
    {
        Integration::configure([]);
        Participants::configure(\TinyCat\Core\Modules\Loader::fromCatalog(\TinyCat\Core\Modules\Resources::catalog()));
        \TinyCat\Core\Runtime\Startup::$identity = static fn (): ?array => Authentication::auth();
        \TinyCat\Core\Runtime\Startup::$readiness[] = static fn (): bool => self::ready();
    }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant
    {
        return new \TinyCat\Core\Identity\Participant(prepareDelete: static fn (array $user): \TinyCat\Core\Identity\Deletion => new \TinyCat\Core\Identity\Deletion(
            afterCommit: static function () use ($user): void { Avatar::delete((int) $user['id'], $user['avatar_version'] ?? null); },
        ));
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(statistics: [new \TinyCat\Core\Modules\DashboardStats('identity.accounts', static fn (): string => \TinyCat\Support\Localization::t('admin.stats.users'), Administration::dashboardWidget(...), fields: Administration::statisticsFields(...))], homepages: [new \TinyCat\Core\Modules\EntryPage('tinycat.accounts.login', '/login', static function (): void { Pages::homepage(); }, PHP_INT_MAX, static fn (): string => \TinyCat\Support\Localization::t('auth.login_title'), 'login', navigation: false)], settings: Settings::sections(), temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('avatars', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/avatars'), 10),
        ], navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.users.admin', static fn (): array => \TinyCat\Core\Ui\Admin\Definitions::navigation('core.users'), 20)], maintenance: [
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_avatar_files', static fn (int $batch): array => Avatar::cleanupOrphans($batch), 50),
            \TinyCat\Database\Retention::task('old_password_reset_tokens', 'password_reset_tokens', 'used_at IS NOT NULL OR expires_at < ?', static fn (): array => [\TinyCat\Support\Dates::dateDb()], 100),
        ]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/avatar/initials/v3/{initials:.+[.]svg}', static function (string $initials): void { AvatarHttp::initialsText(substr($initials, 0, -4)); });

        \TinyCat\Http\Router::route('GET', '/avatar/{username:' . \TinyCat\Core\Identity\Usernames::pattern() . '}', static function (string $username): void {
            \TinyCat\Core\Identity\AvatarHttp::respond($username, static function (): void { \TinyCat\Core\Runtime\Errors::respond(404); });
        });

        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('core.users'), static function (): void { Pages::administration(); });
        foreach ([
            ['GET', '/account', static function (): void { Pages::account(); }],
            ['GET', '/login', static function (): void { Pages::login(); }],
            ['GET', '/logout', static function (): void { Pages::logout(); }],
            ['GET', '/register', static function (): void { Pages::register(); }],
            ['GET', '/recovery', static function (): void { Pages::recovery(); }],
        ] as [$methods, $path, $handler]) {
            \TinyCat\Http\Router::route($methods, $path, $handler);
        }
    }

    public static function ready(): bool
    {
        try {
            return (int) \TinyCat\Database\Connection::value(
                sprintf(
                    'SELECT 1 FROM %s WHERE %s IS NOT NULL AND %s <> ? LIMIT 1',
                    \TinyCat\Database\Schema::app_sql_identifier('users'),
                    \TinyCat\Database\Schema::app_sql_identifier('password'),
                    \TinyCat\Database\Schema::app_sql_identifier('password')
                ),
                ['']
            ) > 0;
        } catch (\Throwable $failure) { \TinyCat\Core\Runtime\Failure::report($failure);
            return false;
        }
    }
}
