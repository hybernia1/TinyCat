<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$mode = auth_modal_mode((string) ($mode ?? 'login'));
$next = auth_safe_next_url((string) ($next ?? ''));
$old = is_array($old ?? null) ? $old : [];
$isRegister = $mode === 'register';
?>
<form class="stack" method="post" action="<?= e(auth_url_with_next('/' . $mode, $next)) ?>" data-ajax-form data-ajax-action="<?= e($isRegister ? '/api/auth/register' : '/api/auth/login') ?>" data-confirm-unsaved="true" data-confirm-unsaved-title="<?= e(t('common.unsaved_title')) ?>" data-confirm-unsaved-message="<?= e(t('common.unsaved_message')) ?>" data-confirm-unsaved-ok="<?= e(t('common.leave')) ?>" data-confirm-unsaved-cancel="<?= e(t('common.stay')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <?php if ($isRegister): ?>
        <label class="field">
            <span class="label"><?= et('common.username') ?></span>
            <input class="input" name="username" value="<?= e((string) ($old['username'] ?? '')) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" inputmode="text" enterkeyhint="next" pattern="[a-z][a-z0-9_]{2,31}" maxlength="32" required>
            <span class="help"><?= e(username_hint()) ?></span>
        </label>
        <label class="field">
            <span class="label"><?= et('common.email') ?></span>
            <input class="input" type="email" name="email" value="<?= e((string) ($old['email'] ?? '')) ?>" autocomplete="email" enterkeyhint="next" maxlength="<?= user_email_max_length() ?>">
            <span class="help"><?= et('auth.email_optional') ?></span>
        </label>
        <label class="field">
            <span class="label"><?= et('common.password') ?></span>
            <input class="input" type="password" name="password" autocomplete="new-password" enterkeyhint="next" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
        </label>
        <label class="field">
            <span class="label"><?= et('common.password_confirm') ?></span>
            <input class="input" type="password" name="password_confirm" autocomplete="new-password" enterkeyhint="done" minlength="8" maxlength="<?= auth_password_max_length() ?>" required>
        </label>
        <label class="check-line">
            <input type="checkbox" name="platform_terms" value="1" required<?= !empty($old['platform_terms']) ? ' checked' : '' ?>>
            <span><?= et('auth.platform_terms_agree') ?> <a href="/privacy" target="_blank" rel="noopener"><?= et('privacy.title') ?></a></span>
        </label>
        <?= captcha_field('register') ?>
        <button class="btn btn-primary" type="submit"><?= icon('user-plus') ?> <span><?= et('common.register') ?></span></button>
    <?php else: ?>
        <label class="field">
            <span class="label"><?= et('auth.login_identifier') ?></span>
            <input class="input" name="username" value="<?= e((string) ($old['username'] ?? '')) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" enterkeyhint="next" required>
        </label>
        <label class="field">
            <span class="label"><?= et('common.password') ?></span>
            <input class="input" type="password" name="password" autocomplete="current-password" enterkeyhint="done" maxlength="<?= auth_password_max_length() ?>" required>
        </label>
        <?php if (auth_login_captcha_required()): ?>
            <?= captcha_field('login') ?>
        <?php endif; ?>
        <div class="login-submit-row">
            <label class="check-line">
                <input type="checkbox" name="remember" value="1"<?= !empty($old['remember']) ? ' checked' : '' ?>>
                <span><?= et('auth.remember_me') ?></span>
            </label>
            <button class="btn btn-primary" type="submit"><?= icon('login') ?> <span><?= et('common.login') ?></span></button>
        </div>
    <?php endif; ?>
</form>
