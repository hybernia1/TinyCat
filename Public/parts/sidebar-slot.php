<?php
declare(strict_types=1);

use TinyCat\Support\Html;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$sidebarUrl = (string) ($sidebar_url ?? '/api/site/sidebar');
?>
<template data-public-sidebar-slot data-sidebar-url="<?= Html::e($sidebarUrl) ?>"></template>
