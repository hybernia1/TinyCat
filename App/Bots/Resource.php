<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use TinyCat\Support\AdminViews;
use TinyCat\Support\Dates;

final class Resource
{
    /**
     * @param array<mixed> $runs
     * @return array<mixed>
     */
    public static function runs(array $runs): array
    {
        return array_map(static function (array $run): array {
            $at = (string) ($run['started_at'] ?? '');
            $status = (string) ($run['status'] ?? '');
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
        $navigation = AdminViews::admin_pagination_view_data($pagination, '/api/bots/accounts', '#bot-accounts-list', $filters + ['per_page' => (int) ($pagination['per_page'] ?? 10)], 'page', 2, '/admin/bots/accounts');
        unset($navigation['target']);
        return array_replace($data, ['items' => $items, 'statuses' => array_keys($statuses),
            'navigation' => $navigation]);
    }
}
