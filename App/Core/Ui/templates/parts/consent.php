<?php
declare(strict_types=1);
/** @var array<string, string> $pageUrls */
/** @var bool $analyticsConfigured */
/** @var string $analyticsConsent */
/** @var string $cookieConsentTextKey */
/** @var string $googleMeasurementId */
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
