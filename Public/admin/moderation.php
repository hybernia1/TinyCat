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
    $domains = moderation_blocked_domains();
    ?>
    <section class="grid lg:grid-2">
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
                                                    <?= tc_admin_moderation_report_form($reportId, 'hide', 'eye-off', 'moderation.hide_content') ?>
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

        <article class="card">
            <div class="card-header">
                <h2 class="text-lg m-0 cluster gap-2"><?= icon('shield') ?> <?= et('moderation.blocked_domains_title') ?></h2>
            </div>
            <div class="card-body stack">
                <form class="stack" method="post" action="/admin/moderation">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="block_domain">
                    <label class="field">
                        <span class="label"><?= et('moderation.domain') ?></span>
                        <input class="input" name="domain" placeholder="example.com" required>
                    </label>
                    <label class="field">
                        <span class="label"><?= et('moderation.block_reason') ?></span>
                        <textarea class="textarea" name="reason" rows="3" maxlength="1000"></textarea>
                    </label>
                    <div class="cluster" style="justify-content: flex-end;">
                        <button class="btn btn-primary" type="submit"><?= icon('plus') ?> <span><?= et('moderation.block_domain') ?></span></button>
                    </div>
                </form>

                <?php if ($domains === []): ?>
                    <div class="alert alert-info"><?= et('moderation.blocked_domains_empty') ?></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?= et('moderation.domain') ?></th>
                                    <th><?= et('common.created') ?></th>
                                    <th><?= et('common.actions') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($domains as $domain): ?>
                                    <tr>
                                        <td>
                                            <strong><?= e((string) ($domain['domain'] ?? '')) ?></strong>
                                            <?php if ((string) ($domain['reason'] ?? '') !== ''): ?>
                                                <div class="table-meta"><?= e((string) $domain['reason']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e(datetime((string) ($domain['created_at'] ?? ''))) ?></td>
                                        <td>
                                            <form method="post" action="/admin/moderation" data-confirm="<?= et('moderation.unblock_confirm', ['domain' => (string) ($domain['domain'] ?? '')]) ?>" data-confirm-title="<?= et('moderation.unblock_domain') ?>" data-confirm-ok="<?= et('moderation.unblock_domain') ?>" data-confirm-cancel="<?= et('common.cancel') ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="unblock_domain">
                                                <input type="hidden" name="id" value="<?= e((int) ($domain['id'] ?? 0)) ?>">
                                                <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" title="<?= et('moderation.unblock_domain') ?>" aria-label="<?= et('moderation.unblock_domain') ?>">
                                                    <?= icon('trash') ?>
                                                </button>
                                            </form>
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

    if ($action === 'block_domain') {
        tc_admin_moderation_block_domain();
    }

    if ($action === 'unblock_domain') {
        delete('blocked_domains', ['id' => max(0, (int) post('id', 0))]);
        flash('success', t('moderation.messages.domain_unblocked'));
        redirect('/admin/moderation');
    }

    if ($action === 'report_review') {
        tc_admin_moderation_review_report();
    }

    redirect('/admin/moderation');
}

function tc_admin_moderation_block_domain(): never
{
    $domain = moderation_domain_normalize((string) post('domain', ''));
    $reason = plain_text_limit((string) post('reason', ''), 1000);

    if ($domain === '') {
        flash('error', t('moderation.messages.invalid_domain'));
        redirect('/admin/moderation');
    }

    try {
        insert('blocked_domains', [
            'domain' => $domain,
            'reason' => $reason,
            'created_by' => (int) auth('id', 0),
            'created_at' => date_db(),
        ]);
    } catch (Throwable) {
        update('blocked_domains', [
            'reason' => $reason,
            'created_by' => (int) auth('id', 0),
            'created_at' => date_db(),
        ], ['domain' => $domain]);
    }

    flash('success', t('moderation.messages.domain_blocked'));
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

    if ($decision === 'hide') {
        update('content', ['status' => 'hidden'], ['id' => $contentId]);
        status_edit_lock($contentId, $actor, 'moderation_hide');
        user_mute($authorId, $actor, '+24 hours', 'moderation_hide');
        $status = 'resolved';
        $note = 'hidden';
    } elseif ($decision === 'remove') {
        update('content', ['status' => 'removed'], ['id' => $contentId]);
        status_edit_lock($contentId, $actor, 'moderation_remove');
        user_mute($authorId, $actor, '+24 hours', 'moderation_remove');
        $status = 'resolved';
        $note = 'removed';
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

    flash('success', t('moderation.messages.report_reviewed'));
    redirect('/admin/moderation');
}

function tc_admin_moderation_reports(): array
{
    if (!app_table_exists('content_reports')) {
        return [];
    }

    return all(
        'SELECT cr.*,
            rc.report_count,
            rc.open_count,
            rc.latest_reported_at,
            c.body,
            c.status AS content_status,
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
