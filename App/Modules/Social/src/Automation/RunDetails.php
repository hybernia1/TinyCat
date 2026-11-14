<?php
declare(strict_types=1);
namespace TinyCat\Social\Automation;

/** Import details are queried only for an active importer task. */
final class RunDetails
{
    /** @param array<string, mixed> $item
     * @return array<string, mixed> */
    public static function present(array $item): array
    {
        $records = \TinyCat\Database\Connection::all('SELECT br.*, bs.name AS source_name FROM bot_source_runs br INNER JOIN bot_sources bs ON bs.id = br.source_id WHERE br.scheduler_run_id = ? ORDER BY br.id ASC', [(int) $item['id']]);
        $item['sources'] = Resource::runs($records);
        $sourceNames = array_column($item['sources'], 'source_name', 'source_id');
        foreach ($item['summary']['skipped_items'] ?? [] as $index => $skipped) {
            $item['summary']['skipped_items'][$index]['source_name'] = $sourceNames[$skipped['source_id']] ?? (string) $skipped['source_id'];
        }
        return $item;
    }
}
