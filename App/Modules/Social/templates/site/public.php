<?php
declare(strict_types=1);
/** @var string $content */
/** @var string $publicHeader */
/** @var string $consent */
?>
<?= $publicHeader ?>
        <main class="section" id="main-content" tabindex="-1">
            <div class="container stack stack-gap-24">
                <?= $content ?>
            </div>
        </main>

<?= $consent ?>
