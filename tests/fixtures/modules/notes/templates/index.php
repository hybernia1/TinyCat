<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title><?= \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t('pilot_notes.title')) ?></title>
<?php foreach ($styles as $url): ?><link rel="stylesheet" href="<?= \TinyCat\Support\Html::e($url) ?>"><?php endforeach; ?>
</head><body><?= \TinyCat\Support\Components::render('pilot-notes/list', ['items' => $items]) ?>
<?php foreach ($scripts as $url): ?><script src="<?= \TinyCat\Support\Html::e($url) ?>" defer></script><?php endforeach; ?>
</body></html>
