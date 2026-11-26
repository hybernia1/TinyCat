<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui\Admin;

use TinyCat\Support\{Cache, ClientAssets, Components, Localization, Markup};

/** Read-only operational cache overview; no cache keys or private paths are exposed. */
final class CachePage
{
    public static function respond(): void
    {
        \TinyCat\Core\Identity\Access::require_admin();
        \TinyCat\Core\Ui\Page::render(['title' => Localization::t('admin.cache.title'), 'current' => '/admin/cache'], Components::render('ui/cache-page', self::model()));
    }

    /** @return array<string, mixed> */
    public static function model(): array
    {
        $cache = Cache::diagnostics();
        $application = ['name' => Localization::t('admin.cache.application'), 'description' => Localization::t('admin.cache.application_help'), 'value' => $cache['driver'] === 'memcached' ? 'Memcached' : Localization::t('admin.cache.filesystem'), 'badge_label' => Localization::t('admin.cache.' . ($cache['available'] ? 'available' : 'fallback')), 'tone' => $cache['available'] ? 'success' : 'danger'];
        $assets = ['name' => Localization::t('admin.cache.assets'), 'rows' => []];
        foreach (['css', 'js', 'html'] as $kind) {
            $enabled = (bool) \TinyCat\Core\Runtime\Startup::option('performance.minify_' . $kind, false);
            $assets['rows'][] = ['label' => Localization::t('settings.fields.minify_' . $kind), 'value' => Localization::t('admin.cache.' . ($enabled ? 'enabled' : 'disabled')), 'badge' => true, 'tone' => $enabled ? 'success' : 'info'];
        }
        $browser = ['name' => Localization::t('admin.cache.browser'), 'rows' => [
            ['label' => Localization::t('admin.cache.assets'), 'value' => Localization::t('admin.cache.one_year')],
            ['label' => Localization::t('admin.cache.uploads'), 'value' => Localization::t('admin.cache.thirty_days')],
            ['label' => 'HTML', 'value' => 'no-store'],
        ]];
        $observations = [['label' => Localization::t('common.home'), 'url' => \TinyCat\Support\LocalUrls::resolve('/')], ['label' => 'CSS', 'url' => ClientAssets::styles('/admin/cache')[0]]];
        foreach ($observations as &$observation) {
            $observation['rows'] = [];
            foreach (['Content-Encoding', 'Cache-Control', 'Vary', 'ETag'] as $header) {
                $observation['rows'][] = ['label' => $header, 'value' => Localization::t('admin.cache.observing'), 'attributes' => ['data-cache-header' => strtolower($header), 'class' => $header === 'Content-Encoding' ? 'badge badge-info' : 'is-resource-text']];
            }
        }
        unset($observation);
        return ['application' => $application, 'assets' => $assets, 'browser' => $browser, 'observations' => $observations, 'services' => new Markup(Diagnostics::render(['cache' => $cache, 'memcached' => $cache['memcached'], 'memcachedStats' => $cache['memcached']['stats'], 'opcache' => $cache['opcache'], 'opcacheStats' => $cache['opcache']['stats'], 'opcacheConfig' => $cache['opcache']['configuration']]))];
    }
}
