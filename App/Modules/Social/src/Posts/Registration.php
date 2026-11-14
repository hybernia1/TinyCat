<?php
declare(strict_types=1);
namespace TinyCat\Social\Posts;

final class Registration implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Modules\ProvidesContributions
{

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(embeds: [new \TinyCat\Core\Modules\EmbedSource('social.posts', Embed::data(...))], sitemaps: [new \TinyCat\Core\Modules\SitemapSection('status', static fn (): int => SitemapData::count('status'), static fn (int $limit, int $offset): array => SitemapData::entries('status', $limit, $offset), 30), new \TinyCat\Core\Modules\SitemapSection('tags', static fn (): int => SitemapData::count('tags'), static fn (int $limit, int $offset): array => SitemapData::entries('tags', $limit, $offset), 40)], homepages: [new \TinyCat\Core\Modules\EntryPage('tinycat.status.home', '/social', Pages::home(...), 10, static fn (): string => \TinyCat\Support\Localization::t('public.feed_title'), 'message-circle')], notificationTypes: NotificationPresentation::types());
    }

    public static function initialize(): void
    {
        InteractionPolicy::register();
        \TinyCat\Social\Profiles\Integration::extend(['followed' => NotificationEvents::notifyFollowedUser(...)]);
        NotificationHooks::configure([
            'mentionedUserIds' => NotificationEvents::mentionedUserIds(...), 'notifyMentions' => NotificationEvents::notifyMentions(...),
            'notifyContentOwner' => NotificationEvents::notifyContentOwner(...), 'notifyCommentOwner' => NotificationEvents::notifyCommentOwner(...),
            'removeForContent' => NotificationHistory::removeForContent(...), 'removeForComment' => NotificationHistory::removeForComment(...),
        ]);
        \TinyCat\Social\Content\Integration::provide('tinycat.social', 'post', new \TinyCat\Core\Text\TextPolicy(Mentions::normalize_mentions_for_storage(...), InlinePresentation::segments(...), ContentLinks::navigation(...)));
        \TinyCat\Social\Profiles\Integration::extend([
            'author' => static function (?int $id): void { AuthorPages::author($id); },
            'author_data' => AuthorPages::authorData(...), 'activity' => AuthorStatistics::author_activity_stats(...),
            'feed' => static function (int $id): void { Syndication::respond('author', '', $id); },
        ]);
    }


    /** @return list<string> */
    public static function tables(): array
    {
        return [];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed', static fn (string $id) => Embed::page((int) $id), contentType: 'text/plain');
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed-code', static fn (string $id) => Embed::page((int) $id, true), contentType: 'text/plain');
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/rss', static fn () => \TinyCat\Http\Document::notFound('Feed not found.'), contentType: 'text/plain');
        \TinyCat\Http\Router::route('GET', '/status/{status_id:[0-9]+}', static function (string $status_id): void {
            Pages::detail(max(0, (int) $status_id));
        });

        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/tag/{tag}/feed', static fn (string $tag) => Syndication::respond('tag', $tag), contentType: 'text/plain');

        \TinyCat\Http\Router::route('GET', '/tag/{tag}', static function (string $tag): void {
            Pages::tag($tag);
        });

        foreach ([
            ['GET', '/social', Pages::home(...)],
            ['GET', '/status', Pages::detail(...)],
            ['GET', '/tag', Pages::tag(...)],
        ] as [$methods, $path, $handler]) {
            \TinyCat\Http\Router::route($methods, $path, $handler);
        }
    }
}
