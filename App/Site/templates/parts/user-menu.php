<?php
declare(strict_types=1);

use TinyCat\Support\Html;

/** @var array<mixed> $authUser */
/** @var string $profileUrl */
/** @var bool $profileNeedsEmail */
/** @var string $theme */
/** @var string $displayName */
/** @var string $editModalId */
/** @var string $editModalUrl */
?>
<details class="context-menu user-menu" data-dismissible-menu>
    <summary class="nav-link user-menu-trigger profile-nav-link" aria-label="<?= Html::et('account.profile_settings') ?>" title="<?= Html::e($displayName) ?>">
        <span class="avatar avatar-sm"><?= \TinyCat\Users\Views::avatar(['user' => $authUser]) ?></span>
        <?= Html::icon('chevron-down') ?>
        <?php if ($profileNeedsEmail): ?><span class="notification-badge profile-email-warning" aria-hidden="true">!</span><span class="sr-only"><?= Html::et('account.email_missing_title') ?></span><?php endif; ?>
    </summary>
    <div class="context-menu-popover user-menu-popover">
        <strong class="user-menu-identity"><?= Html::e($displayName) ?></strong>
        <a class="context-menu-action" href="<?= Html::e($profileUrl) ?>" data-dismissible-menu-action><?= Html::icon('user') ?> <span><?= Html::et('account.public_profile') ?></span></a>
        <a class="context-menu-action" href="<?= Html::e($profileUrl) ?>" data-modal-open="<?= Html::e($editModalId) ?>" data-modal-url="<?= Html::e($editModalUrl) ?>" data-dismissible-menu-action><?= Html::icon('settings') ?> <span><?= Html::et('settings.title') ?></span></a>
        <div class="user-menu-theme">
            <div class="user-menu-theme-switch" role="group" aria-label="<?= Html::et('account.theme') ?>">
                <?php foreach (['system', 'light', 'dark'] as $option): ?>
                    <button type="button" data-user-menu-theme="<?= Html::e($option) ?>" aria-label="<?= Html::et('account.theme_' . $option) ?>" title="<?= Html::et('account.theme_' . $option) ?>" aria-pressed="<?= $theme === $option ? 'true' : 'false' ?>"><?= Html::icon(['system' => 'monitor', 'light' => 'sun', 'dark' => 'moon'][$option]) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <form action="/api/users/sessions/current" data-ajax-form data-api-method="DELETE" method="post" data-confirm="<?= Html::et('auth.logout_confirm') ?>" data-confirm-title="<?= Html::et('auth.logout_title') ?>" data-confirm-ok="<?= Html::et('common.logout') ?>" data-confirm-cancel="<?= Html::et('common.cancel') ?>" data-confirm-variant="danger">
            <?= \TinyCat\Security\Csrf::field() ?>
            <input type="hidden" name="_method" value="DELETE">
            <button class="context-menu-action is-danger" type="submit"><?= Html::icon('logout') ?> <span><?= Html::et('common.logout') ?></span></button>
        </form>
    </div>
</details>
