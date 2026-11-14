<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

final readonly class Contributions
{
    /** @param list<ScheduledTask> $tasks
     * @param list<NavigationItem> $navigation
     * @param list<HealthProbe> $health
     * @param list<MaintenanceTask> $maintenance
     * @param list<TemporaryDirectory> $temporaryDirectories
     * @param list<string> $configOverrides
     * @param list<NotificationType> $notificationTypes
     * @param list<PageTheme> $themes
     * @param list<EntryPage> $homepages
     * @param list<EntryPage> $dashboards
     * @param list<SettingsSection> $settings
     * @param list<FeatureGroup> $features
     * @param list<SitemapSection> $sitemaps
     * @param list<SearchSource> $search
     * @param list<CrawlerHint> $crawlerHints
     * @param list<LocalizedRoute> $localizedRoutes
     * @param list<InformationSection> $information
     * @param list<DashboardWidget> $widgets
     * @param list<EmbedSource> $embeds */
    public function __construct(public array $tasks = [], public array $navigation = [], public array $health = [], public array $maintenance = [], public array $temporaryDirectories = [], public array $configOverrides = [], public array $notificationTypes = [], public bool $notificationInbox = false, public array $themes = [], public array $homepages = [], public array $dashboards = [], public array $settings = [], public array $features = [], public bool $mailDelivery = false, public array $sitemaps = [], public array $search = [], public array $crawlerHints = [], public array $localizedRoutes = [], public array $information = [], public array $widgets = [], public array $embeds = []) {}
}
