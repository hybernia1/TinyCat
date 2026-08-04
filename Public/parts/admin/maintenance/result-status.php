<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$result = is_array($result ?? null) ? $result : [];

if (isset($result['error'])) {
    $class = 'badge badge-danger';
    $labelKey = 'maintenance.status_error';
} elseif (!empty($result['stalled'])) {
    $class = 'badge badge-danger';
    $labelKey = 'maintenance.status_stalled';
} elseif (!empty($result['has_more'])) {
    $class = 'badge';
    $labelKey = 'maintenance.status_more';
} else {
    $class = 'badge badge-primary';
    $labelKey = 'maintenance.status_done';
}
?>
<span class="<?= e($class) ?>"><?= et($labelKey) ?></span>
