<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$reports = is_array($reports ?? null) ? $reports : [];
?>
<div class="stack stack-gap-14">
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
                        $permalinkUrl = (string) ($report['permalink_url'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <strong><?= e((string) ($report['excerpt'] ?? '')) ?></strong>
                                <div class="table-meta">
                                    #<?= e((string) ($report['content_id'] ?? '')) ?>
                                    <?php if ((string) ($report['author_name'] ?? '') !== ''): ?>
                                        &middot; @<?= e((string) $report['author_name']) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="table-meta"><?= e((string) ($report['report_count_label'] ?? '')) ?></div>
                                <?php if ((string) ($report['note'] ?? '') !== ''): ?>
                                    <div class="table-meta"><?= e((string) $report['note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($report['reason_label'] ?? '')) ?></td>
                            <td>
                                <span class="<?= e((string) ($report['status_badge_class'] ?? 'badge')) ?>"><?= e((string) ($report['status_label'] ?? '')) ?></span>
                                <div class="table-meta"><?= e((string) ($report['reported_at_label'] ?? '')) ?></div>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <?php if ($permalinkUrl !== ''): ?>
                                        <a class="btn btn-sm btn-ghost btn-icon" href="<?= e($permalinkUrl) ?>" title="<?= e((string) ($report['permalink_label'] ?? '')) ?>" aria-label="<?= e((string) ($report['permalink_label'] ?? '')) ?>">
                                            <?= icon('link') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($openCount > 0): ?>
                                        <?= part('admin/moderation/report-action', [
                                            'report_id' => $reportId,
                                            'decision' => 'remove',
                                            'icon_name' => 'trash',
                                            'label_key' => 'moderation.remove_content',
                                            'variant' => 'danger',
                                        ]) ?>
                                        <?= part('admin/moderation/report-action', [
                                            'report_id' => $reportId,
                                            'decision' => 'dismiss',
                                            'icon_name' => 'lock',
                                            'label_key' => 'moderation.keep_and_lock',
                                        ]) ?>
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
