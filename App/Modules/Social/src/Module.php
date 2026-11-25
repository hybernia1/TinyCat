<?php
declare(strict_types=1);
namespace TinyCat\Social;

use TinyCat\Core\Modules as Core;
use TinyCat\Core\Identity;
use TinyCat\Core\Runtime\InitializesModule;
use TinyCat\Database;
use TinyCat\Http\Router;
use TinyCat\Http\Web;
use TinyCat\Support;
use TinyCat\Core\Ui\Admin\Definitions;
use TinyCat\Http\Api;

/** The complete social application: one activation, one resource owner, one initialization. */
final class Module implements
    Core\Module,
    Core\ProvidesSchemas,
    Core\ProvidesContributions,
    InitializesModule,
    Identity\ProvidesLifecycle,
    Core\ProvidesFileReferences
{
    public static function initialize(): void
    {
        self::initializePublishing();
        Moderation\ActionPolicy::register();
        self::registerIdentityPolicies();
    }

    public static function contributions(): Core\Contributions
    {
        return new Core\Contributions(
            sitemaps: [
                new Core\SitemapSection(
                    'authors',
                    static fn (): int => Profiles\Repository::sitemapCount(),
                    static fn (int $limit, int $offset): array => Profiles\Repository::sitemapEntries($limit, $offset),
                    20,
                ),
                new Core\SitemapSection(
                    'status',
                    static fn (): int => Posts\SitemapData::count('status'),
                    static fn (int $limit, int $offset): array => Posts\SitemapData::entries('status', $limit, $offset),
                    30,
                ),
                new Core\SitemapSection(
                    'tags',
                    static fn (): int => Posts\SitemapData::count('tags'),
                    static fn (int $limit, int $offset): array => Posts\SitemapData::entries('tags', $limit, $offset),
                    40,
                ),
            ],
            playerOrigins: [
                new Core\PlayerOrigins(
                    'social.players',
                    Posts\Providers::playerOrigins(...),
                ),
            ],
            settings: Posts\Settings::sections(),
            configOverrides: [
                'moderation.blocked_urls',
                'content_links.utm.enabled',
                'content_links.utm.source',
                'content_links.utm.medium',
                'content_links.utm.campaign',
            ],
            temporaryDirectories: [
                new Core\TemporaryDirectory(
                    'publication-images',
                    static fn (): string => Support\Paths::basePath('uploads/status-images'),
                    20,
                ),
                new Core\TemporaryDirectory(
                    'link-images',
                    static fn (): string => Support\Paths::basePath('uploads/links'),
                    30,
                ),
            ],
            maintenance: [
                Database\Retention::task('orphan_terms', 'social_terms', 'NOT EXISTS (SELECT 1 FROM social_post_tags ct WHERE ct.term_id = social_terms.id)', static fn (): array => [], 10),
                Database\Retention::task('orphan_links', 'social_links', 'NOT EXISTS (SELECT 1 FROM social_post_links cl WHERE cl.link_id = social_links.id)', static fn (): array => [], 20),
                new Core\MaintenanceTask(
                    'orphan_status_image_files',
                    static fn (int $batch): array => Posts\ImageStorage::cleanupOrphans($batch),
                    30,
                    whenInactive: true,
                ),
                new Core\MaintenanceTask(
                    'orphan_link_image_files',
                    static fn (int $batch): array => Posts\LinkImages::cleanup($batch),
                    40,
                    whenInactive: true,
                ),
                new Core\MaintenanceTask(
                    'bot_history',
                    static fn (int $batch): array => Bots\Cleanup::run($batch),
                    130,
                ),
                new Core\MaintenanceTask(
                    'duplicate_bot_posts',
                    static fn (int $batch): array => Bots\DuplicateCleanup::run($batch),
                    140,
                ),
            ],
            embeds: [
                new Core\EmbedSource(
                    'social.posts',
                    static fn (string $path, int $width, int $height): ?array => Posts\Embed::data($path, $width, $height),
                ),
            ],
            homepages: [
                new Core\EntryPage(
                    'tinycat.status.home',
                    '/social',
                    static function (): void { Posts\Pages::home(); },
                    10,
                    static fn (): string => Support\Localization::t('public.feed_title'),
                    'message-circle',
                ),
            ],
            notificationTypes: Posts\NotificationPresentation::types(),
            tasks: [
                Bots\Importer::scheduledTask(),
            ],
            navigation: [
                new Core\NavigationItem(
                    'tinycat.bots.admin',
                    static fn (): array => self::botsAdminNavigation(),
                    30,
                ),
                new Core\NavigationItem(
                    'tinycat.moderation.admin',
                    static fn (): array => [
                        'icon' => 'shield',
                        'label' => Support\Localization::t('moderation.title'),
                        'children' => [Definitions::navigation('social.moderation-reports')],
                    ],
                    40,
                ),
            ],
            search: Search\Sources::all(),
            statistics: [
                new Core\DashboardStats(
                    'social.content',
                    static fn (): string => Support\Localization::t('admin.stats.content_group'),
                    Site\Dashboard::statistics(...),
                    order: 20,
                    fields: Site\Dashboard::statisticsFields(...),
                ),
            ],
            crawlerHints: [
                new Core\CrawlerHint(
                    'tinycat.site.sample',
                    static fn (): string => 'Recent public-post sample for LLMs: ' . \TinyCat\Support\LocalUrls::absolute('/llms-full.txt'),
                ),
            ],
            localizedRoutes: Site\PublicPages::localizedRoutes(),
            information: [
                new Core\InformationSection(
                    'social.privacy',
                    'privacy',
                    static fn (): string => Site\Pages::privacySection(),
                ),
                new Core\InformationSection(
                    'social.llms',
                    'llms',
                    static fn (): string => Site\Discovery::llms(),
                ),
                new Core\InformationSection(
                    'social.sample',
                    'llms-full',
                    static fn (): string => Site\Discovery::sample(),
                ),
            ],
            widgets: [
                new Core\DashboardWidget(
                    'social.comments',
                    Site\Dashboard::commentsWidget(...),
                    20,
                    type: 'list',
                    title: static fn (): string => Support\Localization::t('admin.recent_comments'),
                    icon: 'message-circle',
                ),
            ],
        );
    }

    /** @return array<string, Core\ModuleSchema> */
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

    public static function accountLifecycle(): Identity\Participant
    {
        return new Identity\Participant(prepareDelete: static function (array $user): Identity\Deletion {
            Posts\NotificationHistory::prepareAccountDelete($user);
            return Posts\AccountLifecycle::prepareDelete($user);
        }, statusChanged: self::accountStatusChanged(...));
    }

    public static function register(Api $api): void
    {
        self::registerProfiles($api);
        self::registerPosts($api);
        self::registerModeration($api);
        self::registerBots($api);
        self::registerSite($api);
        self::registerSearch($api);
    }

    /** @param list<string> $candidates
     * @return list<string> */
    public static function fileReferences(string $group, array $candidates): array
    {
        $source = match ($group) { 'raster-images:uploads/status-images' => ['social_post_images', 'path'], 'link-images' => ['social_links', 'image_url'], default => null };
        if ($source === null || $candidates === [] || !Database\Schema::exists(Database\Connection::get(), $source[0])) { return []; }
        $rows = Database\Connection::all('SELECT ' . $source[1] . ' AS path FROM ' . $source[0] . ' WHERE ' . $source[1] . ' IN (' . implode(', ', array_fill(0, count($candidates), '?')) . ')', $candidates);
        return array_values(array_map(static fn (array $row): string => (string) $row['path'], $rows));
    }

    private static function registerProfiles(Api $api): void
    {
        Profiles\Api::register($api);
        Router::route(['GET', 'HEAD'], '/author/{author_id:[0-9]+}/feed', static fn (string $author_id) => Posts\Syndication::respond('author', '', (int) $author_id), contentType: 'text/plain', publicWeb: true);
    }

    private static function initializePublishing(): void
    {
        Discussion\Interactions::invalidate();
    }

    private static function registerPosts(Api $api): void
    {
        Posts\Api::register($api);
        // Public embed snippets keep a stable entry URL; its source belongs to Social.
        Router::route(['GET', 'HEAD'], '/assets/embed.js', static function (): never {
            Web::redirect(Support\Assets::asset('App/Modules/Social/assets/embed/embed.js'));
        }, publicWeb: true);
        Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed', static fn (string $id) => Posts\Embed::page((int) $id), contentType: 'text/plain', publicWeb: true);
        Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed-code', static fn (string $id) => Posts\Embed::page((int) $id, true), contentType: 'text/plain', publicWeb: true);

        Router::route(['GET', 'HEAD'], '/tag/{tag}/feed', static fn (string $tag) => Posts\Syndication::respond('tag', $tag), contentType: 'text/plain', publicWeb: true);

        foreach ([
            ['GET', '/status', static function (?int $statusId = null): void { Posts\Pages::detail($statusId); }],
            ['GET', '/tag', static function (?string $tag = null): void { Posts\Pages::tag($tag); }],
        ] as [$methods, $path, $handler]) {
            Router::route($methods, $path, $handler, publicWeb: true);
        }
    }

    /** @return array<string, mixed> */
    private static function botsAdminNavigation(): array
    {
        return [
            'icon' => 'rss',
            'label' => Support\Localization::t('bots.title'),
            'children' => [
                Definitions::navigation('social.bot-creation'),
                Definitions::navigation('social.bot-sources'),
                Definitions::navigation('social.bot-queue'),
            ],
        ];
    }

    private static function registerBots(Api $api): void
    {
        Bots\Api::register($api);
        Router::route('GET', Definitions::route('social.bot-queue'), static function (): void { Bots\Pages::queue(); });
        Router::route('GET', '/admin/bots', static function (): void {
            $botId = max(0, (int) Web::get('bot', 0));
            Web::redirect($botId > 0
                ? '/admin/bots/list?' . http_build_query(['bot' => $botId])
                : '/admin/bots/accounts');
        });
        Router::route('GET', '/admin/bots/{bot_id:[0-9]+}', static function (string $bot_id): void {
            Bots\Pages::detail(max(0, (int) $bot_id));
        });
        Router::route('GET', Definitions::route('social.bot-creation'), static function (): void { Bots\Pages::accounts(); });
        Router::route('GET', Definitions::route('social.bot-sources'), static function (): void { Bots\Pages::sources(); });
    }

    private static function registerModeration(Api $api): void
    {
        Moderation\Api::register($api);
        Router::route('GET', '/admin/moderation', static function (): void {
            Web::redirect(Definitions::route('social.moderation-reports'));
        });
        Router::route('GET', Definitions::route('social.moderation-reports'), Moderation\Pages::reports(...));
    }

    private static function registerSearch(Api $api): void
    {
        Search\Api::register($api);
        Router::route(['GET', 'HEAD'], '/search/feed', static fn () => Posts\Syndication::respond('search'), contentType: 'text/plain', publicWeb: true);
    }

    private static function registerIdentityPolicies(): void
    {
        Identity\Integration::provide('tinycat.social', [
            'landing' => static fn (?array $user): string => Identity\Roles::isAdmin($user) ? '/admin' : Profiles\Profiles::author_url((int) ($user['id'] ?? 0)),
            'profile' => Profiles\Profiles::author_url(...),
            'next' => static function (string $url): string {
                $fragment = (string) (parse_url($url, PHP_URL_FRAGMENT) ?: '');
                return preg_match('/^status-(?:comments-thread-)?([1-9][0-9]*)$/', $fragment, $match) === 1 ? '/status/' . $match[1] : $url;
            },
        ]);
    }

    private static function registerSite(Api $api): void
    {
        Site\Api::register($api);
        Site\PublicPages::register();
    }

    /** @param array<string, mixed> $user */
    private static function accountStatusChanged(array $user, bool $active): void
    {
        if (!$active && ($user['role'] ?? '') === 'bot') {
            Database\Connection::update('bot_sources', ['enabled' => 0], ['bot_user_id' => (int) $user['id']]);
        }
    }
}
