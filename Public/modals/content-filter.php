<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('tc_admin_content_filter_fields')) {
    http_response_code(404);
    return;
}

$filters = tc_admin_content_filters();
$body = tc_admin_content_filter_fields($filters);
$clearParams = ['per_page' => admin_per_page(), 'page' => 1];
$footer = '<a class="btn btn-secondary" href="' . e(tc_admin_content_api_url('list', $clearParams, false)) . '" data-ajax data-ajax-target="#content-list" data-history="' . e(admin_list_url('/admin/content', $clearParams, false)) . '" data-modal-close>' . icon('close') . ' <span>' . et('common.clear_filters') . '</span></a>'
    . '<button class="btn btn-primary" type="submit">' . icon('filter') . ' <span>' . et('common.apply_filters') . '</span></button>';

echo render('modals/layout', [
    'id' => 'content-filter-modal',
    'title' => t('content.filter_title'),
    'icon' => 'filter',
    'action' => tc_admin_content_api_url('list'),
    'method' => 'GET',
    'target' => '#content-list',
    'closeOnSuccess' => true,
    'csrf' => false,
    'formAttributes' => [
        'data-history' => '/admin/content',
    ],
    'body' => $body,
    'footer' => $footer,
]);
