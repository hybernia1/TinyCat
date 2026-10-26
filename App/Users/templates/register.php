<?php
declare(strict_types=1);
/** @var string $next */
/** @var mixed $old */
?>
<section class="max-w-auth mx-auto">
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('user-plus') ?> <?= \TinyCat\Support\Html::et('auth.register_title') ?></h1>
        </div>
        <div class="card-body">
            <?= \TinyCat\Users\Views::authContent(['mode' => 'register', 'next' => $next, 'old' => $old]) ?>
        </div>
    </article>
</section>
