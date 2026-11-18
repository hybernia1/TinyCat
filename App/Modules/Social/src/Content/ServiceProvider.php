<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Internal Social contributions, HTTP routes and runtime caches. */
final class ServiceProvider
{

    public static function initialize(): void
    {
        Interactions::invalidate();
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/moderation/blocked-urls', Policy::resource(...), 'admin');
        $api->put('/moderation/blocked-urls', Policy::save(...), 'admin');
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
