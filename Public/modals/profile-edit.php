<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = (array) ($user ?? []);
$actor = (array) ($actor ?? []);
$authorId = (int) ($author_id ?? 0);
$action = (string) ($action ?? '');
$focus = (string) ($focus ?? '');
$bio = trim((string) ($user['bio'] ?? ''));
$profileLinks = user_profile_links($authorId);
$selectedLocale = language_code((string) ($user['locale'] ?? '')) ?: locale();
$selectedTheme = user_theme($user);
$themeChoices = [
    'system' => t('account.theme_system'),
    'light' => t('account.theme_light'),
    'dark' => t('account.theme_dark'),
];
$canManageSecurity = (int) ($actor['id'] ?? 0) === $authorId;
$tabs = ['profile' => ['user', t('account.profile_settings')], 'email' => ['mail', t('common.email')]];
if ($canManageSecurity) {
    $tabs['security'] = ['key', t('account.security_settings')];
}
$activeTab = match ($focus) {
    'email' => 'email',
    'password', 'security' => $canManageSecurity ? 'security' : 'profile',
    default => 'profile',
};

if ($authorId < 1 || $action === '') {
    http_response_code(404);
    return;
}

$autofocus = static fn (string $name): string => $focus === $name ? ' autofocus' : '';
$formAttributes = ' data-confirm-unsaved="true"'
    . ' data-confirm-unsaved-title="' . e(t('common.unsaved_title')) . '"'
    . ' data-confirm-unsaved-message="' . e(t('common.unsaved_message')) . '"'
    . ' data-confirm-unsaved-ok="' . e(t('common.leave')) . '"'
    . ' data-confirm-unsaved-cancel="' . e(t('common.stay')) . '"';

ob_start();
?>
<div class="profile-edit-tabs" data-tabs>
    <div class="tabs" role="tablist" aria-label="<?= et('account.profile_settings') ?>">
        <?php foreach ($tabs as $tab => [$icon, $label]): ?>
            <?php $selected = $tab === $activeTab; ?>
            <button class="tab" type="button" id="profile-edit-tab-<?= e($tab) ?>" role="tab" aria-controls="profile-edit-panel-<?= e($tab) ?>" aria-selected="<?= $selected ? 'true' : 'false' ?>" data-tab="<?= e($tab) ?>">
                <?= icon($icon) ?> <span><?= e($label) ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <form class="tab-panel profile-edit-form" id="profile-edit-panel-profile" role="tabpanel" aria-labelledby="profile-edit-tab-profile" data-tab-panel="profile" action="/api/profile/update" method="post" data-ajax-form<?= $activeTab === 'profile' ? '' : ' hidden' ?><?= $formAttributes ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="author_id" value="<?= $authorId ?>">
        <div class="profile-modal-grid">
            <label class="field">
                <span class="label"><?= et('common.language') ?></span>
                <select class="select" name="locale" required<?= $autofocus('locale') ?>>
                    <?= language_options($selectedLocale) ?>
                </select>
            </label>
            <label class="field">
                <span class="label"><?= et('account.theme') ?></span>
                <select class="select" name="theme" required<?= $autofocus('theme') ?>>
                    <?php foreach ($themeChoices as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= $value === $selectedTheme ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field profile-modal-span">
                <span class="label"><?= et('account.bio') ?></span>
                <textarea class="textarea" name="bio" rows="6" maxlength="500"<?= $autofocus('bio') ?>><?= e($bio) ?></textarea>
            </label>
            <section class="profile-modal-span stack">
                <div>
                    <span class="label"><?= et('profile_links.title') ?></span>
                    <span class="help"><?= et('profile_links.help') ?></span>
                </div>
                <?= part('profile/link-fields', ['links' => $profileLinks]) ?>
            </section>
        </div>
    </form>

    <form class="tab-panel profile-edit-form stack" id="profile-edit-panel-email" role="tabpanel" aria-labelledby="profile-edit-tab-email" data-tab-panel="email" action="/api/profile/email" method="post" data-ajax-form<?= $activeTab === 'email' ? '' : ' hidden' ?><?= $formAttributes ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="author_id" value="<?= $authorId ?>">
        <label class="field">
            <span class="label"><?= et('common.email') ?></span>
            <input class="input" type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" maxlength="<?= user_email_max_length() ?>" autocomplete="email"<?= $autofocus('email') ?>>
            <span class="help"><?= et('account.email_optional') ?></span>
        </label>
        <?php if (email_notification_templates_enabled()): ?>
            <label class="check-line">
                <input type="checkbox" name="email_notifications" value="1"<?= (bool) ($user['email_notifications'] ?? true) ? ' checked' : '' ?><?= user_email_valid((string) ($user['email'] ?? '')) ? '' : ' disabled' ?>>
                <span><?= et('account.email_notifications') ?><?php if (!user_email_valid((string) ($user['email'] ?? ''))): ?> <small class="text-muted"><?= et('account.email_notifications_email_required') ?></small><?php endif; ?></span>
            </label>
        <?php endif; ?>
    </form>

    <?php if ($canManageSecurity): ?>
    <form class="tab-panel profile-edit-form stack" id="profile-edit-panel-security" role="tabpanel" aria-labelledby="profile-edit-tab-security" data-tab-panel="security" action="/api/profile/password" method="post" data-ajax-form<?= $activeTab === 'security' ? '' : ' hidden' ?><?= $formAttributes ?>>
        <?= csrf_field() ?>
        <label class="field">
            <span class="label"><?= et('common.current_password') ?></span>
            <input class="input" type="password" name="current_password" autocomplete="current-password" maxlength="<?= auth_password_max_length() ?>" required<?= $autofocus('current_password') ?>>
        </label>
        <label class="field">
            <span class="label"><?= et('common.new_password') ?></span>
            <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required<?= $autofocus('password') ?>>
        </label>
        <label class="field">
            <span class="label"><?= et('common.password_confirm') ?></span>
            <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= auth_password_max_length() ?>" required<?= $autofocus('password_confirm') ?>>
        </label>
    </form>
    <?php endif; ?>
</div>
<?php

$body = trim((string) ob_get_clean());
$footer = '<button class="btn btn-primary" type="submit" form="profile-edit-panel-' . e($activeTab) . '" data-tab-submit>' . icon('save') . ' <span>' . et('common.save') . '</span></button>';

echo render('modals/layout', [
    'id' => author_profile_edit_modal_id($authorId),
    'title' => t('account.profile_settings'),
    'icon' => 'edit',
    'modalClass' => 'modal-form',
    'size' => 'modal-panel-lg',
    'body' => $body,
    'footer' => $footer,
]);
