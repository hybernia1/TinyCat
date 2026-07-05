<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_menu_form_fields') || !function_exists('tc_admin_menu_api_url')) {
    http_response_code(404);
    return;
}

$item = (array) ($item ?? []);
$id = (int) ($item['id'] ?? 0);

if ($id <= 0) {
    http_response_code(404);
    return;
}

$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.save') . '</span></button>';

echo render('modals/layout', [
    'id' => 'menu-edit-' . $id,
    'title' => t('menu.edit_title'),
    'icon' => 'edit',
    'action' => tc_admin_menu_api_url('update', ['id' => $id]),
    'method' => 'PATCH',
    'target' => '#menu-list',
    'closeOnSuccess' => true,
    'body' => tc_admin_menu_form_fields($item),
    'footer' => $footer,
]);
