<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$status = (string) ($status ?? '');
$class = match ($status) {
    'active' => 'badge badge-primary',
    'ban' => 'badge badge-danger',
    default => 'badge',
};
?>
<span class="<?= e($class) ?>"><?= e((string) (admin_user_statuses()[$status] ?? $status)) ?></span>
