<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$sidebarUrl = (string) ($sidebar_url ?? '/api/sidebar');
?>
<template data-public-sidebar-slot data-sidebar-url="<?= e($sidebarUrl) ?>"></template>
