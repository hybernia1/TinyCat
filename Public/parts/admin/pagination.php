<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$view = is_array($view ?? null) ? $view : [];
$target = (string) ($target ?? '');
$total = max(0, (int) ($view['total'] ?? 0));
$from = max(0, (int) ($view['from'] ?? 0));
$to = max(0, (int) ($view['to'] ?? 0));
$pages = is_array($view['pages'] ?? null) ? $view['pages'] : [];
$previousPage = is_array($view['previous'] ?? null) ? $view['previous'] : null;
$nextPage = is_array($view['next'] ?? null) ? $view['next'] : null;

if (!(bool) ($view['visible'] ?? false)) {
    return '';
}
?>
<nav class="pagination" aria-label="<?= et('common.pagination') ?>">
    <div class="pagination-summary"><?= et('common.pagination_summary', ['from' => (string) $from, 'to' => (string) $to, 'total' => (string) $total]) ?></div>
    <div class="pagination-list">
        <?php if ($previousPage === null): ?>
            <span class="pagination-link pagination-prev" aria-disabled="true"><?= et('common.previous') ?></span>
        <?php else: ?>
            <a class="pagination-link pagination-prev" href="<?= e((string) $previousPage['url']) ?>" data-ajax data-ajax-target="<?= e($target) ?>" data-history="<?= e((string) $previousPage['history_url']) ?>"><?= et('common.previous') ?></a>
        <?php endif; ?>

        <?php foreach ($pages as $pageData): ?>
            <?php $pageNumber = (int) ($pageData['number'] ?? 0); ?>
            <?php if ((bool) ($pageData['gap_before'] ?? false)): ?>
                <span class="pagination-ellipsis" aria-hidden="true">...</span>
            <?php endif; ?>
            <?php if ((bool) ($pageData['current'] ?? false)): ?>
                <span class="pagination-link is-active" aria-current="page"><?= e($pageNumber) ?></span>
            <?php else: ?>
                <a class="pagination-link" href="<?= e((string) ($pageData['url'] ?? '')) ?>" data-ajax data-ajax-target="<?= e($target) ?>" data-history="<?= e((string) ($pageData['history_url'] ?? '')) ?>"><?= e($pageNumber) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($nextPage === null): ?>
            <span class="pagination-link pagination-next" aria-disabled="true"><?= et('common.next') ?></span>
        <?php else: ?>
            <a class="pagination-link pagination-next" href="<?= e((string) $nextPage['url']) ?>" data-ajax data-ajax-target="<?= e($target) ?>" data-history="<?= e((string) $nextPage['history_url']) ?>"><?= et('common.next') ?></a>
        <?php endif; ?>
    </div>
</nav>
