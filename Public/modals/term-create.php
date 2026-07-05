<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_terms_form_fields') || !function_exists('tc_admin_terms_api_url')) {
    http_response_code(404);
    return;
}

$footer = '<button class="btn btn-secondary" type="button" data-modal-close>' . icon('close') . ' <span>' . et('common.cancel') . '</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('save') . ' <span>' . et('common.create') . '</span></button>';

echo render('modals/layout', [
    'id' => 'term-create-modal',
    'title' => t('terms.new_term'),
    'icon' => 'folder',
    'action' => tc_admin_terms_api_url('create'),
    'target' => '#terms-list',
    'reset' => true,
    'closeOnSuccess' => true,
    'body' => tc_admin_terms_form_fields(),
    'footer' => $footer,
]);
