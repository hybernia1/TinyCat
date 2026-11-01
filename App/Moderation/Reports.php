<?php
declare(strict_types=1);

namespace TinyCat\Moderation;

use DomainException;

final class Reports
{
    public static function publish(int $contentId): void
    {
        \TinyCat\Database\Transactions::run(static function () use ($contentId): void {
            $content = \TinyCat\Database\Connection::one(
                \TinyCat\Database\Connection::lockingSelect('SELECT id FROM content WHERE id = ?'), [$contentId]
            );
            if ($content === null) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
            }
            \TinyCat\Database\Connection::update('content', ['status' => 'published'], ['id' => $contentId]);
        });
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_moderation_review_report(int $reportId, string $decision): array
    {
        if (!in_array($decision, ['remove', 'dismiss'], true)) {
            \TinyCat\Http\Response::validation(['decision' => [\TinyCat\Http\Response::issue('common_request_failed', \TinyCat\Support\Localization::t('common.request_failed'))]]);
        }

        $contentId = (int) \TinyCat\Database\Connection::value('SELECT content_id FROM content_reports WHERE id = ?', [$reportId]);
        try {
            $result = \TinyCat\Database\Transactions::run(static function () use ($reportId, $decision, $contentId): array {
                // Serialize reviews for the same post even when moderators choose different reports.
                $content = \TinyCat\Database\Connection::one(
                    \TinyCat\Database\Connection::lockingSelect('SELECT * FROM content WHERE id = ?'), [$contentId]
                );
                $report = \TinyCat\Database\Connection::one(
                    \TinyCat\Database\Connection::lockingSelect('SELECT * FROM content_reports WHERE id = ? AND status = ? LIMIT 1'),
                    [$reportId, 'open']
                );

                if ($report === null) {
                    throw new DomainException('report_not_open');
                }

                if ($content === null) {
                    throw new DomainException('reported_content_not_found');
                }

                $actor = \TinyCat\Users\Authentication::auth() ?? [];
                $status = $decision === 'remove' ? 'resolved' : 'dismissed';
                $note = $decision === 'remove' ? 'hidden' : 'kept';

                if ($decision === 'remove') {
                    \TinyCat\Moderation\Restrictions::user_mute((int) ($content['author_id'] ?? 0), $actor, '+24 hours', 'moderation_remove');
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
                    \TinyCat\Database\Connection::update('content', ['status' => 'hidden'], ['id' => $contentId]);
                }

                return [
                    'report_id' => $reportId,
                    'content_id' => $contentId,
                    'decision' => $decision,
                    'status' => $status,
                ];
            });

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
        $query = self::reportsQuery();
        $pagination = \TinyCat\Support\Pagination::paginationMeta($query->count(), \TinyCat\Support\AdminViews::admin_page(), \TinyCat\Support\AdminViews::admin_per_page());
        $filters = self::filters() + ['per_page' => $pagination['per_page']] + \TinyCat\Support\AdminSorting::state('reports');
        return [
            'items' => array_map(static fn (array $report): array => Resource::report($report) + ['action_url' => \TinyCat\Support\AdminViews::admin_list_url('/api/moderation/reports/' . (int) $report['id'], $filters + ['page' => $pagination['page']])], $query->order(\TinyCat\Support\AdminSorting::order('reports'))->limit((int) $pagination['per_page'], (int) $pagination['offset'])->all()),
            'filters' => $filters, 'pagination' => $pagination,
            'sorting' => \TinyCat\Support\AdminSorting::headings('reports', $filters),
            'navigation' => \TinyCat\Support\AdminViews::admin_pagination_view_data($pagination, '/api/moderation/reports', '#moderation-reports', $filters, historyPath: '/admin/moderation/reports'),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_moderation_reports_view_data(): array
    {
        return self::tc_admin_moderation_reports_payload();
    }

    /** @return array<string, mixed> */
    public static function detail(int $reportId): array
    {
        $report = \TinyCat\Database\Connection::one('SELECT * FROM content_reports WHERE id = ?', [$reportId]);
        if ($report === null) {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.report_not_found'), 404, 'report_not_found');
        }
        $contentId = (int) $report['content_id'];
        $content = \TinyCat\Database\Connection::one(
            \TinyCat\Status\Feed::public_status_select_sql() . ' WHERE c.id = ?', [$contentId]
        );
        $items = \TinyCat\Database\Connection::all(
            'SELECT cr.*, ru.username AS reporter_name, au.username AS reviewer_name FROM content_reports cr LEFT JOIN users ru ON ru.id = cr.reporter_id LEFT JOIN users au ON au.id = cr.reviewed_by WHERE cr.content_id = ? ORDER BY cr.created_at DESC, cr.id DESC', [$contentId]
        );
        return Resource::detail($reportId, $content, $items);
    }

    /** @return array<string, string> */
    private static function filters(): array
    {
        $status = \TinyCat\Http\Web::get('status', '');
        $reason = \TinyCat\Http\Web::get('reason', '');
        return [
            'q' => \TinyCat\Support\AdminViews::admin_filter_text((string) \TinyCat\Http\Web::get('q', '')),
            'status' => in_array($status, ['open', 'reviewed', 'resolved', 'dismissed'], true) ? $status : '',
            'reason' => in_array($reason, ['spam', 'illegal', 'malware', 'abuse', 'other'], true) ? $reason : '',
        ];
    }

    private static function reportsQuery(): \TinyCat\Database\Query
    {
        $query = new \TinyCat\Database\Query(
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
            LEFT JOIN users ru ON ru.id = cr.reporter_id',
            ['open', 'open']
        );
        $filters = self::filters();
        if ($filters['q'] !== '') {
            $search = '%' . \TinyCat\Database\Connection::escapeLike($filters['q']) . '%';
            $query->where("(c.body LIKE ? ESCAPE '!' OR au.username LIKE ? ESCAPE '!' OR ru.username LIKE ? ESCAPE '!')", $search, $search, $search);
        }
        if ($filters['status'] === 'open') { $query->where('rc.open_count > 0'); }
        elseif ($filters['status'] !== '') { $query->where('rc.open_count = 0 AND cr.status = ?', $filters['status']); }
        if ($filters['reason'] !== '') { $query->where('EXISTS (SELECT 1 FROM content_reports matching WHERE matching.content_id = cr.content_id AND matching.reason = ?)', $filters['reason']); }
        return $query;
    }

}
