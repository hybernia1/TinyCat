<?php
declare(strict_types=1);
/** @var string $notFoundTitle */
/** @var string $notFoundMessage */
?>
<section class="max-w-auth mx-auto" aria-labelledby="not-found-title">
    <article class="card">
        <div class="card-body stack stack-gap-16">
            <p class="text-2xl text-primary m-0" aria-hidden="true">404</p>
            <div class="stack stack-gap-8">
                <h1 class="text-2xl m-0" id="not-found-title"><?= \TinyCat\Support\Html::icon('alert') ?> <?= \TinyCat\Support\Html::e($notFoundTitle) ?></h1>
                <p class="text-muted m-0"><?= \TinyCat\Support\Html::e($notFoundMessage) ?></p>
            </div>
            <div class="btn-group">
                <a class="btn btn-primary" href="/"><?= \TinyCat\Support\Html::icon('home') ?> <span><?= \TinyCat\Support\Html::et('public.not_found_home') ?></span></a>
                <a class="btn btn-secondary" href="/search"><?= \TinyCat\Support\Html::icon('search') ?> <span><?= \TinyCat\Support\Html::et('public.not_found_search') ?></span></a>
            </div>
        </div>
    </article>
</section>
