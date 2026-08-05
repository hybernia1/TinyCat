<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$pagination = is_array($pagination ?? null) ? $pagination : [];
$path = (string) ($path ?? '');
$target = (string) ($target ?? '');
$params = is_array($params ?? null) ? $params : [];
$pageName = (string) ($page_name ?? 'page');
$window = max(1, (int) ($window ?? 2));
$historyPath = (string) ($history_path ?? $path);
$page = max(1, (int) ($pagination['page'] ?? 1));
$lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
$total = max(0, (int) ($pagination['total'] ?? 0));
$from = max(0, (int) ($pagination['from'] ?? 0));
$to = max(0, (int) ($pagination['to'] ?? 0));

if ($lastPage <= 1) {
    return '';
}

$pages = [1, $lastPage];

for ($pageNumber = max(1, $page - $window); $pageNumber <= min($lastPage, $page + $window); $pageNumber++) {
    $pages[] = $pageNumber;
}

$pages = array_values(array_unique($pages));
sort($pages);

$url = static function (int $targetPage, bool $ajax) use ($path, $historyPath, $params, $pageName): string {
    $query = $params;
    $query[$pageName] = $targetPage;

    return admin_list_url($ajax ? $path : $historyPath, $query, $ajax);
};
?>
<nav class="pagination" aria-label="<?= et('common.pagination') ?>">
    <div class="pagination-summary"><?= et('common.pagination_summary', ['from' => (string) $from, 'to' => (string) $to, 'total' => (string) $total]) ?></div>
    <div class="pagination-list">
        <?php if ($page <= 1): ?>
            <span class="pagination-link pagination-prev" aria-disabled="true"><?= et('common.previous') ?></span>
        <?php else: ?>
            <a class="pagination-link pagination-prev" href="<?= e($url($page - 1, true)) ?>" data-ajax data-ajax-target="<?= e($target) ?>" data-history="<?= e($url($page - 1, false)) ?>"><?= et('common.previous') ?></a>
        <?php endif; ?>

        <?php $previous = null; ?>
        <?php foreach ($pages as $pageNumber): ?>
            <?php if ($previous !== null && $pageNumber > $previous + 1): ?>
                <span class="pagination-ellipsis" aria-hidden="true">...</span>
            <?php endif; ?>
            <?php if ($pageNumber === $page): ?>
                <span class="pagination-link is-active" aria-current="page"><?= e($pageNumber) ?></span>
            <?php else: ?>
                <a class="pagination-link" href="<?= e($url($pageNumber, true)) ?>" data-ajax data-ajax-target="<?= e($target) ?>" data-history="<?= e($url($pageNumber, false)) ?>"><?= e($pageNumber) ?></a>
            <?php endif; ?>
            <?php $previous = $pageNumber; ?>
        <?php endforeach; ?>

        <?php if ($page >= $lastPage): ?>
            <span class="pagination-link pagination-next" aria-disabled="true"><?= et('common.next') ?></span>
        <?php else: ?>
            <a class="pagination-link pagination-next" href="<?= e($url($page + 1, true)) ?>" data-ajax data-ajax-target="<?= e($target) ?>" data-history="<?= e($url($page + 1, false)) ?>"><?= et('common.next') ?></a>
        <?php endif; ?>
    </div>
</nav>
