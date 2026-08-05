<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$reportId = max(0, (int) ($report_id ?? 0));
$decision = (string) ($decision ?? '');
$iconName = (string) ($icon_name ?? 'flag');
$labelKey = (string) ($label_key ?? 'common.actions');
$buttonClass = trim('btn btn-sm btn-ghost btn-icon ' . ((string) ($variant ?? '') === 'danger' ? 'text-danger' : ''));
$confirmDelete = $decision === 'remove';
?>
<form class="inline-flex" method="post" action="/api/admin/moderation/reports?view=html" data-ajax-form data-ajax-target="#moderation-reports"<?= $confirmDelete ? ' data-confirm="' . et('account.status_delete_confirm') . '" data-confirm-title="' . et('account.status_delete_title') . '" data-confirm-ok="' . et('common.delete') . '" data-confirm-cancel="' . et('common.cancel') . '" data-confirm-variant="danger"' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="report_id" value="<?= e($reportId) ?>">
    <input type="hidden" name="decision" value="<?= e($decision) ?>">
    <button class="<?= e($buttonClass) ?>" type="submit" title="<?= et($labelKey) ?>" aria-label="<?= et($labelKey) ?>">
        <?= icon($iconName) ?>
    </button>
</form>
