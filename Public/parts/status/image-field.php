<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$image = is_array($image ?? null) ? $image : [];
$imageUrl = (string) ($image['url'] ?? '');
$enabled = (bool) ($image['enabled'] ?? false);
?>
<div class="status-image-field" data-status-image<?= $enabled || $imageUrl !== '' ? '' : ' hidden' ?>>
    <input type="hidden" name="remove_image" value="0" data-status-image-remove-input>
    <button class="status-image-preview" type="button" data-status-image-preview-wrap data-status-image-remove title="<?= et('account.status_image_remove') ?>" aria-label="<?= et('account.status_image_remove') ?>"<?= $imageUrl !== '' ? '' : ' hidden' ?>>
        <img<?= $imageUrl !== '' ? ' src="' . e($imageUrl) . '"' : '' ?> alt="<?= e((string) ($image['alt'] ?? '')) ?>" data-status-image-preview<?= $imageUrl !== '' ? '' : ' hidden' ?>>
    </button>
    <label class="btn btn-secondary btn-sm btn-icon status-image-picker" data-status-image-picker<?= $enabled && $imageUrl === '' ? '' : ' hidden' ?> title="<?= et($imageUrl === '' ? 'account.status_image_add' : 'account.status_image_replace') ?>" aria-label="<?= et($imageUrl === '' ? 'account.status_image_add' : 'account.status_image_replace') ?>">
        <input class="sr-only" type="file" name="image" accept="image/jpeg,image/png,image/webp" data-status-image-input data-client-image-max-dimension="800" data-client-image-max-bytes="26214400">
        <?= icon('upload') ?>
    </label>
</div>
