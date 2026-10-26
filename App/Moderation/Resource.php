<?php
declare(strict_types=1);

namespace TinyCat\Moderation;

final class Resource
{
    /**
     * @param array<mixed> $report
     * @return array<string, mixed>
     */
    public static function report(array $report): array
    {
        $view = Reports::tc_admin_moderation_report_view_data($report);
        return [
            'id' => (int) ($report['id'] ?? 0),
            'content_id' => (int) ($report['content_id'] ?? 0),
            'author_name' => (string) ($report['author_name'] ?? ''),
            'open_count' => (int) ($report['open_count'] ?? 0),
            'excerpt' => $view['excerpt'],
            'reason_label' => $view['reason_label'],
            'report_count_label' => $view['report_count_label'],
            'status' => $view['display_status'],
            'status_label' => $view['status_label'],
            'reported_at_label' => $view['reported_at_label'],
            'permalink_url' => $view['permalink_url'],
            'permalink_label' => $view['permalink_label'],
            'note' => (string) ($report['note'] ?? ''),
        ];
    }
}
