<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Authentication;
use TinyCat\Core\Identity\Readiness;
use TinyCat\Core\Identity\Participants;
use TinyCat\Core\Identity\Integration;
use TinyCat\Core\Identity\Initialization;

final class ServiceProvider implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Identity\ProvidesLifecycle, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Modules\ProvidesManagedSchema, \TinyCat\Core\Modules\ProvidesSetup
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function validateSetup(array $input): array { return Setup::input($input); }
    /** @param array<string, mixed> $input */
    public static function completeSetup(array $input): void
    {
        $data = Setup::input($input + ['password_confirm' => $input['password'] ?? '']);
        Initialization::administrator($data['username'], $data['password'], $data['locale']);
    }
    public static function schema(): \TinyCat\Core\Modules\ModuleSchema { return Schema::schema(); }

    public static function initialize(): void
    {
        Integration::configure([]);
        Participants::configure(\TinyCat\Core\Modules\Loader::fromCatalog(\TinyCat\Core\Modules\Resources::catalog()));
        \TinyCat\Core\Runtime\Startup::$identity = Authentication::auth(...);
        \TinyCat\Core\Runtime\Startup::$readiness[] = Readiness::ready(...);
    }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant
    {
        return new \TinyCat\Core\Identity\Participant(prepareDelete: static fn (array $user): \TinyCat\Core\Identity\Deletion => new \TinyCat\Core\Identity\Deletion(
            afterCommit: static function () use ($user): void { Avatar::delete((int) $user['id']); },
        ));
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(settings: Settings::sections(), temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('avatars', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/avatars'), 10),
        ], navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.users.admin', static fn (): array => [
            'href' => '/admin/users', 'icon' => 'users', 'label' => \TinyCat\Support\Localization::t('users.list_title'),
        ], 20)], maintenance: [
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_avatar_files', static fn (int $batch): array => Avatar::cleanupOrphans($batch), 50),
            \TinyCat\Database\Retention::task('old_password_reset_tokens', 'password_reset_tokens', 'used_at IS NOT NULL OR expires_at < ?', static fn (): array => [\TinyCat\Support\Dates::dateDb()], 100),
        ]);
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return ['users', 'password_reset_tokens'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/avatar/generated/v1/{user_id:[1-9][0-9]*[.]svg}', static function (string $user_id): void {
            GeneratedAvatar::respond((int) $user_id);
        });

        \TinyCat\Http\Router::route('GET', '/avatar/{username:' . \TinyCat\Core\Identity\Usernames::pattern() . '}', static function (string $username): void {
            \TinyCat\Core\Identity\AvatarHttp::respond($username, static function (): void { Integration::notFound(); });
        });

        \TinyCat\Http\Router::route('GET', '/admin/users', Pages::administration(...));
        foreach ([
            ['GET', '/login', Pages::login(...)],
            ['GET', '/logout', Pages::logout(...)],
            ['GET', '/register', Pages::register(...)],
            ['GET', '/recovery', Pages::recovery(...)],
        ] as [$methods, $path, $handler]) {
            \TinyCat\Http\Router::route($methods, $path, $handler);
        }
    }
}
