<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = is_array($user ?? null) ? $user : null;
$roles = is_array($roles ?? null) ? $roles : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$create = (bool) ($create ?? false);
$role = (string) ($user['role'] ?? 'user');
$status = (string) ($user['status'] ?? 'active');
$superAdminLocked = !$create && $user !== null && tc_admin_user_is_super_admin($user);
$profileLinks = (array) ($user['profile_links'] ?? []);
?>
<div class="user-editor-layout">
    <div class="user-editor-main stack">
        <section class="card card-body">
            <div class="grid sm:grid-2">
                <label class="field">
                    <span class="label"><?= et('common.username') ?></span>
                    <?php if ($create): ?>
                        <input class="input input-lg" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" value="" required>
                        <span class="help"><?= e(username_hint()) ?></span>
                    <?php else: ?>
                        <input class="input input-lg" value="<?= e((string) ($user['username'] ?? '')) ?>" disabled>
                        <span class="help"><?= et('users.username_locked') ?></span>
                    <?php endif; ?>
                </label>
                <label class="field">
                    <span class="label"><?= et('common.email') ?></span>
                    <input class="input input-lg" type="email" name="email" autocomplete="email" maxlength="<?= user_email_max_length() ?>" value="<?= e((string) ($user['email'] ?? '')) ?>">
                    <span class="help"><?= et('account.email_optional') ?></span>
                </label>
            </div>
        </section>

        <?php if (!$create): ?>
            <section class="card card-body stack stack-gap-12">
                <label class="field">
                    <span class="label"><?= et('account.bio') ?></span>
                    <textarea class="textarea" name="bio" rows="5" maxlength="500"><?= e((string) ($user['bio'] ?? '')) ?></textarea>
                </label>
            </section>
            <section class="card card-body stack stack-gap-12">
                <div><span class="label"><?= et('profile_links.title') ?></span><span class="help"><?= et('profile_links.help') ?></span></div>
                <?= part('profile/link-fields', ['links' => $profileLinks]) ?>
            </section>
        <?php endif; ?>

        <section class="card card-body">
            <label class="field">
                <span class="label"><?= $create ? et('common.password') : et('common.new_password') ?></span>
                <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" placeholder="<?= $create ? '' : et('users.password_keep') ?>">
            </label>
        </section>
    </div>

    <aside class="user-editor-sidebar">
        <?php if (!$create): ?>
            <section class="card card-body stack stack-gap-12">
                <span class="label"><?= et('account.avatar') ?></span>
                <div class="avatar avatar-xl"><?= part('user/avatar', ['user' => $user, 'alt' => (string) ($user['username'] ?? '')]) ?></div>
                <label class="field"><span class="label"><?= et('account.avatar_upload_label') ?></span><input class="input" type="file" name="avatar" accept="image/png,image/jpeg,image/webp" data-client-image-input data-client-image-max-dimension="400" data-client-image-max-bytes="26214400"></label>
                <?php if (user_avatar_url($user) !== ''): ?>
                    <label class="check"><input type="checkbox" name="remove_avatar" value="1"> <span><?= et('account.remove_avatar') ?></span></label>
                <?php endif; ?>
            </section>
        <?php endif; ?>
        <section class="card card-body">
            <div class="stack stack-gap-12">
                <label class="field">
                    <span class="label"><?= et('common.role') ?></span>
                    <?php if ($superAdminLocked): ?>
                        <input type="hidden" name="role" value="admin">
                    <?php endif; ?>
                    <select class="select" name="<?= $superAdminLocked ? 'role_locked' : 'role' ?>"<?= $superAdminLocked ? ' disabled' : '' ?>><?= tc_admin_options($roles, $role) ?></select>
                </label>
                <label class="field">
                    <span class="label"><?= et('common.status') ?></span>
                    <?php if ($superAdminLocked): ?>
                        <input type="hidden" name="status" value="active">
                    <?php endif; ?>
                    <select class="select" name="<?= $superAdminLocked ? 'status_locked' : 'status' ?>"<?= $superAdminLocked ? ' disabled' : '' ?>><?= tc_admin_options($statuses, $status) ?></select>
                </label>
            </div>
        </section>
    </aside>
</div>
