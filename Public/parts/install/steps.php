<?php
declare(strict_types=1);

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
<ol class="steps" aria-label="<?= et('install.title') ?>">
    <?php $index = 0; ?>
    <?php foreach ($steps as $step => $label): ?>
        <?php
        $class = 'step';
        $class .= $step === $current ? ' is-active' : '';
        $class .= $index < $currentIndex ? ' is-complete' : '';
        ?>
        <li class="<?= e($class) ?>">
            <span class="step-marker"><?= e($index + 1) ?></span>
            <span><?= et($label) ?></span>
        </li>
        <?php $index++; ?>
    <?php endforeach; ?>
</ol>
