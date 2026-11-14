<?php
declare(strict_types=1);
/** @var string $siteName */
/** @var bool $siteCaptchaEnabled */
/** @var bool $siteGoogleAnalyticsConfigured */
/** @var string $sections */
?>
<section class="stack max-w-prose mx-auto">
    <article class="card">
        <div class="card-header">
            <h1 class="text-xl m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('shield') ?> <?= \TinyCat\Support\Html::et('privacy.title') ?></h1>
        </div>
        <div class="card-body stack">
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('privacy.intro', ['site' => $siteName]) ?></p>
        </div>
    </article>

<?= $sections ?>
<article class="card"><div class="card-header"><h2 class="text-lg m-0"><?= \TinyCat\Support\Html::et('privacy.data_title') ?></h2></div><div class="card-body stack">
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.data_account', ['site' => $siteName]) ?></p>
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.data_email', ['site' => $siteName]) ?></p>
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.data_technical', ['site' => $siteName]) ?></p>
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.data_server_logs', ['site' => $siteName]) ?></p>
</div></article>
<article class="card"><div class="card-header"><h2 class="text-lg m-0"><?= \TinyCat\Support\Html::et('privacy.recovery_title') ?></h2></div><div class="card-body stack">
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.recovery_email', ['site' => $siteName]) ?></p>
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.recovery_no_contact', ['site' => $siteName]) ?></p>
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.recovery_lost', ['site' => $siteName]) ?></p>
<p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.recovery_rotation', ['site' => $siteName]) ?></p>
</div></article>
    <article class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('info') ?> <?= \TinyCat\Support\Html::et('privacy.cookies_title') ?></h2>
        </div>
        <div class="card-body stack">
            <p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.cookies_session') ?></p>
            <?php if ($siteCaptchaEnabled): ?>
                <p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.cookies_captcha') ?></p>
            <?php endif; ?>
            <p class="mb-0"><?= \TinyCat\Support\Html::et('privacy.cookies_remember') ?></p>
            <p class="mb-0"><?= \TinyCat\Support\Html::et(
                $siteGoogleAnalyticsConfigured ? 'privacy.cookies_analytics' : 'privacy.cookies_no_analytics',
                ['site' => $siteName],
            ) ?></p>
        </div>
    </article>
</section>
