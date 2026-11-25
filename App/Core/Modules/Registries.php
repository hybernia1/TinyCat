<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use InvalidArgumentException;

final readonly class Registries
{
    public Contributions $entries;
    /** @var array<string, string> */
    private array $owners;

    /** @param array<string, Contributions> $modules */
    public function __construct(array $modules = [])
    {
        $configOverrides = []; $notificationInbox = false; $mailDelivery = false; $owners = [];
        foreach ($modules as $owner => $bundle) {
            if ($bundle->mailDelivery) {
                if ($mailDelivery) { throw new InvalidArgumentException('Duplicate mail delivery provider.'); }
                $mailDelivery = true; $owners['service:mail'] = $owner;
            }
            if ($bundle->notificationInbox) {
                if ($notificationInbox) { throw new InvalidArgumentException('Duplicate notification inbox provider.'); }
                $notificationInbox = true; $owners['service:notification-inbox'] = $owner;
            }
            foreach ($bundle->configOverrides as $key) {
                if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $key) !== 1) { throw new InvalidArgumentException('Invalid config override key.'); }
                if (isset($owners['config:' . $key])) { throw new InvalidArgumentException('Duplicate config override: ' . $key); }
                $owners['config:' . $key] = $owner;
                $configOverrides[] = $key;
            }
        }
        $this->entries = new Contributions(
            tasks: self::collect($modules, 'tasks', ScheduledTask::class, 'task', $owners),
            navigation: self::collect($modules, 'navigation', NavigationItem::class, 'navigation', $owners),
            health: self::collect($modules, 'health', HealthProbe::class, 'health', $owners),
            maintenance: self::collect($modules, 'maintenance', MaintenanceTask::class, 'maintenance', $owners),
            temporaryDirectories: self::collect($modules, 'temporaryDirectories', TemporaryDirectory::class, 'temporary', $owners),
            notificationTypes: self::collect($modules, 'notificationTypes', NotificationType::class, 'notification', $owners),
            homepages: self::collect($modules, 'homepages', EntryPage::class, 'homepage', $owners),
            dashboards: self::collect($modules, 'dashboards', EntryPage::class, 'dashboard', $owners),
            settings: self::collect($modules, 'settings', SettingsSection::class, 'settings', $owners),
            sitemaps: self::collect($modules, 'sitemaps', SitemapSection::class, 'sitemap', $owners),
            search: self::collect($modules, 'search', SearchSource::class, 'search', $owners),
            crawlerHints: self::collect($modules, 'crawlerHints', CrawlerHint::class, 'crawler-hint', $owners),
            localizedRoutes: self::collect($modules, 'localizedRoutes', LocalizedRoute::class, 'localized-route', $owners),
            information: self::collect($modules, 'information', InformationSection::class, 'information', $owners),
            widgets: self::collect($modules, 'widgets', DashboardWidget::class, 'widget', $owners),
            statistics: self::collect($modules, 'statistics', DashboardStats::class, 'dashboard-stats', $owners),
            embeds: self::collect($modules, 'embeds', EmbedSource::class, 'embed', $owners),
            componentLookups: self::collect($modules, 'componentLookups', ComponentLookup::class, 'component-lookup', $owners),
            playerOrigins: self::collect($modules, 'playerOrigins', PlayerOrigins::class, 'player-origins', $owners),
            configOverrides: $configOverrides, notificationInbox: $notificationInbox, mailDelivery: $mailDelivery,
        );
        $this->owners = $owners;
    }

    public function task(string $id): ScheduledTask
    {
        foreach ($this->entries->tasks as $task) { if ($task->id === $id) { return $task; } }
        throw new InvalidArgumentException('Unknown scheduled task.');
    }

    public function owner(string $kind, string $id): string
    {
        return $this->owners[$kind . ':' . $id] ?? throw new InvalidArgumentException('Unknown contribution.');
    }

    public function maintenance(string $id): MaintenanceTask
    {
        foreach ($this->entries->maintenance as $task) { if ($task->id === $id) { return $task; } }
        throw new InvalidArgumentException('Unknown maintenance task.');
    }

    public function initialMaintenance(): string
    {
        foreach ($this->entries->maintenance as $task) { if ($task->initial) { return $task->id; } }
        return $this->entries->maintenance[0]->id ?? '';
    }

    /** @template T of NotificationType|PlayerOrigins|ComponentLookup|ScheduledTask|NavigationItem|HealthProbe|MaintenanceTask|TemporaryDirectory|EntryPage|SettingsSection|SitemapSection|SearchSource|CrawlerHint|LocalizedRoute|InformationSection|DashboardWidget|DashboardStats|EmbedSource
     * @param array<string, Contributions> $modules
     * @param class-string<T> $type
     * @param array<string, string> $owners
     * @return list<T> */
    private static function collect(array $modules, string $property, string $type, string $kind, array &$owners): array
    {
        $entries = [];
        foreach ($modules as $owner => $bundle) {
            foreach ($bundle->$property as $entry) {
                if (!$entry instanceof $type) { throw new InvalidArgumentException('Invalid contribution type: ' . $property); }
                if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $entry->id) !== 1) { throw new InvalidArgumentException('Invalid contribution ID: ' . $entry->id); }
                $key = $kind . ':' . $entry->id;
                if (isset($owners[$key])) { throw new InvalidArgumentException('Duplicate ' . $key . ' (' . $owners[$key] . ', ' . $owner . ')'); }
                $owners[$key] = $owner;
                $entries[] = $entry;
            }
        }
        // Inbox type order is the declaration order; all ordered contributions use ID as a stable tie-breaker.
        if ($type !== NotificationType::class) {
            usort($entries, static fn ($a, $b): int => $a instanceof NotificationType || $b instanceof NotificationType ? 0 : ($a->order <=> $b->order ?: strcmp($a->id, $b->id)));
        }
        return $entries;
    }
}
