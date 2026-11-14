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
?>
        <?php if ($analyticsConfigured && !in_array($analyticsConsent, ['granted', 'denied'], true)): ?>
        <aside class="cookie-consent" data-cookie-consent data-google-measurement-id="<?= \TinyCat\Support\Html::e($googleMeasurementId) ?>" aria-labelledby="cookie-consent-title">
            <div class="cookie-consent-copy">
                <strong id="cookie-consent-title"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_title') ?></strong>
                <span><?= \TinyCat\Support\Html::et($cookieConsentTextKey) ?></span>
                <a href="<?= \TinyCat\Support\Html::e($pageUrls['/privacy']) ?>" rel="privacy-policy"><?= \TinyCat\Support\Html::et('privacy.title') ?></a>
            </div>
            <div class="cookie-consent-actions">
                <button class="btn btn-primary btn-sm" type="button" data-cookie-consent-choice="granted"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_accept') ?></button>
                <button class="btn btn-secondary btn-sm" type="button" data-cookie-consent-choice="denied"><?= \TinyCat\Support\Html::et('privacy.cookie_consent_necessary') ?></button>
            </div>
        </aside>
    <?php endif;
