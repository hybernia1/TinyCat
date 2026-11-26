<?php
declare(strict_types=1);

namespace TinyCat\Core\Ui\Admin;

use TinyCat\Core\Ui\{Components, Markup};
use TinyCat\Support\Localization;

final class Views
{
    public static function listCard(string $title, string $icon, string $targetId, string $filterModal, Markup $content, bool $primary = false): string
    {
        return Components::render('ui/admin-list', [
            'title' => $title, 'icon' => $icon, 'target_id' => $targetId,
            'filter_modal' => $filterModal, 'content' => $content, 'primary' => $primary,
        ]);
    }

    public static function modalAction(string $modal, string $label, string $icon, string $url): string
    {
        if (!str_starts_with($url, '/api/')) { throw new \InvalidArgumentException('Resource dialog actions require an API URL.'); }
        return Components::render('ui/modal-trigger', [
            'class' => 'btn btn-primary btn-sm', 'modal' => $modal, 'label' => $label, 'icon' => $icon, 'url' => $url,
        ]);
    }

    public static function admin_filter_text(string $value, int $limit = 120): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        $limit = max(1, $limit);

        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    public const array PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public static function admin_per_page(?int $value = null): int
    {
        $options = self::PER_PAGE_OPTIONS;
        $default = 25;
        $value ??= (int) \TinyCat\Http\Web::get('per_page', $default);
        $value = max(1, min(200, $value));

        return in_array($value, $options, true) ? $value : $default;
    }

    public static function admin_page(string $name = 'page'): int
    {
        return max(1, (int) \TinyCat\Http\Web::get($name, 1));
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public static function admin_list_query(array $params = []): array
    {
        $query = [];

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $query[(string) $key] = $value;
        }

        return $query;
    }

    /**
     * @param array<mixed> $params
     */
    public static function admin_list_url(string $path, array $params = []): string
    {
        $query = self::admin_list_query($params);

        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * @param array<mixed> $pagination
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public static function admin_pagination_view_data(
        array $pagination,
        string $path,
        string $target,
        array $params = [],
        string $pageName = 'page',
        int $window = 2,
        string $historyPath = ''
    ): array {
        $page = max(1, (int) ($pagination['page'] ?? 1));
        $lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
        $window = max(1, $window);
        $historyPath = $historyPath !== '' ? $historyPath : $path;
        $pageNumbers = [1, $lastPage];

        for ($number = max(1, $page - $window); $number <= min($lastPage, $page + $window); $number++) {
            $pageNumbers[] = $number;
        }

        $pageNumbers = array_values(array_unique($pageNumbers));
        sort($pageNumbers);
        $url = static function (int $targetPage, bool $ajax) use ($path, $historyPath, $params, $pageName): string {
            $query = $params;
            $query[$pageName] = $targetPage;

            return self::admin_list_url($ajax ? $path : $historyPath, $query);
        };
        $pages = [];
        $previous = null;

        foreach ($pageNumbers as $number) {
            $pages[] = [
                'number' => $number,
                'current' => $number === $page,
                'gap_before' => $previous !== null && $number > $previous + 1,
                'url' => $url($number, true),
                'history_url' => $url($number, false),
            ];
            $previous = $number;
        }

        return [
            'visible' => $lastPage > 1,
            'target' => $target,
            'page' => $page,
            'last_page' => $lastPage,
            'total' => max(0, (int) ($pagination['total'] ?? 0)),
            'from' => max(0, (int) ($pagination['from'] ?? 0)),
            'to' => max(0, (int) ($pagination['to'] ?? 0)),
            'previous' => $page > 1 ? [
                'url' => $url($page - 1, true),
                'history_url' => $url($page - 1, false),
            ] : null,
            'next' => $page < $lastPage ? [
                'url' => $url($page + 1, true),
                'history_url' => $url($page + 1, false),
            ] : null,
            'pages' => $pages,
        ];
    }
    /**
     * @return array<mixed>
     */
    public static function paginationMeta(int $total, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $total = max(0, $total);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'offset' => $offset,
            'last_page' => $lastPage,
            'from' => $total === 0 ? 0 : $offset + 1,
            'to' => $total === 0 ? 0 : min($total, $offset + $perPage),
            'has_prev' => $page > 1,
            'has_next' => $page < $lastPage,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ];
    }

    /** @param array{string, string, string, array<string, string>, string, string} $config
     * @param array<string, mixed>|null $input
     * @return array{sort: string, direction: string}
     */
    public static function sortState(array $config, ?array $input = null): array
    {
        $sort = $input['sort'] ?? \TinyCat\Http\Web::get('sort', '');
        $direction = $input['direction'] ?? \TinyCat\Http\Web::get('direction', 'asc');
        return ['sort' => is_string($sort) && isset($config[3][$sort]) ? $sort : '',
            'direction' => $direction === 'desc' ? 'desc' : 'asc'];
    }

    /** @param array{string, string, string, array<string, string>, string, string} $config
     * @param array<string, mixed>|null $input */
    public static function sortOrder(array $config, ?array $input = null): string
    {
        $state = self::sortState($config, $input);
        return $state['sort'] === '' ? $config[4] : $config[3][$state['sort']] . ' ' . strtoupper($state['direction']) . ', ' . $config[5] . ' DESC';
    }

    /** @param array{string, string, string, array<string, string>, string, string} $config
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function sortHeadings(array $config, array $params = [], string $apiPath = '', string $historyPath = ''): array
    {
        $state = self::sortState($config, $params);
        $headings = [];
        foreach ($config[3] as $key => $_expression) {
            $active = $state['sort'] === $key;
            $next = array_replace($params, ['sort' => $key, 'direction' => $active && $state['direction'] === 'asc' ? 'desc' : 'asc', 'page' => 1]);
            $headings[$key] = ['url' => self::admin_list_url($apiPath !== '' ? $apiPath : $config[0], $next),
                'history' => self::admin_list_url($historyPath !== '' ? $historyPath : $config[1], $next), 'target' => $config[2],
                'aria' => $active ? ($state['direction'] === 'asc' ? 'ascending' : 'descending') : 'none',
                'ascending' => $active && $state['direction'] === 'asc', 'descending' => $active && $state['direction'] === 'desc'];
        }
        return $headings;
    }
}
