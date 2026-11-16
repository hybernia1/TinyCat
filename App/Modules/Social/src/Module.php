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
        Content\ServiceProvider::initialize();
        Profiles\Registration::initialize();
        Posts\Registration::initialize();
        Moderation\ActionPolicy::register();
        Presentation\Registration::initialize();
    }

    public static function contributions(): Contributions
    {
        return (new Registries([
            'content' => Content\ServiceProvider::contributions(),
            'profiles' => Profiles\Registration::contributions(),
            'posts' => Posts\Registration::contributions(),
            'automation' => Automation\Registration::contributions(),
            'moderation' => Moderation\Registration::contributions(),
            'search' => Search\Registration::contributions(),
            'presentation' => Presentation\Registration::contributions(),
        ]))->entries;
    }

    /** @return array<string, ModuleSchema> */
    public static function schemas(): array
    {
        return [
            'tinycat.publishing' => Content\Schema::schema(),
            'tinycat.status' => Content\InteractionSchema::schema(),
            'tinycat.users' => Profiles\Schema::schema(),
            'tinycat.moderation' => Moderation\Schema::schema(),
            'tinycat.bots' => Automation\Schema::schema(),
        ];
    }

    /** @return list<string> */
    public static function tables(): array
    {
        return [...Content\ServiceProvider::tables(), ...Profiles\Registration::tables(), ...Posts\Registration::tables(), ...Moderation\Registration::tables(), ...Automation\Registration::tables()];
    }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant
    {
        return new \TinyCat\Core\Identity\Participant(prepareDelete: static function (array $user): \TinyCat\Core\Identity\Deletion {
            Posts\NotificationCleanup::prepareAccountDelete($user);
            return Content\AccountLifecycle::prepareDelete($user);
        }, statusChanged: Automation\Registration::accountLifecycle()->statusChanged);
    }

    public static function register(Api $api): void
    {
        Content\ServiceProvider::register($api);
        Profiles\Registration::register($api);
        Posts\Registration::register($api);
        Moderation\Registration::register($api);
        Automation\Registration::register($api);
        Presentation\Registration::register($api);
        Search\Registration::register($api);
    }

    public static function fileReferences(string $group, array $candidates): array
    {
        $source = match ($group) { 'raster-images:uploads/status-images' => ['social_post_images', 'path'], 'link-images' => ['social_links', 'image_url'], default => null };
        if ($source === null || $candidates === [] || !\TinyCat\Database\SchemaInspection::exists(\TinyCat\Database\Connection::get(), $source[0])) { return []; }
        $rows = \TinyCat\Database\Connection::all('SELECT ' . $source[1] . ' AS path FROM ' . $source[0] . ' WHERE ' . $source[1] . ' IN (' . implode(', ', array_fill(0, count($candidates), '?')) . ')', $candidates);
        return array_values(array_map(static fn (array $row): string => (string) $row['path'], $rows));
    }
}
