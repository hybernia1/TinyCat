<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

final class Registration
{

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(
            settings: Settings::sections(),
            configOverrides: ['moderation.blocked_urls', 'content_links.utm.enabled', 'content_links.utm.source', 'content_links.utm.medium', 'content_links.utm.campaign'],
            temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('publication-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/status-images'), 20),
            new \TinyCat\Core\Modules\TemporaryDirectory('link-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/links'), 30),
            ],
            maintenance: [
            \TinyCat\Database\Retention::task('orphan_terms', 'social_terms', 'NOT EXISTS (SELECT 1 FROM social_post_tags ct WHERE ct.term_id = social_terms.id)', static fn (): array => [], 10),
            \TinyCat\Database\Retention::task('orphan_links', 'social_links', 'NOT EXISTS (SELECT 1 FROM social_post_links cl WHERE cl.link_id = social_links.id)', static fn (): array => [], 20),
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_status_image_files', static fn (int $batch): array => ImageStorage::cleanupOrphans($batch), 30),
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_link_image_files', static fn (int $batch): array => Links::cleanupOrphanImages($batch), 40),
            ],
            embeds: [new \TinyCat\Core\Modules\EmbedSource('social.posts', static fn (string $path, int $width, int $height): ?array => Embed::data($path, $width, $height))],
            sitemaps: [new \TinyCat\Core\Modules\SitemapSection('status', static fn (): int => SitemapData::count('status'), static fn (int $limit, int $offset): array => SitemapData::entries('status', $limit, $offset), 30), new \TinyCat\Core\Modules\SitemapSection('tags', static fn (): int => SitemapData::count('tags'), static fn (int $limit, int $offset): array => SitemapData::entries('tags', $limit, $offset), 40)],
            homepages: [new \TinyCat\Core\Modules\EntryPage('tinycat.status.home', '/social', static function (): void { Pages::home(); }, 10, static fn (): string => \TinyCat\Support\Localization::t('public.feed_title'), 'message-circle')],
            notificationTypes: NotificationPresentation::types(),
        );
    }

    public static function initialize(): void
    {
        \TinyCat\Social\Discussion\Interactions::invalidate();
        \TinyCat\Core\Text\Policies::provide('tinycat.social', 'post', new \TinyCat\Core\Text\TextPolicy(Mentions::normalize_mentions_for_storage(...), InlinePresentation::segments(...), ContentLinks::navigation(...)));
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed', static fn (string $id) => Embed::page((int) $id), contentType: 'text/plain');
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed-code', static fn (string $id) => Embed::page((int) $id, true), contentType: 'text/plain');

        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/tag/{tag}/feed', static fn (string $tag) => Syndication::respond('tag', $tag), contentType: 'text/plain');

        foreach ([
            ['GET', '/status', static function (?int $statusId = null): void { Pages::detail($statusId); }],
            ['GET', '/tag', static function (?string $tag = null): void { Pages::tag($tag); }],
        ] as [$methods, $path, $handler]) {
            \TinyCat\Http\Router::route($methods, $path, $handler);
        }
    }
}
