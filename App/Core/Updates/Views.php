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
            'current_version' => 'v' . Version::CURRENT,
            'missing_extension_names' => implode(', ', array_map(static fn (string $name): string => 'PHP ' . $name, array_keys(array_filter($extensions, static fn (bool $loaded): bool => !$loaded)))),
            'status_label' => $release !== null ? ($available ? Localization::t('updates.available', ['version' => (string) ($release['version'] ?? '')]) : Localization::t('updates.up_to_date')) : Localization::t('updates.not_checked'),
            'migration_count' => count((array) ($result['migrations'] ?? [])), 'history' => $history,
        ]);
    }
}
