<?php
declare(strict_types=1);
/** @var array<mixed> $article */
/** @var bool $canEdit */
/** @var string $content */
/** @var string $discussion */
use TinyCat\Support\Dates;
use TinyCat\Support\Html;
?>
<article class="blog-page section stack stack-gap-16">
    <header class="stack stack-gap-16"><h1 class="blog-title m-0"><?= Html::e($article['title']) ?></h1><p class="text-muted m-0"><?= Html::e($article['author']) ?> · <time datetime="<?= Html::e(Dates::dateIso($article['published_at'])) ?>"><?= Html::e(Dates::displayDateTime($article['published_at'])) ?></time></p></header>
    <?= $content ?>
    <?= $discussion ?>
    <?php if ($canEdit): ?><div class="cluster"><a class="btn btn-secondary" href="/admin/articles/<?= (int) $article['id'] ?>"><?= Html::icon('edit') ?> <?= Html::et('common.edit') ?></a></div><?php endif; ?>
</article>
