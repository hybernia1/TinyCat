<?php
declare(strict_types=1);
/** @var array<string, string> $pageUrls */
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
/** @var string $currentLanguageName */
/** @var string $languageFlagSprite */
/** @var string $languageFlagId */
/** @var list<array{language: string, name: string, url: string, active: bool}> $languageLinks */
/** @var array{language: string, url: string, message: string, action: string, close: string}|null $languageSuggestion */
/** @var string $userMenu */
/** @var bool $hasGlobalSearch */
?>
        <header class="navbar">
            <div class="container navbar-inner">
                <a class="navbar-brand" href="<?= \TinyCat\Support\Html::e($pageUrls['/']) ?>">
                    <?php if ($siteLogoUrl !== ''): ?>
                        <img class="brand-logo" src="<?= \TinyCat\Support\Html::e($siteLogoUrl) ?>" alt="<?= \TinyCat\Support\Html::et('common.site_logo_alt', ['site' => $appName]) ?>">
                    <?php endif; ?>
                    <strong<?= $siteLogoUrl !== '' ? ' aria-hidden="true"' : '' ?>><?= \TinyCat\Support\Html::e($appName) ?></strong>
                </a>
                <?php if ($hasGlobalSearch): ?>
                <form class="global-search" action="<?= \TinyCat\Support\Html::e($pageUrls['/search/all']) ?>" method="get" role="search" data-global-search data-search-api="/api/search" data-search-all="<?= \TinyCat\Support\Html::et('public.search_all') ?>" data-search-empty="<?= \TinyCat\Support\Html::et('public.search_empty') ?>" data-search-min="<?= \TinyCat\Support\Html::et('public.search_min') ?>" data-search-captcha-title="<?= \TinyCat\Support\Html::et('public.search_captcha_title') ?>" data-search-captcha-required="<?= \TinyCat\Support\Html::et('public.search_captcha_required') ?>" data-search-captcha-submit="<?= \TinyCat\Support\Html::et('common.confirm') ?>" autocomplete="off">
                    <label class="sr-only" for="global-search-input"><?= \TinyCat\Support\Html::et('common.search') ?></label>
                    <div class="global-search-control" data-popover data-popover-mode="input" data-popover-match-width="true">
                        <?= \TinyCat\Support\Html::icon('search') ?>
                        <input class="input global-search-input" id="global-search-input" type="search" name="q" value="<?= \TinyCat\Support\Html::e($searchQuery) ?>" placeholder="<?= \TinyCat\Support\Html::et('public.search_suggest_placeholder') ?>" minlength="2" maxlength="80" data-global-search-input data-popover-trigger>
                        <div class="popover-panel global-search-results" data-global-search-results hidden></div>
                    </div>
                </form>
                <?php endif; ?>
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
                        <?= $userMenu ?>
                    <?php else: ?>
                        <details class="context-menu language-menu" data-language-menu data-popover>
                            <summary class="nav-link nav-link-icon" aria-label="<?= \TinyCat\Support\Html::et('common.language') ?>: <?= \TinyCat\Support\Html::e($currentLanguageName) ?>" title="<?= \TinyCat\Support\Html::e($currentLanguageName) ?>">
                                <svg class="language-flag" viewBox="0 0 24 16" aria-hidden="true" focusable="false"><use href="<?= \TinyCat\Support\Html::e($languageFlagSprite . '#' . $languageFlagId) ?>"></use></svg>
                            </summary>
                            <nav class="popover-panel language-menu-list" aria-label="<?= \TinyCat\Support\Html::et('common.language') ?>">
                                <?php foreach ($languageLinks as $languageLink): ?>
                                    <a class="context-menu-action language-menu-link" href="<?= \TinyCat\Support\Html::e($languageLink['url']) ?>" lang="<?= \TinyCat\Support\Html::e($languageLink['language']) ?>" data-popover-close data-language-link="<?= \TinyCat\Support\Html::e($languageLink['language']) ?>"<?= $languageLink['active'] ? ' aria-current="true"' : '' ?>>
                                        <svg class="language-flag" viewBox="0 0 24 16" aria-hidden="true" focusable="false"><use href="<?= \TinyCat\Support\Html::e($languageFlagSprite . '#flag-' . $languageLink['language']) ?>"></use></svg>
                                        <span><?= \TinyCat\Support\Html::e($languageLink['name']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </nav>
                        </details>
                        <a class="nav-link nav-link-icon" href="<?= \TinyCat\Support\Html::e($pageUrls['/login']) ?>"<?= $current === '/login' ? ' aria-current="page"' : '' ?> data-modal-open="<?= \TinyCat\Support\Html::e($loginModalId) ?>" data-modal-url="<?= \TinyCat\Support\Html::e($loginModalUrl) ?>" aria-label="<?= \TinyCat\Support\Html::et('common.login') ?>" title="<?= \TinyCat\Support\Html::et('common.login') ?>">
                            <?= \TinyCat\Support\Html::icon('user') ?>
                        </a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <?php if ($languageSuggestion !== null): ?>
            <aside class="language-suggestion" data-language-suggestion="<?= \TinyCat\Support\Html::e($languageSuggestion['language']) ?>" lang="<?= \TinyCat\Support\Html::e($languageSuggestion['language']) ?>">
                <div class="container language-suggestion-inner">
                    <span><?= \TinyCat\Support\Html::e($languageSuggestion['message']) ?></span>
                    <a class="btn btn-secondary btn-sm" href="<?= \TinyCat\Support\Html::e($languageSuggestion['url']) ?>" data-popover-close data-language-link="<?= \TinyCat\Support\Html::e($languageSuggestion['language']) ?>"><?= \TinyCat\Support\Html::e($languageSuggestion['action']) ?></a>
                    <button class="language-suggestion-close nav-link nav-link-icon" type="button" data-language-suggestion-close aria-label="<?= \TinyCat\Support\Html::e($languageSuggestion['close']) ?>"><?= \TinyCat\Support\Html::icon('close') ?></button>
                </div>
            </aside>
        <?php endif; ?>
