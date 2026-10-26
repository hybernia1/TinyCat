<?php
declare(strict_types=1);

use TinyCat\Security\Csrf;
use TinyCat\Support\Html;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$authorId = (int) ($authorId ?? 0);
$isFollowing = (bool) ($isFollowing ?? false);
$prepared = is_array($prepared ?? null) ? $prepared : [];
?>
<form method="post" action="<?= Html::e($prepared['author_api_url']) ?>" data-follow-form data-author-id="<?= Html::e($authorId) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>">
    <button class="btn <?= $isFollowing ? 'btn-secondary' : 'btn-primary' ?> btn-sm" type="submit">
        <?= Html::icon($isFollowing ? 'check' : 'plus') ?> <span><?= Html::et($isFollowing ? 'public.unfollow' : 'public.follow') ?></span>
    </button>
</form>
