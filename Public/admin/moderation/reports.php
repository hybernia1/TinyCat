<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_admin();

$moderationReportsApi = route_path() === '/api/admin/moderation/reports';

if (!$moderationReportsApi && method() !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo 'Method not allowed.';
    return;
}

if ($moderationReportsApi && method() === 'GET') {
    api_ok(tc_admin_moderation_reports_payload());
}

if ($moderationReportsApi && method() === 'POST') {
    csrf_require();
    tc_admin_moderation_review_report(
        max(1, (int) input('report_id', 0)),
        (string) input('decision', '')
    );
    api_ok(tc_admin_moderation_reports_payload(), t('moderation.messages.report_reviewed'));
}

layout('layout', [
    'title' => t('moderation.reports_title'),
    'current' => '/admin/moderation/reports',
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('flag') ?> <?= et('moderation.reports_title') ?></h2>
        </div>
        <div class="card-body" id="moderation-reports">
            <?= part('admin/moderation/reports', tc_admin_moderation_reports_view_data()) ?>
        </div>
    </section>
    <?php
});

function tc_admin_moderation_review_report(int $reportId, string $decision): array
{
    if (!in_array($decision, ['remove', 'dismiss'], true)) {
        api_validation(['decision' => [t('common.request_failed')]]);
    }

    $deletedImagePath = '';

    try {
        $result = db_transaction(static function () use ($reportId, $decision, &$deletedImagePath): array {
            $report = one(
                'SELECT * FROM content_reports WHERE id = ? AND status = ? LIMIT 1 FOR UPDATE',
                [$reportId, 'open']
            );

            if ($report === null) {
                throw new DomainException('report_not_open');
            }

            $contentId = (int) ($report['content_id'] ?? 0);
            $content = status_find($contentId);

            if ($content === null) {
                throw new DomainException('reported_content_not_found');
            }

            $actor = auth() ?? [];
            $status = $decision === 'remove' ? 'resolved' : 'dismissed';
            $note = $decision === 'remove' ? 'removed' : 'kept_locked';

            if ($decision === 'remove') {
                user_mute((int) ($content['author_id'] ?? 0), $actor, '+24 hours', 'moderation_remove');
            } else {
                status_edit_lock($contentId, $actor, 'moderation_keep');
            }

            Notifications::notifyReporters(
                $contentId,
                $decision === 'remove' ? 'report_resolved' : 'report_dismissed',
                $actor,
                'open',
                $decision !== 'remove'
            );

            run(
                'UPDATE content_reports
                SET status = ?, reviewed_at = ?, reviewed_by = ?, action_note = ?
                WHERE content_id = ? AND status = ?',
                [$status, date_db(), (int) ($actor['id'] ?? 0), $note, $contentId, 'open']
            );

            if ($decision === 'remove') {
                $deletedImagePath = status_delete_content($contentId, false, false, false);
            }

            return [
                'report_id' => $reportId,
                'content_id' => $contentId,
                'decision' => $decision,
                'status' => $status,
            ];
        });
        StatusImage::delete($deletedImagePath);

        return $result;
    } catch (DomainException $exception) {
        api_error(
            t('moderation.messages.report_not_found'),
            $exception->getMessage() === 'report_not_open' ? 409 : 404,
            $exception->getMessage()
        );
    }
}

function tc_admin_moderation_reports_payload(): array
{
    $data = tc_admin_moderation_reports_view_data();

    return api_payload(
        ['items' => $data['reports']],
        static fn (): array => ['html' => part('admin/moderation/reports', $data)]
    );
}

function tc_admin_moderation_reports_view_data(): array
{
    return [
        'reports' => array_map('tc_admin_moderation_report_view_data', tc_admin_moderation_reports()),
    ];
}

function tc_admin_moderation_reports(): array
{
    return all(
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

function tc_admin_moderation_report_view_data(array $report): array
{
    $openCount = max(0, (int) ($report['open_count'] ?? 0));
    $reportCount = max(1, (int) ($report['report_count'] ?? 1));
    $status = $openCount > 0 ? 'open' : (string) ($report['status'] ?? 'reviewed');
    $contentExists = (int) ($report['existing_content_id'] ?? 0) > 0;
    $body = trim((string) preg_replace('/\s+/', ' ', (string) ($report['body'] ?? '')));
    $reasons = status_report_reasons();

    return array_merge($report, [
        'excerpt' => $body === '' ? t('moderation.empty_content') : plain_text_limit($body, 120),
        'reason_label' => (string) ($reasons[(string) ($report['reason'] ?? 'other')] ?? $reasons['other']),
        'report_count_label' => $reportCount === 1
            ? t('moderation.report_count_one')
            : t('moderation.report_count_many', ['count' => $reportCount]),
        'display_status' => $status,
        'status_label' => match ($status) {
            'open' => t('moderation.report_statuses.open'),
            'resolved' => t('moderation.report_statuses.resolved'),
            'dismissed' => t('moderation.report_statuses.dismissed'),
            default => t('moderation.report_statuses.reviewed'),
        },
        'status_badge_class' => match ($status) {
            'open' => 'badge badge-danger',
            'resolved' => 'badge badge-primary',
            default => 'badge',
        },
        'reported_at_label' => datetime((string) ($report['latest_reported_at'] ?? $report['created_at'] ?? '')),
        'permalink_url' => $contentExists ? status_url((int) ($report['content_id'] ?? 0)) : '',
        'permalink_label' => $contentExists ? status_permalink_label($report) : '',
    ]);
}
