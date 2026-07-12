<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$id = (string) ($id ?? '');

if ($id === '') {
    http_response_code(404);
    return;
}

$title = (string) ($title ?? '');
$iconName = (string) ($icon ?? '');
$modalClass = trim('modal ' . (string) ($modalClass ?? ''));
$bodyClass = trim('modal-body ' . (string) ($bodyClass ?? 'stack'));
$body = (string) ($body ?? '');
$footer = (string) ($footer ?? '');
$action = (string) ($action ?? '');
$labelledBy = (string) ($labelledBy ?? $id . '-title');
$panelClass = trim('modal-panel ' . trim((string) ($size ?? '')));
$hasForm = $action !== '';

if ($hasForm) {
    $method = strtoupper((string) ($method ?? 'POST'));
    $methodOverride = strtoupper((string) ($methodOverride ?? ''));
    $nativeMethod = in_array($method, ['GET', 'POST'], true);
    $formMethod = $nativeMethod ? strtolower($method) : 'post';
    $hiddenMethod = $methodOverride !== '' ? $methodOverride : ($nativeMethod ? '' : $method);
    $target = (string) ($target ?? '');
    $multipart = (bool) ($multipart ?? false);
    $csrf = (bool) ($csrf ?? ($formMethod !== 'get'));
    $reset = (bool) ($reset ?? false);
    $closeOnSuccess = (bool) ($closeOnSuccess ?? false);
    $ajax = (bool) ($ajax ?? true);
    $extraAttributes = html_attributes((array) ($formAttributes ?? []));
} else {
    $extraAttributes = html_attributes((array) ($panelAttributes ?? []));
}
?>
<div class="<?= e($modalClass) ?>" id="<?= e($id) ?>" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="<?= e($labelledBy) ?>" data-open="false">
    <div class="modal-backdrop"></div>
    <?php if ($hasForm): ?>
        <form class="<?= e($panelClass) ?>" action="<?= e($action) ?>" method="<?= e($formMethod) ?>"<?= $multipart ? ' enctype="multipart/form-data"' : '' ?><?= $ajax ? ' data-ajax-form' : '' ?><?= $target !== '' ? ' data-ajax-target="' . e($target) . '"' : '' ?><?= $reset ? ' data-reset="true"' : '' ?><?= $closeOnSuccess ? ' data-modal-close-on-success="true"' : '' ?><?= $extraAttributes ?>>
            <?php if ($csrf): ?>
                <?= csrf_field() ?>
            <?php endif; ?>
            <?php if ($hiddenMethod !== ''): ?>
                <input type="hidden" name="_method" value="<?= e($hiddenMethod) ?>">
            <?php endif; ?>
    <?php else: ?>
        <div class="<?= e($panelClass) ?>"<?= $extraAttributes ?>>
    <?php endif; ?>
            <div class="modal-header">
                <h2 class="text-lg m-0 cluster gap-2" id="<?= e($labelledBy) ?>">
                    <?= $iconName !== '' ? icon($iconName) . ' ' : '' ?><?= e($title) ?>
                </h2>
                <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-close aria-label="<?= et('common.close') ?>"><?= icon('close') ?></button>
            </div>
            <div class="<?= e($bodyClass) ?>">
                <?= $body ?>
            </div>
            <?php if ($footer !== ''): ?>
                <div class="modal-footer">
                    <?= $footer ?>
                </div>
            <?php endif; ?>
    <?php if ($hasForm): ?>
        </form>
    <?php else: ?>
        </div>
    <?php endif; ?>
</div>
