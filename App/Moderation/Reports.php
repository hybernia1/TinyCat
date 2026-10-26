<?php
declare(strict_types=1);

namespace TinyCat\Moderation;

use DomainException;

final class Reports
{
    /**
     * @return array<mixed>
     */
    public static function tc_admin_moderation_review_report(int $reportId, string $decision): array
    {
        if (!in_array($decision, ['remove', 'dismiss'], true)) {
            \TinyCat\Http\Response::validation(['decision' => [\TinyCat\Support\Localization::t('common.request_failed')]]);
        }

        $deletedImagePath = '';

        try {
            $result = \TinyCat\Database\Transactions::run(static function () use ($reportId, $decision, &$deletedImagePath): array {
                $report = \TinyCat\Database\Connection::one(
                    \TinyCat\Database\Connection::lockingSelect('SELECT * FROM content_reports WHERE id = ? AND status = ? LIMIT 1'),
                    [$reportId, 'open']
                );

                if ($report === null) {
                    throw new DomainException('report_not_open');
                }

                $contentId = (int) ($report['content_id'] ?? 0);
                $content = \TinyCat\Status\Posts::status_find($contentId);

                if ($content === null) {
                    throw new DomainException('reported_content_not_found');
                }

                $actor = \TinyCat\Users\Authentication::auth() ?? [];
                $status = $decision === 'remove' ? 'resolved' : 'dismissed';
                $note = $decision === 'remove' ? 'removed' : 'kept_locked';

                if ($decision === 'remove') {
                    \TinyCat\Moderation\Restrictions::user_mute((int) ($content['author_id'] ?? 0), $actor, '+24 hours', 'moderation_remove');
                } else {
                    \TinyCat\Status\Posts::status_edit_lock($contentId, $actor, 'moderation_keep');
                }

                \TinyCat\Notifications\Inbox::notifyReporters(
                    $contentId,
                    $decision === 'remove' ? 'report_resolved' : 'report_dismissed',
                    $actor,
                    'open',
                    $decision !== 'remove'
                );

                \TinyCat\Database\Connection::exec(
                    'UPDATE content_reports
                    SET status = ?, reviewed_at = ?, reviewed_by = ?, action_note = ?
                    WHERE content_id = ? AND status = ?',
                    [$status, \TinyCat\Support\Dates::dateDb(), (int) ($actor['id'] ?? 0), $note, $contentId, 'open']
                );

                if ($decision === 'remove') {
                    $deletedImagePath = \TinyCat\Status\Posts::status_delete_content($contentId, false, false, false);
                }

                return [
                    'report_id' => $reportId,
                    'content_id' => $contentId,
                    'decision' => $decision,
                    'status' => $status,
                ];
            });
            \TinyCat\Status\ImageStorage::delete($deletedImagePath);

            return $result;
        } catch (DomainException $exception) {
            \TinyCat\Http\Response::error(
                \TinyCat\Support\Localization::t('moderation.messages.report_not_found'),
                $exception->getMessage() === 'report_not_open' ? 409 : 404,
                $exception->getMessage()
            );
        }
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_moderation_reports_payload(): array
    {
        return ['items' => array_map(Resource::report(...), self::tc_admin_moderation_reports())];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_moderation_reports_view_data(): array
    {
        return [
            'reports' => array_map('TinyCat\\Moderation\\Reports::tc_admin_moderation_report_view_data', \TinyCat\Moderation\Reports::tc_admin_moderation_reports()),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_moderation_reports(): array
    {
        return \TinyCat\Database\Connection::all(
            'SELECT cr.*,
                rc.report_count,
                rc.open_count,
                rc.latest_reported_at,
                c.id AS existing_content_id,
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

    /**
     * @param array<mixed> $report
     * @return array<mixed>
     */
    public static function tc_admin_moderation_report_view_data(array $report): array
    {
        $openCount = max(0, (int) ($report['open_count'] ?? 0));
        $reportCount = max(1, (int) ($report['report_count'] ?? 1));
        $status = $openCount > 0 ? 'open' : (string) ($report['status'] ?? 'reviewed');
        $contentExists = (int) ($report['existing_content_id'] ?? 0) > 0;
        $body = trim((string) preg_replace('/\s+/', ' ', (string) ($report['body'] ?? '')));
        $reasons = \TinyCat\Status\Posts::status_report_reasons();

        return array_merge($report, [
            'excerpt' => $body === '' ? \TinyCat\Support\Localization::t('moderation.empty_content') : \TinyCat\Support\Text::plain_text_limit($body, 120),
            'reason_label' => (string) ($reasons[(string) ($report['reason'] ?? 'other')] ?? $reasons['other']),
            'report_count_label' => $reportCount === 1
                ? \TinyCat\Support\Localization::t('moderation.report_count_one')
                : \TinyCat\Support\Localization::t('moderation.report_count_many', ['count' => $reportCount]),
            'display_status' => $status,
            'status_label' => match ($status) {
                'open' => \TinyCat\Support\Localization::t('moderation.report_statuses.open'),
                'resolved' => \TinyCat\Support\Localization::t('moderation.report_statuses.resolved'),
                'dismissed' => \TinyCat\Support\Localization::t('moderation.report_statuses.dismissed'),
                default => \TinyCat\Support\Localization::t('moderation.report_statuses.reviewed'),
            },
            'status_badge_class' => match ($status) {
                'open' => 'badge badge-danger',
                'resolved' => 'badge badge-primary',
                default => 'badge',
            },
            'reported_at_label' => \TinyCat\Support\Dates::displayDateTime((string) ($report['latest_reported_at'] ?? $report['created_at'] ?? '')),
            'permalink_url' => $contentExists ? \TinyCat\Status\Posts::status_url((int) ($report['content_id'] ?? 0)) : '',
            'permalink_label' => $contentExists ? \TinyCat\Status\ViewData::status_permalink_label($report) : '',
        ]);
    }
}
