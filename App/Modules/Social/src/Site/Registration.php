<?php
declare(strict_types=1);
namespace TinyCat\Social\Site;

final class Registration
{
    public static function initialize(): void
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

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(statistics: [new \TinyCat\Core\Modules\DashboardStats('social.content', static fn (): string => \TinyCat\Support\Localization::t('admin.stats.content_group'), Dashboard::statistics(...), order: 20, fields: Dashboard::statisticsFields(...))], crawlerHints: [new \TinyCat\Core\Modules\CrawlerHint('tinycat.site.sample', static fn (): string => 'Recent public-post sample for LLMs: ' . \TinyCat\Core\Ui\Site::absolute_url('/llms-full.txt'))], localizedRoutes: PublicPages::localizedRoutes(), information: [new \TinyCat\Core\Modules\InformationSection('social.privacy', 'privacy', static fn (): string => Pages::privacySection()), new \TinyCat\Core\Modules\InformationSection('social.llms', 'llms', static fn (): string => Discovery::llms()), new \TinyCat\Core\Modules\InformationSection('social.sample', 'llms-full', static fn (): string => Discovery::sample())], widgets: [new \TinyCat\Core\Modules\DashboardWidget('social.comments', Dashboard::commentsWidget(...), 20, type: 'list', title: static fn (): string => \TinyCat\Support\Localization::t('admin.recent_comments'), icon: 'message-circle')]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        PublicPages::register();
    }
}
