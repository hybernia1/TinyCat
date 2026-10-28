<?php
declare(strict_types=1);
?>
<form method="post" action="/api/scheduler/tokens" data-ajax-form data-confirm="<?= \TinyCat\Support\Html::et('cron.rotate_confirm') ?>">
            <?= \TinyCat\Security\Csrf::field() ?>
            <button class="btn btn-secondary btn-sm" type="submit"><?= \TinyCat\Support\Html::icon('refresh') ?> <span><?= \TinyCat\Support\Html::et('cron.rotate') ?></span></button>
        </form>
