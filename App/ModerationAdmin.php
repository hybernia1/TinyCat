<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class ModerationAdmin
{
    private function __construct()
    {
    }

    public static function saveUrlBlocker(): never
    {
        $rules = moderation_blocked_url_rules((string) post('blocked_urls', ''));
        setting_set('moderation.blocked_urls', implode(', ', $rules), 'string', 'moderation');
        flash('success', t('moderation.messages.url_blocker_saved'));
        redirect('/admin/moderation/blocking');
    }

    public static function blockedUrlsValue(): string
    {
        return implode(', ', moderation_blocked_url_rules());
    }

    public static function reviewReport(): never
    {
        $reportId = max(0, (int) post('report_id', 0));
        $decision = (string) post('decision', '');

        if (!in_array($decision, ['remove', 'dismiss'], true)) {
            flash('error', t('common.request_failed'));
            redirect('/admin/moderation/reports');
        }

        $report = one('SELECT * FROM content_reports WHERE id = ? LIMIT 1', [$reportId]);

        if ($report === null) {
            flash('error', t('moderation.messages.report_not_found'));
            redirect('/admin/moderation/reports');
        }

        $contentId = (int) ($report['content_id'] ?? 0);
        $actor = auth() ?? [];
        $content = status_find($contentId);
        $authorId = (int) ($content['author_id'] ?? 0);
        $status = 'reviewed';
        $note = '';
        $removeContent = false;

        if ($decision === 'remove') {
            user_mute($authorId, $actor, '+24 hours', 'moderation_remove');
            $status = 'resolved';
            $note = 'removed';
            $removeContent = true;
        } elseif ($decision === 'dismiss') {
            status_edit_lock($contentId, $actor, 'moderation_keep');
            $status = 'dismissed';
            $note = 'kept_locked';
        }

        Notifications::notifyReporters(
            $contentId,
            $status === 'dismissed' ? 'report_dismissed' : 'report_resolved',
            $actor,
            'open'
        );

        run(
            'UPDATE content_reports
            SET status = ?,
                reviewed_at = ?,
                reviewed_by = ?,
                action_note = ?
            WHERE content_id = ? AND status = ?',
            [$status, date_db(), (int) auth('id', 0), $note, $contentId, 'open']
        );

        if ($removeContent) {
            status_delete_content($contentId, false, false);
        }

        flash('success', t('moderation.messages.report_reviewed'));
        redirect('/admin/moderation/reports');
    }

    public static function reports(): array
    {
        return all(
            'SELECT cr.*,
                rc.report_count,
                rc.open_count,
                rc.latest_reported_at,
                c.body,
                au.username AS author_name,
                ru.username AS reporter_name
            FROM (
                SELECT content_id,
                    COUNT(*) AS report_count,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS open_count,
                    MAX(CASE WHEN status = ? THEN id ELSE 0 END) AS open_report_id,
                    MAX(id) AS latest_report_id,
                    MAX(created_at) AS latest_reported_at
                FROM content_reports
                GROUP BY content_id
            ) rc
            INNER JOIN content_reports cr ON cr.id = CASE WHEN rc.open_report_id > 0 THEN rc.open_report_id ELSE rc.latest_report_id END
            LEFT JOIN content c ON c.id = cr.content_id
            LEFT JOIN users au ON au.id = c.author_id
            LEFT JOIN users ru ON ru.id = cr.reporter_id
            ORDER BY CASE WHEN rc.open_count > 0 THEN 0 ELSE 1 END, rc.latest_reported_at DESC
            LIMIT 100',
            ['open', 'open']
        );
    }

    public static function reportsViewData(): array
    {
        return [
            'reports' => array_map([self::class, 'reportViewData'], self::reports()),
        ];
    }

    private static function reportViewData(array $report): array
    {
        $openCount = max(0, (int) ($report['open_count'] ?? 0));
        $reportCount = max(1, (int) ($report['report_count'] ?? 1));
        $status = $openCount > 0 ? 'open' : (string) ($report['status'] ?? 'reviewed');

        return array_merge($report, [
            'excerpt' => self::excerpt((string) ($report['body'] ?? '')),
            'reason_label' => self::reasonLabel((string) ($report['reason'] ?? 'other')),
            'report_count_label' => self::reportCountLabel($reportCount),
            'display_status' => $status,
            'status_label' => self::reportStatus($status),
            'status_badge_class' => self::statusBadgeClass($status),
            'reported_at_label' => datetime((string) ($report['latest_reported_at'] ?? $report['created_at'] ?? '')),
            'permalink_url' => status_url((int) ($report['content_id'] ?? 0)),
            'permalink_label' => status_permalink_label($report),
        ]);
    }

    private static function excerpt(string $body): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));

        return $body === '' ? t('moderation.empty_content') : plain_text_limit($body, 120);
    }

    private static function reasonLabel(string $reason): string
    {
        $reasons = status_report_reasons();

        return (string) ($reasons[$reason] ?? $reasons['other']);
    }

    private static function reportCountLabel(int $count): string
    {
        $count = max(0, $count);

        return $count === 1
            ? t('moderation.report_count_one')
            : t('moderation.report_count_many', ['count' => $count]);
    }

    private static function reportStatus(string $status): string
    {
        return match ($status) {
            'open' => t('moderation.report_statuses.open'),
            'resolved' => t('moderation.report_statuses.resolved'),
            'dismissed' => t('moderation.report_statuses.dismissed'),
            default => t('moderation.report_statuses.reviewed'),
        };
    }

    private static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'open' => 'badge badge-danger',
            'resolved' => 'badge badge-primary',
            default => 'badge',
        };
    }

}
