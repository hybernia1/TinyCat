<?php
declare(strict_types=1);
/** @var array<mixed> $batch */
/** @var int $unread */
/** @var array<mixed> $viewItems */
?>
<div id="notifications-view">
    <?= \TinyCat\Support\View::part('Notifications/page', [
        'notifications' => $viewItems,
        'unread' => $unread,
        'next_url' => (string) $batch['next_url'],
    ]) ?>
</div>
