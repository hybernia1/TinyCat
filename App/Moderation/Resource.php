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
        $at = (string) ($report['latest_reported_at'] ?? $report['created_at'] ?? '');
        return [
            'id' => (int) ($report['id'] ?? 0), 'content_id' => (int) ($report['content_id'] ?? 0),
            'author_name' => (string) ($report['author_name'] ?? ''),
            'open_count' => max(0, (int) ($report['open_count'] ?? 0)),
            'report_count' => max(1, (int) ($report['report_count'] ?? 1)),
            'excerpt' => trim((string) preg_replace('/\\s+/', ' ', (string) ($report['body'] ?? ''))),
            'reason' => (string) ($report['reason'] ?? 'other'),
            'status' => (int) ($report['open_count'] ?? 0) > 0 ? 'open' : (string) ($report['status'] ?? 'reviewed'),
            'reported_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
            'permalink_url' => (int) ($report['existing_content_id'] ?? 0) > 0 ? \TinyCat\Status\Posts::status_url((int) ($report['content_id'] ?? 0)) : '',
            'context' => \TinyCat\Status\Resource::context($report),
            'note' => (string) ($report['note'] ?? ''),
        ];
    }

    /** @param array<mixed>|null $content
     * @param array<mixed> $reports
     * @return array<string, mixed>
     */
    public static function detail(int $reportId, ?array $content, array $reports): array
    {
        return [
            'report_id' => $reportId,
            'content' => $content === null ? null : [
                'id' => (int) $content['id'],
                'status' => (string) ($content['status'] ?? 'published'),
            ],
            'reports' => array_map(self::entry(...), $reports),
            'preview' => $content === null ? null : [
                'item' => \TinyCat\Status\Resource::posts([$content], null)[0],
                'comments' => \TinyCat\Status\Resource::comments(\TinyCat\Status\Comments::status_comments((int) $content['id']), null),
            ],
        ];
    }

    /** @param array<mixed> $report
     * @return array<string, mixed>
     */
    public static function entry(array $report): array
    {
        return [
            'id' => (int) $report['id'],
            'content_id' => (int) $report['content_id'],
            'reporter_name' => (string) ($report['reporter_name'] ?? ''),
            'reviewer_name' => (string) ($report['reviewer_name'] ?? ''),
            'reason' => (string) ($report['reason'] ?? 'other'),
            'note' => (string) ($report['note'] ?? ''),
            'status' => (string) ($report['status'] ?? 'open'),
            'created_at' => !empty($report['created_at']) ? \TinyCat\Support\Dates::dateIso((string) $report['created_at']) : null,
            'reviewed_at' => !empty($report['reviewed_at']) ? \TinyCat\Support\Dates::dateIso((string) $report['reviewed_at']) : null,
        ];
    }
}
