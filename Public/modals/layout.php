<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$id = (string) ($id ?? '');
$title = (string) ($title ?? '');
$iconName = (string) ($icon ?? '');
$size = trim((string) ($size ?? ''));
$modalClass = trim('modal ' . (string) ($modalClass ?? ''));
$body = (string) ($body ?? '');
$footer = (string) ($footer ?? '');
$action = (string) ($action ?? '');
$method = strtoupper((string) ($method ?? 'POST'));
$methodOverride = strtoupper((string) ($methodOverride ?? ''));
$target = (string) ($target ?? '');
$labelledBy = (string) ($labelledBy ?? ($id !== '' ? $id . '-title' : 'modal-title'));
$panelClass = trim('modal-panel ' . $size);
$formMethod = in_array($method, ['GET', 'POST'], true) ? strtolower($method) : 'post';
$hiddenMethod = $methodOverride !== '' ? $methodOverride : (in_array($method, ['GET', 'POST'], true) ? '' : $method);
$multipart = (bool) ($multipart ?? false);
$csrf = (bool) ($csrf ?? ($formMethod !== 'get'));
$reset = (bool) ($reset ?? false);
$closeOnSuccess = (bool) ($closeOnSuccess ?? false);
$ajax = (bool) ($ajax ?? ($action !== ''));
$formAttributes = (array) ($formAttributes ?? []);
$panelAttributes = (array) ($panelAttributes ?? []);

if ($id === '') {
    http_response_code(404);
    return;
}

$attributes = static function (array $items): string {
    $html = '';

    foreach ($items as $name => $value) {
        if ($value === false || $value === null) {
            continue;
        }

        if ($value === true) {
            $html .= ' ' . e((string) $name);
            continue;
        }

        $html .= ' ' . e((string) $name) . '="' . e((string) $value) . '"';
    }

    return $html;
};
?>
<div class="<?= e($modalClass) ?>" id="<?= e($id) ?>" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="<?= e($labelledBy) ?>" data-open="false">
    <div class="modal-backdrop"></div>
    <?php if ($action !== ''): ?>
        <form class="<?= e($panelClass) ?>" action="<?= e($action) ?>" method="<?= e($formMethod) ?>"<?= $multipart ? ' enctype="multipart/form-data"' : '' ?><?= $ajax ? ' data-ajax-form' : '' ?><?= $target !== '' ? ' data-ajax-target="' . e($target) . '"' : '' ?><?= $reset ? ' data-reset="true"' : '' ?><?= $closeOnSuccess ? ' data-modal-close-on-success="true"' : '' ?><?= $attributes($formAttributes) ?>>
            <?php if ($csrf): ?>
                <?= csrf_field() ?>
            <?php endif; ?>
            <?php if ($hiddenMethod !== ''): ?>
                <input type="hidden" name="_method" value="<?= e($hiddenMethod) ?>">
            <?php endif; ?>
    <?php else: ?>
        <div class="<?= e($panelClass) ?>"<?= $attributes($panelAttributes) ?>>
    <?php endif; ?>
            <div class="modal-header">
                <h2 class="text-lg m-0 cluster gap-2" id="<?= e($labelledBy) ?>">
                    <?= $iconName !== '' ? icon($iconName) . ' ' : '' ?><?= e($title) ?>
                </h2>
                <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-close aria-label="<?= et('common.close') ?>"><?= icon('close') ?></button>
            </div>
            <div class="modal-body stack">
                <?= $body ?>
            </div>
            <?php if ($footer !== ''): ?>
                <div class="modal-footer">
                    <?= $footer ?>
                </div>
            <?php endif; ?>
    <?php if ($action !== ''): ?>
        </form>
    <?php else: ?>
        </div>
    <?php endif; ?>
</div>
