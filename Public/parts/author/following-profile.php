<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$profile = is_array($profile ?? null) ? $profile : [];
$profileId = (int) ($profile['id'] ?? 0);
$profileName = user_display_name($profile);

if ($profileId < 1) {
    return '';
}
?>
<a class="profile-following-link" href="<?= e(author_url($profileId)) ?>">
    <span class="avatar avatar-sm">
        <?= part('user/avatar', ['user' => $profile, 'alt' => $profileName]) ?>
    </span>
    <span class="profile-following-main">
        <strong><?= e($profileName) ?></strong>
    </span>
</a>
