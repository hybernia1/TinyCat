<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use TinyCat\Support\AdminViews;
use TinyCat\Support\Dates;
use TinyCat\Support\Localization;

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
            return $run + ['started_iso' => $at !== '' ? Dates::dateIso($at) : '', 'started_label' => $at !== '' ? Dates::displayDateTime($at) : ''];
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
            $item['status_label'] = (string) ($statuses[$item['status']] ?? $item['status']);
            $item['updated_label'] = !empty($item['updated_at']) ? Dates::displayDateTime((string) $item['updated_at']) : '';
        }
        unset($item);
        $pagination = (array) ($data['pagination'] ?? []);
        $filters = (array) ($data['filters'] ?? []);
        $navigation = AdminViews::admin_pagination_view_data($pagination, '/api/bots/accounts', '#bot-accounts-list', $filters + ['per_page' => (int) ($pagination['per_page'] ?? 10)], 'page', 2, '/admin/bots/accounts');
        unset($navigation['target']);
        return array_replace($data, ['items' => $items, 'status_options' => ['' => Localization::t('common.all')] + $statuses,
            'navigation' => $navigation]);
    }
}
