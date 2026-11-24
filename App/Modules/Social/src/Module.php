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
        self::initializePosts();
        Moderation\ActionPolicy::register();
        self::initializeSite();
    }

    public static function contributions(): Contributions
    {
        return (new Registries([
            'profiles' => self::profilesContributions(),
            'posts' => self::postsContributions(),
            'bots' => self::botsContributions(),
            'moderation' => self::moderationContributions(),
            'search' => self::searchContributions(),
            'site' => self::siteContributions(),
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
        if ($source === null || $candidates === [] || !\TinyCat\Database\Schema::exists(\TinyCat\Database\Connection::get(), $source[0])) { return []; }
        $rows = \TinyCat\Database\Connection::all('SELECT ' . $source[1] . ' AS path FROM ' . $source[0] . ' WHERE ' . $source[1] . ' IN (' . implode(', ', array_fill(0, count($candidates), '?')) . ')', $candidates);
        return array_values(array_map(static fn (array $row): string => (string) $row['path'], $rows));
    }

    private static function profilesContributions(): \TinyCat\Core\Modules\Contributions { return new \TinyCat\Core\Modules\Contributions(sitemaps: [new \TinyCat\Core\Modules\SitemapSection('authors', static fn (): int => Profiles\Repository::sitemapCount(), static fn (int $limit, int $offset): array => Profiles\Repository::sitemapEntries($limit, $offset), 20)]); }

    private static function registerProfiles(\TinyCat\Http\Api $api): void
    {
        Profiles\Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/author/{author_id:[0-9]+}/feed', static fn (string $author_id) => \TinyCat\Social\Posts\Syndication::respond('author', '', (int) $author_id), contentType: 'text/plain', publicWeb: true);
    }

    private static function postsContributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(
            settings: Posts\Settings::sections(),
            configOverrides: ['moderation.blocked_urls', 'content_links.utm.enabled', 'content_links.utm.source', 'content_links.utm.medium', 'content_links.utm.campaign'],
            temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('publication-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/status-images'), 20),
            new \TinyCat\Core\Modules\TemporaryDirectory('link-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/links'), 30),
            ],
            maintenance: [
            \TinyCat\Database\Retention::task('orphan_terms', 'social_terms', 'NOT EXISTS (SELECT 1 FROM social_post_tags ct WHERE ct.term_id = social_terms.id)', static fn (): array => [], 10),
            \TinyCat\Database\Retention::task('orphan_links', 'social_links', 'NOT EXISTS (SELECT 1 FROM social_post_links cl WHERE cl.link_id = social_links.id)', static fn (): array => [], 20),
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_status_image_files', static fn (int $batch): array => Posts\ImageStorage::cleanupOrphans($batch), 30, whenInactive: true),
            new \TinyCat\Core\Modules\MaintenanceTask('orphan_link_image_files', static fn (int $batch): array => Posts\Links::cleanupOrphanImages($batch), 40, whenInactive: true),
            ],
            embeds: [new \TinyCat\Core\Modules\EmbedSource('social.posts', static fn (string $path, int $width, int $height): ?array => Posts\Embed::data($path, $width, $height))],
            sitemaps: [new \TinyCat\Core\Modules\SitemapSection('status', static fn (): int => Posts\SitemapData::count('status'), static fn (int $limit, int $offset): array => Posts\SitemapData::entries('status', $limit, $offset), 30), new \TinyCat\Core\Modules\SitemapSection('tags', static fn (): int => Posts\SitemapData::count('tags'), static fn (int $limit, int $offset): array => Posts\SitemapData::entries('tags', $limit, $offset), 40)],
            homepages: [new \TinyCat\Core\Modules\EntryPage('tinycat.status.home', '/social', static function (): void { Posts\Pages::home(); }, 10, static fn (): string => \TinyCat\Support\Localization::t('public.feed_title'), 'message-circle')],
            notificationTypes: Posts\NotificationPresentation::types(),
        );
    }

    private static function initializePosts(): void
    {
        \TinyCat\Social\Discussion\Interactions::invalidate();
        \TinyCat\Core\Text\Policies::provide('tinycat.social', 'post', new \TinyCat\Core\Text\TextPolicy(Posts\Mentions::normalize_mentions_for_storage(...), Posts\InlinePresentation::segments(...), Posts\ContentLinks::navigation(...), prepare: \TinyCat\Core\Text\PostText::cleanImportBoilerplate(...)));
    }

    private static function registerPosts(\TinyCat\Http\Api $api): void
    {
        Posts\Api::register($api);
        // Public embed snippets keep a stable entry URL; its source belongs to Social.
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/assets/embed.js', static function (): never {
            \TinyCat\Http\Web::redirect(\TinyCat\Support\Assets::asset('App/Modules/Social/assets/embed/embed.js'));
        }, publicWeb: true);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed', static fn (string $id) => Posts\Embed::page((int) $id), contentType: 'text/plain', publicWeb: true);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/status/{id:[0-9]+}/embed-code', static fn (string $id) => Posts\Embed::page((int) $id, true), contentType: 'text/plain', publicWeb: true);

        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/tag/{tag}/feed', static fn (string $tag) => Posts\Syndication::respond('tag', $tag), contentType: 'text/plain', publicWeb: true);

        foreach ([
            ['GET', '/status', static function (?int $statusId = null): void { Posts\Pages::detail($statusId); }],
            ['GET', '/tag', static function (?string $tag = null): void { Posts\Pages::tag($tag); }],
        ] as [$methods, $path, $handler]) {
            \TinyCat\Http\Router::route($methods, $path, $handler, publicWeb: true);
        }
    }

    private static function botsContributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(
            tasks: [Bots\Importer::scheduledTask()],
            navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.bots.admin', static fn (): array => self::botsAdminNavigation(), 30)],
            maintenance: [
                new \TinyCat\Core\Modules\MaintenanceTask('bot_history', static fn (int $batch): array => Bots\Cleanup::run($batch), 130),
                new \TinyCat\Core\Modules\MaintenanceTask('duplicate_bot_posts', static fn (int $batch): array => Bots\DuplicateCleanup::run($batch), 140),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function botsAdminNavigation(): array
    {
        return [
            'icon' => 'rss',
            'label' => \TinyCat\Support\Localization::t('bots.title'),
            'children' => [
                \TinyCat\Core\Ui\Admin\Definitions::navigation('social.bot-creation'),
                \TinyCat\Core\Ui\Admin\Definitions::navigation('social.bot-sources'),
                \TinyCat\Core\Ui\Admin\Definitions::navigation('social.bot-queue'),
            ],
        ];
    }

    private static function registerBots(\TinyCat\Http\Api $api): void
    {
        Bots\Api::register($api);
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('social.bot-queue'), static function (): void { Bots\Pages::queue(); });
        \TinyCat\Http\Router::route('GET', '/admin/bots', static function (): void {
            $botId = max(0, (int) \TinyCat\Http\Web::get('bot', 0));
            \TinyCat\Http\Web::redirect($botId > 0
                ? '/admin/bots/list?' . http_build_query(['bot' => $botId])
                : '/admin/bots/accounts');
        });
        \TinyCat\Http\Router::route('GET', '/admin/bots/{bot_id:[0-9]+}', static function (string $bot_id): void {
            Bots\Pages::detail(max(0, (int) $bot_id));
        });
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('social.bot-creation'), static function (): void { Bots\Pages::accounts(); });
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('social.bot-sources'), static function (): void { Bots\Pages::sources(); });
    }

    private static function moderationContributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(navigation: [new \TinyCat\Core\Modules\NavigationItem('tinycat.moderation.admin', static fn (): array => [
            'icon' => 'shield', 'label' => \TinyCat\Support\Localization::t('moderation.title'),
            'children' => [\TinyCat\Core\Ui\Admin\Definitions::navigation('social.moderation-reports')],
        ], 40)]);
    }

    private static function registerModeration(\TinyCat\Http\Api $api): void
    {
        Moderation\Api::register($api);
        \TinyCat\Http\Router::route('GET', '/admin/moderation', static function (): void {
            \TinyCat\Http\Web::redirect(\TinyCat\Core\Ui\Admin\Definitions::route('social.moderation-reports'));
        });
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('social.moderation-reports'), Moderation\Pages::reports(...));
    }

    private static function searchContributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(search: Search\Sources::all());
    }

    private static function registerSearch(\TinyCat\Http\Api $api): void
    {
        Search\Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/search/feed', static fn () => \TinyCat\Social\Posts\Syndication::respond('search'), contentType: 'text/plain', publicWeb: true);
    }

    private static function initializeSite(): void
    {
        \TinyCat\Core\Identity\Integration::provide('tinycat.site', [
            'landing' => static fn (?array $user): string => \TinyCat\Core\Identity\Roles::isAdmin($user) ? '/admin' : \TinyCat\Social\Profiles\Profiles::author_url((int) ($user['id'] ?? 0)),
            'profile' => \TinyCat\Social\Profiles\Profiles::author_url(...),
            'next' => static function (string $url): string {
                $fragment = (string) (parse_url($url, PHP_URL_FRAGMENT) ?: '');
                return preg_match('/^status-(?:comments-thread-)?([1-9][0-9]*)$/', $fragment, $match) === 1 ? '/status/' . $match[1] : $url;
            },
        ]);
    }

    private static function siteContributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(statistics: [new \TinyCat\Core\Modules\DashboardStats('social.content', static fn (): string => \TinyCat\Support\Localization::t('admin.stats.content_group'), Site\Dashboard::statistics(...), order: 20, fields: Site\Dashboard::statisticsFields(...))], crawlerHints: [new \TinyCat\Core\Modules\CrawlerHint('tinycat.site.sample', static fn (): string => 'Recent public-post sample for LLMs: ' . \TinyCat\Core\Ui\Site::absolute_url('/llms-full.txt'))], localizedRoutes: Site\PublicPages::localizedRoutes(), information: [new \TinyCat\Core\Modules\InformationSection('social.privacy', 'privacy', static fn (): string => Site\Pages::privacySection()), new \TinyCat\Core\Modules\InformationSection('social.llms', 'llms', static fn (): string => Site\Discovery::llms()), new \TinyCat\Core\Modules\InformationSection('social.sample', 'llms-full', static fn (): string => Site\Discovery::sample())], widgets: [new \TinyCat\Core\Modules\DashboardWidget('social.comments', Site\Dashboard::commentsWidget(...), 20, type: 'list', title: static fn (): string => \TinyCat\Support\Localization::t('admin.recent_comments'), icon: 'message-circle')]);
    }

    private static function registerSite(\TinyCat\Http\Api $api): void
    {
        Site\Api::register($api);
        Site\PublicPages::register();
    }

    /** @param array<string, mixed> $user */
    private static function accountStatusChanged(array $user, bool $active): void
    {
        if (!$active && ($user['role'] ?? '') === 'bot') {
            \TinyCat\Database\Connection::update('bot_sources', ['enabled' => 0], ['bot_user_id' => (int) $user['id']]);
        }
    }
}
