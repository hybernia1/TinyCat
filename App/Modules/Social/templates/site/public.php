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
<?= \TinyCat\Support\View::part('Ui/public-header', get_defined_vars()) ?>
        <main class="section" id="main-content" tabindex="-1">
            <div class="container stack stack-gap-24">
                <?= $content ?>
            </div>
        </main>

<?= \TinyCat\Support\View::part('Ui/consent', get_defined_vars()) ?>
