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
        $tasks = []; $navigation = []; $health = []; $maintenance = []; $temporaryDirectories = []; $configOverrides = []; $notificationTypes = []; $notificationInbox = false; $themes = []; $homepages = []; $dashboards = []; $settings = []; $features = []; $mailDelivery = false; $sitemaps = []; $search = []; $crawlerHints = []; $localizedRoutes = []; $information = []; $widgets = []; $embeds = []; $owners = [];
        foreach ($modules as $owner => $bundle) {
            if ($bundle->mailDelivery) {
                if ($mailDelivery) { throw new InvalidArgumentException('Duplicate mail delivery provider.'); }
                $mailDelivery = true; $owners['service:mail'] = $owner;
            }
            if ($bundle->notificationInbox) {
                if ($notificationInbox) { throw new InvalidArgumentException('Duplicate notification inbox provider.'); }
                $notificationInbox = true; $owners['service:notification-inbox'] = $owner;
            }
            foreach (['task' => $bundle->tasks, 'navigation' => $bundle->navigation, 'health' => $bundle->health, 'maintenance' => $bundle->maintenance, 'temporary' => $bundle->temporaryDirectories, 'notification' => $bundle->notificationTypes, 'theme' => $bundle->themes, 'homepage' => $bundle->homepages, 'dashboard' => $bundle->dashboards, 'settings' => $bundle->settings, 'feature' => $bundle->features, 'sitemap' => $bundle->sitemaps, 'search' => $bundle->search, 'crawler-hint' => $bundle->crawlerHints, 'localized-route' => $bundle->localizedRoutes, 'information' => $bundle->information, 'widget' => $bundle->widgets, 'embed' => $bundle->embeds] as $kind => $entries) {
                foreach ($entries as $entry) {
                    if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $entry->id) !== 1) { throw new InvalidArgumentException('Invalid contribution ID: ' . $entry->id); }
                    $key = $kind . ':' . $entry->id;
                    if (isset($owners[$key])) { throw new InvalidArgumentException('Duplicate ' . $key . ' (' . $owners[$key] . ', ' . $owner . ')'); }
                    $owners[$key] = $owner;
                }
            }
            $tasks = [...$tasks, ...$bundle->tasks];
            $navigation = [...$navigation, ...$bundle->navigation];
            $health = [...$health, ...$bundle->health];
            $maintenance = [...$maintenance, ...$bundle->maintenance];
            $temporaryDirectories = [...$temporaryDirectories, ...$bundle->temporaryDirectories];
            $notificationTypes = [...$notificationTypes, ...$bundle->notificationTypes];
            $themes = [...$themes, ...$bundle->themes];
            $homepages = [...$homepages, ...$bundle->homepages];
            $dashboards = [...$dashboards, ...$bundle->dashboards];
            $settings = [...$settings, ...$bundle->settings];
            $features = [...$features, ...$bundle->features];
            $sitemaps = [...$sitemaps, ...$bundle->sitemaps]; $search = [...$search, ...$bundle->search]; $crawlerHints = [...$crawlerHints, ...$bundle->crawlerHints]; $localizedRoutes = [...$localizedRoutes, ...$bundle->localizedRoutes]; $information = [...$information, ...$bundle->information]; $widgets = [...$widgets, ...$bundle->widgets]; $embeds = [...$embeds, ...$bundle->embeds];
            foreach ($bundle->configOverrides as $key) {
                if (preg_match('/^[a-z][a-z0-9_.-]*$/D', $key) !== 1) { throw new InvalidArgumentException('Invalid config override key.'); }
                if (isset($owners['config:' . $key])) { throw new InvalidArgumentException('Duplicate config override: ' . $key); }
                $owners['config:' . $key] = $owner;
                $configOverrides[] = $key;
            }
        }
        $this->entries = new Contributions(self::ordered($tasks), self::ordered($navigation), self::ordered($health), self::ordered($maintenance), self::ordered($temporaryDirectories), $configOverrides, $notificationTypes, $notificationInbox, self::ordered($themes), self::ordered($homepages), self::ordered($dashboards), self::ordered($settings), self::ordered($features), $mailDelivery, self::ordered($sitemaps), self::ordered($search), self::ordered($crawlerHints), self::ordered($localizedRoutes), self::ordered($information), self::ordered($widgets), self::ordered($embeds));
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

    /** @template T of ScheduledTask|NavigationItem|HealthProbe|MaintenanceTask|TemporaryDirectory|PageTheme|EntryPage|SettingsSection|FeatureGroup|SitemapSection|SearchSource|CrawlerHint|LocalizedRoute|InformationSection|DashboardWidget|EmbedSource
     * @param list<T> $entries
     * @return list<T> */
    private static function ordered(array $entries): array
    {
        usort($entries, static fn ($a, $b): int => $a->order <=> $b->order ?: strcmp($a->id, $b->id));
        return $entries;
    }
}
