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
            'excerpt' => \TinyCat\Support\Text::plain_text_limit(trim((string) preg_replace('/\\s+/', ' ', (string) ($report['body'] ?? ''))), 120),
            'reason' => (string) ($report['reason'] ?? 'other'),
            'status' => (int) ($report['open_count'] ?? 0) > 0 ? 'open' : (string) ($report['status'] ?? 'reviewed'),
            'reported_at' => $at !== '' ? \TinyCat\Support\Dates::dateIso($at) : '',
            'permalink_url' => (int) ($report['existing_content_id'] ?? 0) > 0 ? \TinyCat\Status\Posts::status_url((int) ($report['content_id'] ?? 0)) : '',
            'context' => \TinyCat\Status\Resource::context($report),
            'note' => (string) ($report['note'] ?? ''),
        ];
    }
}
