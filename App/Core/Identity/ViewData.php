<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

final class ViewData
{

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_view_data(): array
    {
        $filters = \TinyCat\Core\Identity\Actions::tc_admin_users_filters();
        $page = \TinyCat\Core\Identity\Actions::tc_admin_users_page($filters);
        $users = $page['items'];
        $pagination = $page['pagination'];
        $params = \TinyCat\Core\Identity\Actions::tc_admin_users_list_params($filters, $pagination);
        $perPage = (int) ($pagination['per_page'] ?? \TinyCat\Support\AdminViews::admin_per_page());

        return [
            'filters' => $filters,
            'users' => $users,
            'pagination' => $pagination,
            'params' => $params,
            'per_page' => $perPage,
            'search_url' => \TinyCat\Core\Identity\Actions::tc_admin_users_api_url([], false),
            'clear_url' => \TinyCat\Core\Identity\Actions::tc_admin_users_api_url(['per_page' => $perPage, 'page' => 1], false),
            'clear_history_url' => \TinyCat\Support\AdminViews::admin_list_url('/admin/users', ['per_page' => $perPage, 'page' => 1]),
            'per_page_view' => \TinyCat\Support\AdminViews::admin_per_page_view_data(
                '/api/users',
                '#users-list',
                $params,
                $perPage,
                '/admin/users'),
            'pagination_view' => \TinyCat\Support\AdminViews::admin_pagination_view_data(
                $pagination,
                '/api/users',
                '#users-list',
                $params,
                'page',
                2,
                '/admin/users'),
            'roles' => \TinyCat\Core\Identity\ReadModel::roles(),
            'statuses' => \TinyCat\Support\AdminViews::admin_user_statuses(),
            'has_filters' => \TinyCat\Core\Identity\ReadModel::tc_admin_users_active_filters($filters) !== [],
        ];
    }
}
