<?php
declare(strict_types=1);
namespace TinyCat\Blog;

final class Module implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesSchema, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Identity\ProvidesLifecycle, \TinyCat\Core\Modules\ProvidesFileReferences
{
    public static function initialize(): void
    {
        Discussion::initialize();
        \TinyCat\Core\Text\Policies::provide('tinycat.blog', 'article', new \TinyCat\Core\Text\TextPolicy(outbound: static fn (int $id, string $hash, int $comment): string => '/api/articles/' . $id . '/links/' . $hash . '?navigate=1'));
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(settings: [Settings::section()], configOverrides: Settings::KEYS, temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('blog-publication-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/status-images'), 21),
            new \TinyCat\Core\Modules\TemporaryDirectory('blog-link-images', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/links'), 31),
        ], maintenance: [
            \TinyCat\Database\Retention::task('blog.orphan_terms', 'blog_terms', 'NOT EXISTS (SELECT 1 FROM blog_article_tags t WHERE t.term_id = blog_terms.id)', static fn (): array => [], 11),
            \TinyCat\Database\Retention::task('blog.orphan_links', 'blog_links', 'NOT EXISTS (SELECT 1 FROM blog_article_links r WHERE r.link_id = blog_links.id)', static fn (): array => [], 21),
            new \TinyCat\Core\Modules\MaintenanceTask('blog.orphan_images', static fn (int $batch): array => ImageStorage::cleanupOrphans($batch), 31),
            new \TinyCat\Core\Modules\MaintenanceTask('blog.orphan_link_images', static fn (int $batch): array => \TinyCat\Core\Files\RemoteImageCache::cleanup($batch), 41),
        ], tasks: [FeedSources::task()], embeds: [new \TinyCat\Core\Modules\EmbedSource('blog.articles', Exports::embed(...), 20)], sitemaps: [new \TinyCat\Core\Modules\SitemapSection('articles', SitemapData::count(...), SitemapData::entries(...), 50)], search: [SearchData::source()], homepages: [new \TinyCat\Core\Modules\EntryPage('tinycat.blog.home', '/blog', static function (): void { Pages::listing(); }, 20, static fn (): string => \TinyCat\Support\Localization::t('blog.articles'))], dashboards: [new \TinyCat\Core\Modules\EntryPage('tinycat.blog.admin', '/admin/articles', static function (): void { Pages::listing(false); }, 20)], localizedRoutes: [new \TinyCat\Core\Modules\LocalizedRoute('blog.archive', '^/blog$', ['before', 'at']), new \TinyCat\Core\Modules\LocalizedRoute('blog.article', '^/articles/[1-9][0-9]*$')], navigation: [
            new \TinyCat\Core\Modules\NavigationItem('tinycat.blog.articles', static fn (): array => \TinyCat\Core\Ui\Admin\Definitions::navigation('blog.articles'), 25),
            new \TinyCat\Core\Modules\NavigationItem('tinycat.blog.sources', static fn (): array => \TinyCat\Core\Ui\Admin\Definitions::navigation('blog.sources'), 26),
        ]);
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(4, Schema::tables(), Schema::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.blog.articles', null, '3815c3a3e231e49457a71c91504ff3c3b926c7f303f409d078d5b3bac39050f8'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.blog.kinds', null, '8f85cc751693f0266400bde205b641a6dc3034a8ea23f2fdc7211379b2e8f8af'),
            new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.blog.feeds', null, '43dd596c5b286dc2e94c646759942dd24349150080c8981c3a386a6d5850fac0'),
            new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.blog.owned-storage', null, '22b3e5ab73f0f26f2661d50ec7df7c6f59f85e8f5f19dae074b1a6056d19946b'),
        ], new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.blog.fresh-v4', dirname(__DIR__) . '/migrations/fresh-v4.php'));
    }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant { return new \TinyCat\Core\Identity\Participant(prepareDelete: AccountLifecycle::prepareDelete(...)); }

    public static function fileReferences(string $group, array $candidates): array
    {
        $source = match ($group) { 'raster-images:uploads/status-images' => ['blog_article_images', 'path'], 'link-images' => ['blog_links', 'image_url'], default => null };
        if ($source === null || $candidates === [] || !\TinyCat\Database\Schema::exists(\TinyCat\Database\Connection::get(), $source[0])) { return []; }
        $rows = \TinyCat\Database\Connection::all('SELECT ' . $source[1] . ' AS path FROM ' . $source[0] . ' WHERE ' . $source[1] . ' IN (' . implode(', ', array_fill(0, count($candidates), '?')) . ')', $candidates);
        return array_values(array_map(static fn (array $row): string => (string) $row['path'], $rows));
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        $ready = static fn (): bool => \TinyCat\Database\ModuleSchemas::ready(\TinyCat\Database\Connection::get(), ['tinycat.blog' => self::schema()]);
        Api::register($api->whenReady($ready));
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('blog.sources'), FeedAdministration::page(...));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/blog/feed', Exports::feed(...), 'application/rss+xml');
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/blog', Pages::listing(...));
        \TinyCat\Http\Router::route('GET', \TinyCat\Core\Ui\Admin\Definitions::route('blog.articles'), static fn () => Pages::listing(false));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/articles/{id:[1-9][0-9]*}', static fn (string $id) => Pages::detail((int) $id));
        \TinyCat\Http\Router::route('GET', '/admin/articles/new', static fn () => Pages::editor());
        \TinyCat\Http\Router::route('GET', '/admin/articles/{id:[1-9][0-9]*}', static fn (string $id) => Pages::editor((int) $id));
    }
}
