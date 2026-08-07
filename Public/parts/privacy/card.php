<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$titleKey = (string) ($title_key ?? '');
$iconName = (string) ($icon_name ?? 'info');
$paragraphKeys = is_array($paragraph_keys ?? null) ? $paragraph_keys : [];
$translationVars = is_array($translation_vars ?? null) ? $translation_vars : [];
$translationVars += ['site' => site_name()];
?>
<article class="card">
    <div class="card-header">
        <h2 class="text-lg m-0 cluster gap-2"><?= icon($iconName) ?> <?= et($titleKey, $translationVars) ?></h2>
    </div>
    <div class="card-body stack">
        <?php foreach ($paragraphKeys as $key): ?>
            <p class="mb-0"><?= et((string) $key, $translationVars) ?></p>
        <?php endforeach; ?>
    </div>
</article>
