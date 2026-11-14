<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use TinyCat\Core\Links\Providers;

/** Shared storage, policy and export transports; modules own publication routes. */
final class ServiceProvider implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Identity\ProvidesLifecycle, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Modules\ProvidesManagedSchemas
{
    /** @return array<string, \TinyCat\Core\Modules\ModuleSchema> */
    public static function schemas(): array { return ['tinycat.publishing' => Schema::schema(), 'tinycat.status' => InteractionSchema::schema()]; }

    public static function initialize(): void
    {
        Integration::configure();
        Interactions::reset();
        \TinyCat\Core\Runtime\Startup::$frameOrigins = Providers::playerOrigins();
    }

    /** @return list<string> */
    public static function tables(): array { return [...Schema::tables(), ...InteractionSchema::tables()]; }
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/moderation/blocked-urls', Policy::resource(...), 'admin');
        $api->put('/moderation/blocked-urls', Policy::save(...), 'admin');
    }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant
    {
        return new \TinyCat\Core\Identity\Participant(prepareDelete: AccountLifecycle::prepareDelete(...));
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(settings: Settings::sections(), configOverrides: ['moderation.blocked_urls', 'content_links.utm.enabled', 'content_links.utm.source', 'content_links.utm.medium', 'content_links.utm.campaign'], temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('publication-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/status-images'), 20),
            new \TinyCat\Core\Modules\TemporaryDirectory('link-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/links'), 30),
        ], maintenance: [
            \TinyCat\Database\Retention::task('orphan_terms', 'social_terms', 'NOT EXISTS (SELECT 1 FROM social_post_tags ct WHERE ct.term_id = social_terms.id)', static fn (): array => [], 10),
            \TinyCat\Database\Retention::task('orphan_links', 'social_links', 'NOT EXISTS (SELECT 1 FROM social_post_links cl WHERE cl.link_id = social_links.id)', static fn (): array => [], 20),
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_status_image_files', static fn (int $batch): array => ImageStorage::cleanupOrphans($batch), 30),
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_link_image_files', static fn (int $batch): array => Links::cleanupOrphanImages($batch), 40),
        ]);
    }
}
