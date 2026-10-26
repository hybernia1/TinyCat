<?php
declare(strict_types=1);
/** @var int $status */
/** @var string $title */
/** @var string $intro */
/** @var string $locale */
?>
<!doctype html>
<html lang="<?= \TinyCat\Support\Html::e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= \TinyCat\Support\Html::e($title) ?></title>
    <style>
        :root { color-scheme: light dark; font: 16px/1.6 system-ui, sans-serif; }
        body { margin: 0; padding: 24px; }
        main { max-width: 40rem; margin: 12vh auto; }
        h1 { font-size: 1.75rem; line-height: 1.3; }
        a { color: inherit; text-underline-offset: .2em; }
    </style>
</head>
<body>
    <main>
        <p><?= $status ?></p>
        <h1><?= \TinyCat\Support\Html::e($title) ?></h1>
        <p><?= \TinyCat\Support\Html::e($intro) ?></p>
        <a href="/">TinyCat</a>
    </main>
</body>
</html>
