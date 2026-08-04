<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$titleKey = (string) ($title_key ?? '');
$iconName = (string) ($icon_name ?? 'info');
$paragraphKeys = is_array($paragraph_keys ?? null) ? $paragraph_keys : [];
?>
<article class="card">
    <div class="card-header">
        <h2 class="text-lg m-0 cluster gap-2"><?= icon($iconName) ?> <?= et($titleKey) ?></h2>
    </div>
    <div class="card-body stack">
        <?php foreach ($paragraphKeys as $key): ?>
            <p class="mb-0"><?= et((string) $key) ?></p>
        <?php endforeach; ?>
    </div>
</article>
