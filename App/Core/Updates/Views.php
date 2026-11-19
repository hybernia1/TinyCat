<?php
declare(strict_types=1);
namespace TinyCat\Core\Updates;

use TinyCat\Support\{Dates, Localization, Version};

/** Pure presentation preparation; never checks, installs or changes maintenance. */
final class Views
{
    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function model(array $data): array
    {
        $release = is_array($data['release']) ? $data['release'] : null;
        $available = $release !== null && !empty($release['available']);
        $extensions = (array) $data['extensions'];
        $result = (array) $data['result'];
        $history = [];
        foreach ((array) $data['history'] as $migration) {
            $history[] = $migration + ['applied_label' => Dates::displayDateTime((string) ($migration['applied_at'] ?? ''))];
        }
        return array_replace($data, [
            'available' => $available, 'has_release' => $release !== null,
            'version' => (string) ($release['version'] ?? ''),
            'has_notes' => trim((string) ($release['notes'] ?? '')) !== '',
            'minimum_version' => (string) ($release['manifest']['minimum_version'] ?? '?'),
            'minimum_php' => (string) ($release['manifest']['minimum_php'] ?? '?'),
            'maintenance_from' => (string) ($data['maintenance']['from_version'] ?? Version::CURRENT),
            'maintenance_to' => (string) ($data['maintenance']['to_version'] ?? '?'),
            'missing_extensions' => in_array(false, $extensions, true),
            'tone' => !$data['canInstall'] ? 'warning' : ($release !== null ? ($available ? 'primary' : 'ok') : 'unknown'),
            'status_label' => $release !== null ? ($available ? Localization::t('updates.available', ['version' => (string) ($release['version'] ?? '')]) : Localization::t('updates.up_to_date')) : Localization::t('updates.not_checked'),
            'stats' => [
                ['label' => Localization::t('updates.current_version'), 'value' => 'v' . Version::CURRENT, 'icon' => 'download'],
                ['label' => Localization::t('updates.latest_version'), 'value' => $release !== null ? 'v' . (string) ($release['version'] ?? '?') : '-', 'icon' => 'refresh'],
                ['label' => Localization::t('updates.requirements'), 'value' => count(array_filter($extensions)) . ' / ' . count($extensions), 'icon' => 'shield'],
            ],
            'migration_count' => count((array) ($result['migrations'] ?? [])), 'history' => $history,
        ]);
    }
}
