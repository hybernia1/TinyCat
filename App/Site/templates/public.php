<?php
declare(strict_types=1);
/** @var string $content */
/** @var bool $analyticsConfigured */
/** @var string $analyticsConsent */
/** @var string $appName */
/** @var array<mixed>|null $authUser */
/** @var string $cookieConsentTextKey */
/** @var string $current */
/** @var string $googleMeasurementId */
/** @var string $loginModalId */
/** @var string $loginModalUrl */
/** @var array<mixed> $nav */
/** @var array<mixed> $notificationItems */
/** @var array<mixed> $notificationState */
/** @var bool $profileNeedsEmail */
/** @var string $profileUrl */
/** @var string $searchQuery */
/** @var string $siteLogoUrl */
/** @var bool $viewerIsAdmin */
?>
        <header class="navbar">
            <div class="container navbar-inner">
                <a class="navbar-brand" href="/">
                    <?php if ($siteLogoUrl !== ''): ?>
                        <img class="brand-logo" src="<?= \TinyCat\Support\Html::e($siteLogoUrl) ?>" alt="<?= \TinyCat\Support\Html::et('common.site_logo_alt', ['site' => $appName]) ?>">
                    <?php endif; ?>
                    <strong<?= $siteLogoUrl !== '' ? ' aria-hidden="true"' : '' ?>><?= \TinyCat\Support\Html::e($appName) ?></strong>
                </a>
                <form class="global-search" action="/search" method="get" role="search" data-global-search data-search-api="/api/search" data-search-tags="<?= \TinyCat\Support\Html::et('public.search_tags') ?>" data-search-users="<?= \TinyCat\Support\Html::et('public.search_users') ?>" data-search-content="<?= \TinyCat\Support\Html::et('public.search_content') ?>" data-search-all="<?= \TinyCat\Support\Html::et('public.search_all') ?>" data-search-empty="<?= \TinyCat\Support\Html::et('public.search_empty') ?>" data-search-min="<?= \TinyCat\Support\Html::et('public.search_min') ?>" data-search-captcha-title="<?= \TinyCat\Support\Html::et('public.search_captcha_title') ?>" data-search-captcha-required="<?= \TinyCat\Support\Html::et('public.search_captcha_required') ?>" data-search-captcha-submit="<?= \TinyCat\Support\Html::et('common.confirm') ?>" autocomplete="off">
                    <label class="sr-only" for="global-search-input"><?= \TinyCat\Support\Html::et('common.search') ?></label>
                    <div class="global-search-control">
                        <?= \TinyCat\Support\Html::icon('search') ?>
                        <input class="global-search-input" id="global-search-input" type="search" name="q" value="<?= \TinyCat\Support\Html::e($searchQuery) ?>" placeholder="<?= \TinyCat\Support\Html::et('public.search_suggest_placeholder') ?>" minlength="2" maxlength="80" data-global-search-input>
                    </div>
                    <div class="global-search-results" data-global-search-results hidden></div>
                </form>
                <nav class="nav-links" aria-label="<?= \TinyCat\Support\Html::et('common.main_navigation') ?>">
                    <?php foreach ((array) $nav as $item): ?>
                        <a class="nav-link nav-link-icon" href="<?= \TinyCat\Support\Html::e($item['href']) ?>"<?= (($item['target'] ?? '') === '_blank') ? ' target="_blank" rel="noopener"' : '' ?><?= $item['active'] ? ' aria-current="page"' : '' ?> aria-label="<?= \TinyCat\Support\Html::e((string) ($item['label'] ?? $item['href'])) ?>" title="<?= \TinyCat\Support\Html::e((string) ($item['label'] ?? $item['href'])) ?>">
                            <?= \TinyCat\Support\Html::icon((string) ($item['icon'] ?? 'link')) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($authUser !== null): ?>
                        <?php if ($viewerIsAdmin): ?>
                            <a class="nav-link nav-link-icon" href="/admin" aria-label="<?= \TinyCat\Support\Html::et('common.admin') ?>" title="<?= \TinyCat\Support\Html::et('common.admin') ?>">
                                <?= \TinyCat\Support\Html::icon('dashboard') ?>
                            </a>
                        <?php endif; ?>
                        <?= \TinyCat\Support\View::part('Notifications/menu', [
                            'current' => $current,
                            'state' => $notificationState,
                            'notifications' => $notificationItems,
                        ]) ?>
                        <a class="nav-link nav-link-icon profile-nav-link" href="<?= \TinyCat\Support\Html::e($profileUrl) ?>"<?= $current === $profileUrl ? ' aria-current="page"' : '' ?> aria-label="<?= \TinyCat\Support\Html::e($profileNeedsEmail ? \TinyCat\Support\Localization::t('account.email_missing_title') : \TinyCat\Support\Localization::t('account.public_profile')) ?>" title="<?= \TinyCat\Support\Html::e($profileNeedsEmail ? \TinyCat\Support\Localization::t('account.email_missing_title') : \TinyCat\Support\Localization::t('account.public_profile')) ?>">
                            <?= \TinyCat\Support\Html::icon('user') ?>
                            <?php if ($profileNeedsEmail): ?>
                                <span class="notification-badge profile-email-warning" aria-hidden="true">!</span>
                                <span class="sr-only"><?= \TinyCat\Support\Html::et('account.email_missing_title') ?></span>
                            <?php endif; ?>
                        </a>
                        <form action="/logout" method="post" class="inline-flex" data-confirm="<?= \TinyCat\Support\Html::et('auth.logout_confirm') ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('auth.logout_title') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('common.logout') ?>" data-confirm-cancel="<?= \TinyCat\Support\Html::et('common.cancel') ?>" data-confirm-variant="danger">
                            <?= \TinyCat\Security\Csrf::field() ?>
                            <button class="nav-link nav-link-icon" type="submit" aria-label="<?= \TinyCat\Support\Html::et('common.logout') ?>" title="<?= \TinyCat\Support\Html::et('common.logout') ?>">
                                <?= \TinyCat\Support\Html::icon('logout') ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <a class="nav-link nav-link-icon" href="/login"<?= $current === '/login' ? ' aria-current="page"' : '' ?> data-modal-open="<?= \TinyCat\Support\Html::e($loginModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($loginModalUrl) ?>" aria-label="<?= \TinyCat\Support\Html::et('common.login') ?>" title="<?= \TinyCat\Support\Html::et('common.login') ?>">
                            <?= \TinyCat\Support\Html::icon('user') ?>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <main class="section" id="main-content" tabindex="-1">
            <div class="container stack stack-gap-24">
                <?= $content ?>
            </div>
        </main>

        <?php if ($analyticsConfigured && !in_array($analyticsConsent, ['granted', 'denied'], true)): ?>
        <aside class="cookie-consent" data-cookie-consent data-google-measurement-id="<?= \TinyCat\Support\Html::e($googleMeasurementId) ?>" aria-labelledby="cookie-consent-title">
            <div class="cookie-consent-copy">
                <strong id="cookie-consent-title"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_title') ?></strong>
                <span><?= \TinyCat\Support\Html::et($cookieConsentTextKey) ?></span>
                <a href="/privacy" rel="privacy-policy"><?= \TinyCat\Support\Html::et('privacy.title') ?></a>
            </div>
            <div class="cookie-consent-actions">
                <button class="btn btn-primary btn-sm" type="button" data-cookie-consent-choice="granted"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_accept') ?></button>
                <button class="btn btn-secondary btn-sm" type="button" data-cookie-consent-choice="denied"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_necessary') ?></button>
            </div>
        </aside>
    <?php endif;
