<?php
declare(strict_types=1);
/** @var mixed $error */
/** @var mixed $message */
/** @var string $next */
/** @var mixed $old */
?>
<section class="max-w-auth-sm mx-auto">
    <article class="card">
        <div class="card-header">
            <h1 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('login') ?> <?= \TinyCat\Support\Html::et('auth.login_title') ?></h1>
        </div>
        <div class="card-body">
            <?= \TinyCat\Users\Views::authContent(['mode' => 'login', 'next' => $next, 'old' => $old, 'error' => $error, 'message' => $message]) ?>
        </div>
    </article>
</section>
