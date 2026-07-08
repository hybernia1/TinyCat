<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

require_admin();

if (is_post()) {
    csrf_require();
    tc_admin_moderation_handle();
}

layout('layout', [
    'title' => t('moderation.meta_title'),
    'current' => '/admin/moderation',
], static function (): void {
    $reports = tc_admin_moderation_reports();
    ?>
    <section>
        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('flag') ?> <?= et('moderation.reports_title') ?></h2>
            </div>
            <div class="card-body stack">
                <?php if ($reports === []): ?>
                    <div class="alert alert-info"><?= et('moderation.reports_empty') ?></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= et('moderation.reported_content') ?></th>
                                    <th><?= et('moderation.report_reason') ?></th>
                                    <th><?= et('common.status') ?></th>
                                    <th><?= et('common.actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): ?>
                                    <?php
                                    $reportId = (int) ($report['id'] ?? 0);
                                    $openCount = (int) ($report['open_count'] ?? 0);
                                    $reportCount = (int) ($report['report_count'] ?? 1);
                                    $reportStatus = $openCount > 0 ? 'open' : (string) ($report['status'] ?? 'reviewed');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?= e(tc_admin_moderation_excerpt((string) ($report['body'] ?? ''))) ?></strong>
                                            <div class="table-meta">
                                                #<?= e((string) ($report['content_id'] ?? '')) ?>
                                                <?php if ((string) ($report['author_name'] ?? '') !== ''): ?>
                                                    · <?= e((string) $report['author_name']) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="table-meta"><?= e(tc_admin_moderation_report_count_label($reportCount)) ?></div>
                                            <?php if ((string) ($report['note'] ?? '') !== ''): ?>
                                                <div class="table-meta"><?= e((string) $report['note']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e(tc_admin_moderation_reason_label((string) ($report['reason'] ?? 'other'))) ?></td>
                                        <td>
                                            <span class="badge"><?= e(tc_admin_moderation_report_status($reportStatus)) ?></span>
                                            <div class="table-meta"><?= e(datetime((string) ($report['latest_reported_at'] ?? $report['created_at'] ?? ''))) ?></div>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <a class="btn btn-sm btn-ghost btn-icon" href="<?= e(status_url((int) ($report['content_id'] ?? 0))) ?>" title="<?= et('account.status_permalink') ?>" aria-label="<?= et('account.status_permalink') ?>">
                                                    <?= icon('link') ?>
                                                </a>
                                                <?php if ($openCount > 0): ?>
                                                    <?= tc_admin_moderation_report_form($reportId, 'remove', 'trash', 'moderation.remove_content', 'danger') ?>
                                                    <?= tc_admin_moderation_report_form($reportId, 'dismiss', 'lock', 'moderation.keep_and_lock') ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <?php
});

function tc_admin_moderation_handle(): never
{
    $action = (string) post('action', '');

    if ($action === 'report_review') {
        tc_admin_moderation_review_report();
    }

    redirect('/admin/moderation');
}

function tc_admin_moderation_review_report(): never
{
    $reportId = max(0, (int) post('report_id', 0));
    $decision = (string) post('decision', '');
    $report = one('SELECT * FROM content_reports WHERE id = ? LIMIT 1', [$reportId]);

    if ($report === null) {
        flash('error', t('moderation.messages.report_not_found'));
        redirect('/admin/moderation');
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

    notification_create_for_reporters(
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
    redirect('/admin/moderation');
}

function tc_admin_moderation_reports(): array
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

function tc_admin_moderation_excerpt(string $body): string
{
    $body = trim((string) preg_replace('/\s+/', ' ', $body));

    if ($body === '') {
        return t('moderation.empty_content');
    }

    return plain_text_limit($body, 120);
}

function tc_admin_moderation_reason_label(string $reason): string
{
    $reasons = status_report_reasons();

    return (string) ($reasons[$reason] ?? $reasons['other']);
}

function tc_admin_moderation_report_count_label(int $count): string
{
    $count = max(0, $count);

    return $count === 1
        ? t('moderation.report_count_one')
        : t('moderation.report_count_many', ['count' => $count]);
}

function tc_admin_moderation_report_status(string $status): string
{
    return match ($status) {
        'open' => t('moderation.report_statuses.open'),
        'resolved' => t('moderation.report_statuses.resolved'),
        'dismissed' => t('moderation.report_statuses.dismissed'),
        default => t('moderation.report_statuses.reviewed'),
    };
}

function tc_admin_moderation_report_form(int $reportId, string $decision, string $icon, string $labelKey, string $variant = ''): string
{
    $class = trim('btn btn-sm btn-ghost btn-icon ' . ($variant === 'danger' ? 'text-danger' : ''));

    ob_start();
    ?>
    <form method="post" action="/admin/moderation">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="report_review">
        <input type="hidden" name="report_id" value="<?= e($reportId) ?>">
        <input type="hidden" name="decision" value="<?= e($decision) ?>">
        <button class="<?= e($class) ?>" type="submit" title="<?= et($labelKey) ?>" aria-label="<?= et($labelKey) ?>">
            <?= icon($icon) ?>
        </button>
    </form>
    <?php

    return trim((string) ob_get_clean());
}
