<?php
declare(strict_types=1);
/** @var string $recoveryToken */
/** @var mixed $authPasswordMaxLength */
/** @var mixed $field */
?>
<section class="max-w-auth mx-auto">
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('key') ?> <?= \TinyCat\Support\Html::et('auth.recovery_title') ?></h1>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et($recoveryToken !== '' ? 'auth.recovery_reset_intro' : 'auth.recovery_intro') ?></p>
            <form data-ajax-form data-ajax-action="<?= $recoveryToken !== '' ? '/api/users/password-resets/confirm' : '/api/users/password-resets' ?>" class="stack" method="post" action="/recovery<?= $recoveryToken !== '' ? '?token=' . \TinyCat\Support\Html::e(rawurlencode($recoveryToken)) : '' ?>">
                <?= \TinyCat\Security\Csrf::field() ?>
                <?php if ($recoveryToken !== ''): ?>
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="token" value="<?= \TinyCat\Support\Html::e($recoveryToken) ?>">
                <?php endif; ?>
                <label class="field">
                    <span class="label"><?= \TinyCat\Support\Html::et($recoveryToken !== '' ? 'common.new_password' : 'common.email') ?></span>
                    <?php if ($recoveryToken !== ''): ?>
                        <input class="input" type="password" name="password" autocomplete="new-password" minlength="8" maxlength="<?= $authPasswordMaxLength ?>" required>
                    <?php else: ?>
                        <input class="input" type="email" name="email" autocomplete="email" required>
                    <?php endif; ?>
                </label>
                <?php if ($recoveryToken !== ''): ?>
                    <label class="field">
                        <span class="label"><?= \TinyCat\Support\Html::et('common.password_confirm') ?></span>
                        <input class="input" type="password" name="password_confirm" autocomplete="new-password" minlength="8" maxlength="<?= $authPasswordMaxLength ?>" required>
                    </label>
                <?php endif; ?>
                <?= $field ?>
                <button class="btn btn-primary" type="submit"><?= \TinyCat\Support\Html::icon('save') ?> <span><?= \TinyCat\Support\Html::et('auth.recovery_submit') ?></span></button>
            </form>
            <div class="cluster gap-2">
                <a class="btn btn-secondary btn-sm" href="/login"><?= \TinyCat\Support\Html::icon('login') ?> <span><?= \TinyCat\Support\Html::et('common.login') ?></span></a>
                <a class="btn btn-ghost btn-sm" href="/privacy" rel="privacy-policy"><?= \TinyCat\Support\Html::icon('shield') ?> <span><?= \TinyCat\Support\Html::et('privacy.title') ?></span></a>
            </div>
        </div>
    </article>
</section>
