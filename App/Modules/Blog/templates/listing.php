<?php
declare(strict_types=1);
/** @var list<array<mixed>> $articles */
/** @var int $next */
/** @var string $at */
/** @var bool $public */
/** @var string $path */
/** @var string $title */
use TinyCat\Support\Components;
use TinyCat\Support\Dates;
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
?>
<?php if ($public): ?><section class="blog-page section stack stack-gap-16"><header><h1><?= Html::e($title) ?></h1></header><?php endif; ?>
<?php if ($articles === []): ?><?= Components::render('ui/empty-state', ['label' => Localization::t('blog.empty'), 'icon' => 'file-text']) ?><?php endif; ?>
<?php foreach ($articles as $article): ?>
<article class="card blog-article"><div class="card-body stack stack-gap-16">
    <header class="stack"><p class="text-muted m-0"><?= Html::e($article['author']) ?> · <time datetime="<?= Html::e(Dates::dateIso($article['published_at'])) ?>"><?= Html::e(Dates::displayDateTime($article['published_at'])) ?></time></p><h2 class="m-0"><a href="/articles/<?= (int) $article['id'] ?>"><?= Html::e($article['title']) ?></a></h2></header>
    <p class="blog-excerpt m-0"><?= Html::e($article['excerpt']) ?></p>
    <a href="/articles/<?= (int) $article['id'] ?>"><?= Html::et('blog.view') ?><span class="sr-only">: <?= Html::e($article['title']) ?> #<?= (int) $article['id'] ?></span></a>
</div></article>
<?php endforeach; ?>
<?php if ($next > 0): ?><nav class="section" aria-label="<?= Html::et('common.pagination') ?>"><a class="btn btn-secondary" href="<?= Html::e($path . '?before=' . $next . '&at=' . rawurlencode($at)) ?>"><?= Html::et('blog.older') ?></a></nav><?php endif; ?>
<?php if ($public): ?></section><?php endif; ?>
