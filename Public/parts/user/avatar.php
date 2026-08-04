<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = is_array($user ?? null) ? $user : null;
$alt = (string) ($alt ?? '');
$fallbackIcon = (string) ($fallback_icon ?? 'user');
$url = user_avatar_url($user);
?>
<?php if ($url !== ''): ?>
    <img src="<?= e($url) ?>" alt="<?= e($alt) ?>" loading="lazy" data-user-avatar-image>
<?php endif; ?>
<span class="avatar-fallback" data-user-avatar-fallback<?= $url !== '' ? ' hidden' : '' ?>><?= icon($fallbackIcon) ?></span>
