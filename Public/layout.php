<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!isset($content)) {
    http_response_code(404);
    return;
}

$appName = (string) config('app.name', 'TinyCat');
$title = (string) ($title ?? $appName);
$current = route_path((string) ($current ?? route_path()));
$bodyClass = (string) ($bodyClass ?? '');
$csrfToken = (string) ($csrfToken ?? csrf_token());
$styles = $styles ?? ['css/tinycat.css'];
$scripts = $scripts ?? ['js/tinycat.js'];
$nav = $nav ?? [
    ['href' => '/admin/users', 'icon' => 'users', 'label' => 'Users'],
    ['href' => '/example.php', 'icon' => 'dashboard', 'label' => 'Example'],
    ['href' => '/api/ping', 'icon' => 'database', 'label' => 'API'],
];
$pageTitle = $title === $appName ? $title : $title . ' | ' . $appName;
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($csrfToken !== ''): ?>
        <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <?php endif; ?>
    <title><?= e($pageTitle) ?></title>
    <?php foreach ((array) $styles as $style): ?>
        <link rel="stylesheet" href="<?= e(asset((string) $style)) ?>">
    <?php endforeach; ?>
    <?php foreach ((array) $scripts as $script): ?>
        <script src="<?= e(asset((string) $script)) ?>" defer></script>
    <?php endforeach; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
    <header class="navbar">
        <div class="container navbar-inner">
            <strong><?= e($appName) ?></strong>
            <nav class="nav-links" aria-label="Main">
                <?php foreach ((array) $nav as $item): ?>
                    <?php
                    $href = (string) ($item['href'] ?? '#');
                    $active = route_path($href) === $current;
                    ?>
                    <a class="nav-link inline-flex items-center gap-2" href="<?= e($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                        <?= icon((string) ($item['icon'] ?? 'link')) ?>
                        <span><?= e((string) ($item['label'] ?? $href)) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="section">
        <div class="container stack" style="--stack-gap: 24px;">
            <?= $content ?>
        </div>
    </main>
</body>
</html>
