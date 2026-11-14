<?php
declare(strict_types=1);
namespace TinyCat\Blog;

final class Module implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesSchema, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Runtime\InitializesModule, \TinyCat\Core\Identity\ProvidesLifecycle, \TinyCat\Core\Modules\ProvidesFileReferences
{
    public static function initialize(): void
    {
        Discussion::initialize();
        \TinyCat\Core\Text\Policies::provide('tinycat.blog', 'article', new \TinyCat\Core\Text\TextPolicy(outbound: static fn (int $id, string $hash, int $comment): string => '/api/articles/' . $id . '/links/' . $hash . '?navigate=1'));
        \TinyCat\Core\Runtime\Startup::$frameOrigins = array_values(array_unique([...\TinyCat\Core\Runtime\Startup::$frameOrigins, ...\TinyCat\Core\Links\Providers::playerOrigins()]));
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
            new \TinyCat\Core\Modules\NavigationItem('tinycat.blog.articles', static fn (): array => ['href' => '/admin/articles', 'icon' => 'file-text', 'label' => \TinyCat\Support\Localization::t('blog.articles')], 25),
            new \TinyCat\Core\Modules\NavigationItem('tinycat.blog.sources', static fn (): array => ['href' => '/admin/article-sources', 'icon' => 'rss', 'label' => \TinyCat\Support\Localization::t('blog.feeds.title')], 26),
        ]);
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(4, Schema::tables(), Schema::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.blog.articles', dirname(__DIR__) . '/migrations/001-articles.php'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.blog.kinds', dirname(__DIR__) . '/migrations/002-kinds.php'),
            new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.blog.feeds', dirname(__DIR__) . '/migrations/003-feed-sources.php'),
            new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.blog.owned-storage', dirname(__DIR__) . '/migrations/004-owned-storage.php'),
        ], new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.blog.fresh-v4', dirname(__DIR__) . '/migrations/fresh-v4.php'), new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.blog.legacy-v4', dirname(__DIR__) . '/migrations/legacy-v4.php'));
    }

    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant { return new \TinyCat\Core\Identity\Participant(prepareDelete: AccountLifecycle::prepareDelete(...)); }

    public static function fileReferences(string $group, array $candidates): array
    {
        $source = match ($group) { 'raster-images:uploads/status-images' => ['blog_article_images', 'path'], 'link-images' => ['blog_links', 'image_url'], default => null };
        if ($source === null || $candidates === [] || !\TinyCat\Database\SchemaInspection::exists(\TinyCat\Database\Connection::get(), $source[0])) { return []; }
        $rows = \TinyCat\Database\Connection::all('SELECT ' . $source[1] . ' AS path FROM ' . $source[0] . ' WHERE ' . $source[1] . ' IN (' . implode(', ', array_fill(0, count($candidates), '?')) . ')', $candidates);
        return array_values(array_map(static fn (array $row): string => (string) $row['path'], $rows));
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        $ready = static fn (): bool => \TinyCat\Database\ModuleSchemas::ready(\TinyCat\Database\Connection::get(), ['tinycat.blog' => self::schema()]);
        Api::register($api->whenReady($ready));
        \TinyCat\Http\Router::route('GET', '/admin/article-sources', FeedAdministration::page(...));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/blog/feed', Exports::feed(...), 'application/rss+xml');
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/blog', Pages::listing(...));
        \TinyCat\Http\Router::route('GET', '/admin/articles', static fn () => Pages::listing(false));
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/articles/{id:[1-9][0-9]*}', static fn (string $id) => Pages::detail((int) $id));
        \TinyCat\Http\Router::route('GET', '/admin/articles/new', static fn () => Pages::administration(0));
        \TinyCat\Http\Router::route('GET', '/admin/articles/{id:[1-9][0-9]*}', static fn (string $id) => Pages::administration((int) $id));
    }
}
