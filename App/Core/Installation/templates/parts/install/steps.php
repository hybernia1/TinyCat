<?php
declare(strict_types=1);

use TinyCat\Support\Html;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$current = (string) ($current ?? 'language');
$steps = [
    'language' => 'install.step_language',
    'db' => 'install.step_database',
    'tables' => 'install.step_tables',
    'admin' => 'install.step_admin',
    'done' => 'install.step_done',
];
$currentIndex = array_search($current, array_keys($steps), true);
$currentIndex = $currentIndex === false ? 0 : $currentIndex;
?>
<ol class="result-list" aria-label="<?= Html::et('install.title') ?>">
    <?php $index = 0; ?>
    <?php foreach ($steps as $step => $label): ?>
        <?php
        $active = $step === $current;
        $complete = $index < $currentIndex;
        ?>
        <li class="result-item"<?= $active ? ' aria-current="step"' : '' ?>>
            <span class="badge<?= $active || $complete ? ' badge-primary' : '' ?>">
                <?php if ($complete): ?><?= Html::icon('check') ?><?php endif; ?>
                <?= Html::e($index + 1) ?>
            </span>
            <?php if ($active): ?>
                <strong class="text-primary"><?= Html::et($label) ?></strong>
            <?php else: ?>
                <span<?= $complete ? '' : ' class="text-muted"' ?>><?= Html::et($label) ?></span>
            <?php endif; ?>
        </li>
        <?php $index++; ?>
    <?php endforeach; ?>
</ol>
