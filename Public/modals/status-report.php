<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$item = (array) ($item ?? []);
$user = isset($user) && is_array($user) ? $user : null;
$action = (string) ($action ?? '');
$contentId = (int) ($item['id'] ?? 0);
$authorId = (int) ($item['author_id'] ?? 0);

if ($contentId < 1 || $user === null || $authorId === (int) ($user['id'] ?? 0)) {
    return;
}

$modalId = status_report_modal_id($contentId);

ob_start();
?>
<input type="hidden" name="action" value="report">
<input type="hidden" name="id" value="<?= e($contentId) ?>">
<label class="field">
    <span class="label"><?= et('moderation.report_reason') ?></span>
    <select class="select" name="reason">
        <?php foreach (status_report_reasons() as $value => $label): ?>
            <option value="<?= e((string) $value) ?>"><?= e((string) $label) ?></option>
        <?php endforeach; ?>
    </select>
</label>
<label class="field">
    <span class="label"><?= et('moderation.report_note') ?></span>
    <textarea class="textarea" name="note" rows="4" maxlength="1000"></textarea>
</label>
<?php
$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('flag') . ' <span>' . et('moderation.report_submit') . '</span></button>';

echo render('modals/layout', [
    'id' => $modalId,
    'title' => t('moderation.report_status'),
    'icon' => 'flag',
    'action' => $action,
    'ajax' => false,
    'size' => 'modal-panel-lg status-report-modal-panel',
    'formAttributes' => [
        'data-status-form' => true,
        'data-status-id' => $contentId,
    ],
    'body' => $body,
    'footer' => $footer,
]);
