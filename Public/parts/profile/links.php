<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$links = is_array($links ?? null) ? $links : [];

if ($links === []) {
    return '';
}
?>
<nav class="profile-links" aria-label="<?= et('profile_links.title') ?>">
    <?php foreach (profile_link_types() as $type => $label): ?>
        <?php if (!empty($links[$type])): ?>
            <a class="profile-link" href="<?= e((string) $links[$type]) ?>" target="_blank" rel="nofollow noopener noreferrer"><?= icon('link') ?> <span><?= e($label) ?></span></a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
