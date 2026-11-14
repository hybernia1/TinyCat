<?php
declare(strict_types=1);
/** @var string $searchUrl */
/** @var array{query: string, collections: list<array<string, mixed>>} $result */
use TinyCat\Support\Html;
?>
<div class="container stack stack-gap-16">
    <form action="<?= Html::e($searchUrl) ?>" method="get" role="search" class="cluster gap-2">
        <label class="field"><?= Html::et('common.search') ?><input class="input" type="search" name="q" value="<?= Html::e($result['query']) ?>" maxlength="80" minlength="2" required></label>
        <button class="btn btn-primary" type="submit"><?= Html::et('common.search') ?></button>
    </form>
    <?php foreach ($result['collections'] as $group): ?>
    <section class="card"><div class="card-header"><h2><?= Html::e($group['name']) ?></h2></div><div class="card-body stack stack-gap-16">
        <?php foreach ($group['items'] as $item): ?>
        <article><h3><a href="<?= Html::e($item['url']) ?>"><?= Html::e($item['title']) ?></a></h3><p><?= Html::e($item['excerpt'] ?? '') ?></p></article>
        <?php endforeach; ?>
        <?php if ($group['items'] === []): ?><p><?= Html::et('common.search_empty') ?></p><?php endif; ?>
        <?php if ($group['next_url'] !== ''): ?><a class="btn btn-secondary" href="<?= Html::e($group['next_url']) ?>"><?= Html::et('common.load_more') ?></a><?php endif; ?>
    </div></section>
    <?php endforeach; ?>
</div>
