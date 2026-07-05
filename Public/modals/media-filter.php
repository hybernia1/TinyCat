<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_media_filters') || !function_exists('tc_admin_media_api_url')) {
    http_response_code(404);
    return;
}

$filters = tc_admin_media_filters();
$clearParams = ['per_page' => admin_per_page(), 'page' => 1];
$footer = '<a class="btn btn-secondary" href="' . e(tc_admin_media_api_url('list', $clearParams, false)) . '" data-ajax data-ajax-target="#media-list" data-history="' . e(admin_list_url('/admin/media', $clearParams, false)) . '" data-modal-close>' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a>'
    . '<button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>';

ob_start();
?>
<div class="filter-modal-grid">
    <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
    <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
    <input type="hidden" name="page" value="1">
    <label class="field">
        <span class="label"><?= et('media.type') ?></span>
        <select class="select" name="type">
            <option value="all"<?= $filters['type'] === 'all' ? ' selected' : '' ?>><?= et('common.all') ?></option>
            <option value="image"<?= $filters['type'] === 'image' ? ' selected' : '' ?>><?= et('media.images') ?></option>
            <option value="file"<?= $filters['type'] === 'file' ? ' selected' : '' ?>><?= et('media.files') ?></option>
        </select>
    </label>
</div>
<?php

echo render('modals/layout', [
    'id' => 'media-filter-modal',
    'title' => t('media.filter_title'),
    'icon' => 'filter',
    'action' => tc_admin_media_api_url('list'),
    'method' => 'GET',
    'target' => '#media-list',
    'closeOnSuccess' => true,
    'csrf' => false,
    'formAttributes' => [
        'data-history' => '/admin/media',
    ],
    'body' => trim((string) ob_get_clean()),
    'footer' => $footer,
]);
