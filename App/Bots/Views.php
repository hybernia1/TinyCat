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
    /** @param array<mixed> $runs */
    public static function runs(array $runs, bool $latest = false): string
    {
        return Components::render('bots/runs', ['runs' => Resource::runs($runs), 'latest' => $latest]);
    }

    /** @param array<mixed> $data */
    public static function sourceFields(array $data): string
    {
        $source = (array) ($data['source'] ?? []);
        $source += ['post_template' => Importer::defaultSourceTemplate(), 'enabled' => true];
        return Components::render('bots/source-fields', ['source' => $source, 'bots' => (array) ($data['bots'] ?? [])]);
    }

    /** @param array<mixed> $data */
    public static function accountCreate(array $data = []): string
    {

        $prepared = [];
        $prepared['accounts_url'] = Administration::accountsApiUrl();
        $prepared['admin_user_statuses'] = AdminViews::admin_user_statuses();
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
    public static function sourceForm(array $data = []): string
    {
        $bots = $data['bots'] ?? null;
        $create = $data['create'] ?? null;
        $source = $data['source'] ?? null;
        $source = is_array($source ?? null) ? $source : [];
        $bots = is_array($bots ?? null) ? $bots : [];
        $create = (bool) ($create ?? false);
        $id = (int) ($source['id'] ?? 0);
        $prepared = [];
        $prepared['source_url'] = Administration::apiUrl();
        $prepared['defaultSourceTemplate'] = Importer::defaultSourceTemplate();
        return View::part('Bots/modals/source', array_replace($data, compact('bots', 'create', 'id', 'source', 'prepared')));
    }

    /** @param array<mixed> $data */
    public static function accounts(array $data = []): string
    {
        $model = Resource::accounts(['items' => array_map(Administration::accountResource(...), (array) ($data['accounts'] ?? [])), 'filters' => (array) ($data['filters'] ?? []), 'statuses' => AdminViews::admin_user_statuses(), 'pagination' => (array) ($data['pagination'] ?? [])]);
        return Components::render('bots/accounts', $model) . self::accountCreate() . self::accountFilter($data);
    }

    /** @param array<mixed> $data */
    public static function sources(array $data = []): string
    {
        $model = ['items' => array_map(Importer::sourceResource(...), (array) ($data['sources'] ?? [])), 'bots' => (array) ($data['bots'] ?? []), 'filter_bot_user_id' => (int) ($data['filter_bot_id'] ?? 0)];
        $model['sorting'] = \TinyCat\Support\AdminSorting::headings('sources', ['bot' => $model['filter_bot_user_id'] ?: '']);
        return Components::render('bots/sources', $model + ['state' => $model]);
    }
}
