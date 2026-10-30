<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use TinyCat\Support\Components;

use TinyCat\Support\AdminViews;
use TinyCat\Support\View;
use TinyCat\Users\Usernames;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{
    /** @param array<mixed> $data */
    public static function accountCreate(array $data = []): string
    {

        $prepared = [];
        $prepared['accounts_url'] = Administration::accountsApiUrl();
        $prepared['username_hint'] = Usernames::username_hint();
        $prepared['username_pattern'] = Usernames::username_pattern();
        return View::part('Bots/modals/account-create', array_replace($data, compact('prepared')));
    }

    /** @param array<mixed> $data */
    public static function accountFilter(array $data = []): string
    {
        $filters = $data['filters'] ?? null;
        $filters = is_array($filters ?? null) ? $filters : [];
        $prepared = [];
        $prepared['accounts_url'] = Administration::accountsApiUrl();
        $prepared['admin_user_statuses'] = AdminViews::admin_user_statuses();
        return View::part('Bots/modals/account-filter', array_replace($data, compact('filters', 'prepared')));
    }

    /** @param array<mixed> $data */
    public static function accounts(array $data = []): string
    {
        $model = Resource::accounts(['items' => array_map(Administration::accountResource(...), (array) ($data['accounts'] ?? [])), 'filters' => (array) ($data['filters'] ?? []), 'statuses' => AdminViews::admin_user_statuses(), 'pagination' => (array) ($data['pagination'] ?? [])]);
        return Components::render('bots/accounts', $model);
    }

    /** @param array<mixed> $data */
    public static function sources(array $data = []): string
    {
        $model = ['items' => array_map(Importer::sourceResource(...), (array) ($data['sources'] ?? [])), 'bots' => (array) ($data['bots'] ?? []), 'filter_bot_user_id' => (int) ($data['filter_bot_id'] ?? 0)];
        $model['sorting'] = \TinyCat\Support\AdminSorting::headings('sources', ['bot' => $model['filter_bot_user_id'] ?: '']);
        $model = array_replace($model, array_intersect_key($data, array_flip(['filters', 'pagination', 'sorting', 'navigation'])));
        return Components::render('bots/sources', $model);
    }
}
