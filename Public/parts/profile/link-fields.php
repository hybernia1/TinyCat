<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$links = is_array($links ?? null) ? $links : [];
?>
<div class="grid sm:grid-2 profile-links-fields">
    <?php foreach (profile_link_types() as $type => $label): ?>
        <label class="field">
            <span class="label"><?= e($label) ?></span>
            <input class="input" type="text" inputmode="url" name="profile_link_<?= e($type) ?>" maxlength="2048" value="<?= e((string) ($links[$type] ?? '')) ?>" placeholder="https://">
        </label>
    <?php endforeach; ?>
</div>
