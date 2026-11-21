<?php
declare(strict_types=1);
namespace TinyCat\Social;

use TinyCat\Core\Modules\Contributions;
use TinyCat\Core\Modules\ModuleSchema;
use TinyCat\Core\Modules\Registries;
use TinyCat\Http\Api;

/** The complete social application: one activation, one resource owner, one initialization. */
final class Module implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesManagedSchemas, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Identity\ProvidesLifecycle, \TinyCat\Core\Modules\ProvidesFileReferences
{
    public static function initialize(): void
    {
        Posts\Registration::initialize();
        Moderation\ActionPolicy::register();
        Site\Registration::initialize();
    }

    public static function contributions(): Contributions
    {
        return (new Registries([
            'profiles' => Profiles\Registration::contributions(),
            'posts' => Posts\Registration::contributions(),
            'bots' => Bots\Registration::contributions(),
            'moderation' => Moderation\Registration::contributions(),
            'search' => Search\Registration::contributions(),
            'site' => Site\Registration::contributions(),
        ]))->entries;
    }

    /** @return array<string, ModuleSchema> */
    public static function schemas(): array
    {
        return [
            'tinycat.publishing' => Posts\Schema::schema(),
            'tinycat.status' => Discussion\Schema::schema(),
            'tinycat.users' => Profiles\Schema::schema(),
            'tinycat.moderation' => Moderation\Schema::schema(),
            'tinycat.bots' => Bots\Schema::schema(),
        ];
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return array_merge(...array_map(static fn (ModuleSchema $schema): array => $schema->tables, array_values(self::schemas())));
    }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant
    {
        return new \TinyCat\Core\Identity\Participant(prepareDelete: static function (array $user): \TinyCat\Core\Identity\Deletion {
            Posts\NotificationHistory::prepareAccountDelete($user);
            return Posts\AccountLifecycle::prepareDelete($user);
        }, statusChanged: Bots\AccountLifecycle::accountStatusChanged(...));
    }

    public static function register(Api $api): void
    {
        Profiles\Registration::register($api);
        Posts\Registration::register($api);
        Moderation\Registration::register($api);
        Bots\Registration::register($api);
        Site\Registration::register($api);
        Search\Registration::register($api);
    }

    public static function fileReferences(string $group, array $candidates): array
    {
        return Posts\FileReferences::find($group, $candidates);
    }
}
