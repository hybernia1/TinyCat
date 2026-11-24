<?php
declare(strict_types=1);

namespace TinyCat\Social\Bots;

use TinyCat\Support\AdminViews;
use TinyCat\Support\Dates;

final class Resource
{
    /** @return array{options: array{username_pattern: string}} */
    public static function creation(): array
    {
        return ['options' => ['username_pattern' => \TinyCat\Core\Identity\Usernames::pattern()]];
    }

    /** @param array<string, mixed> $row
     * @return array{id: int, name: string, url: string}|null
     */
    public static function postSource(array $row, int $botId): ?array
    {
        $id = (int) ($row['source_id'] ?? 0);
        return $id > 0 ? ['id' => $id, 'name' => (string) $row['source_name'], 'url' => '/admin/bots/' . $botId . '?source=' . $id] : null;
    }

    /**
     * @param array<mixed> $runs
     * @return array<mixed>
     */
    public static function runs(array $runs): array
    {
        return array_map(static function (array $run): array {
            $at = (string) ($run['started_at'] ?? '');
            return array_replace($run, ['started_at' => $at !== '' ? Dates::dateIso($at) : '']);
        }, $runs);
    }

    /**
     * @param array<mixed> $data
     * @return array<string, mixed>
     */
    public static function accounts(array $data): array
    {
        $items = (array) ($data['items'] ?? []);
        $statuses = (array) ($data['statuses'] ?? []);
        foreach ($items as &$item) {
            $item['updated_at'] = !empty($item['updated_at']) ? Dates::dateIso((string) $item['updated_at']) : '';
        }
        unset($item);
        $pagination = (array) ($data['pagination'] ?? []);
        $filters = (array) ($data['filters'] ?? []);
        $definition = \TinyCat\Core\Ui\Admin\Definitions::get('social.bot-creation');
        $navigation = AdminViews::admin_pagination_view_data($pagination, $definition['endpoint'], $definition['target'], $filters + ['per_page' => (int) ($pagination['per_page'] ?? 10)], 'page', 2, $definition['path']);
        unset($navigation['target']);
        return array_replace($data, ['items' => $items, 'statuses' => array_keys($statuses),
            'sorting' => \TinyCat\Support\AdminSorting::headings(\TinyCat\Social\Bots\Sorting::accounts(), $filters + ['per_page' => (int) ($pagination['per_page'] ?? 25)]),
            'navigation' => $navigation]);
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed> */
    public static function runDetails(array $item): array
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
