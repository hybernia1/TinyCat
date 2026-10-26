<?php
declare(strict_types=1);

namespace TinyCat\Support;

final class AdminViews
{
    public static function listCard(string $title, string $icon, string $targetId, string $filterModal, Markup $content): string
    {
        return Components::render('ui/admin-list', [
            'title' => $title, 'icon' => $icon, 'target_id' => $targetId,
            'filter_modal' => $filterModal, 'content' => $content,
        ]);
    }

    public static function modalAction(string $modal, string $label, string $icon): string
    {
        return Components::render('ui/modal-trigger', [
            'class' => 'btn btn-primary btn-sm', 'modal' => $modal, 'label' => $label, 'icon' => $icon,
        ]);
    }

    /**
     * @param array<mixed> $options
     */
    public static function tc_admin_options(array $options, ?string $selected = null): string
    {
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . \TinyCat\Support\Html::e($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . \TinyCat\Support\Html::e($label) . '</option>';
        }

        return $html;
    }

    public static function tc_admin_datetime(string $value): string
    {
        return $value === '' ? '' : \TinyCat\Support\Dates::displayDateTime($value);
    }

    public static function tc_admin_datetime_iso(string $value): string
    {
        return $value === '' ? '' : \TinyCat\Support\Dates::dateIso($value);
    }

    /**
     * @return array<mixed>
     */
    public static function admin_user_statuses(): array
    {
        return [
            'active' => \TinyCat\Support\Localization::t('users.statuses.active'),
            'waiting' => \TinyCat\Support\Localization::t('users.statuses.waiting'),
            'ban' => \TinyCat\Support\Localization::t('users.statuses.ban'),
        ];
    }

    public static function admin_search_like(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
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

    /**
     * @return array<mixed>
     */
    public static function admin_per_page_options(): array
    {
        $configured = [10, 25, 50, 100];
        $options = [];

        foreach ($configured as $option) {
            $value = max(1, min(200, (int) $option));
            $options[$value] = $value;
        }

        ksort($options);

        return array_values($options);
    }

    public static function admin_per_page(?int $value = null): int
    {
        $options = \TinyCat\Support\AdminViews::admin_per_page_options();
        $default = 25;
        $default = in_array($default, $options, true) ? $default : ($options[0] ?? 25);
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
        $query = \TinyCat\Support\AdminViews::admin_list_query($params);

        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public static function admin_per_page_view_data(
        string $path,
        string $target,
        array $params,
        int $selected,
        string $historyPath
    ): array {
        $params['page'] = 1;

        return [
            'path' => $path,
            'target' => $target,
            'params' => $params,
            'selected' => \TinyCat\Support\AdminViews::admin_per_page($selected),
            'history_path' => $historyPath,
            'options' => \TinyCat\Support\AdminViews::admin_per_page_options(),
        ];
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

            return \TinyCat\Support\AdminViews::admin_list_url($ajax ? $path : $historyPath, $query);
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
}
