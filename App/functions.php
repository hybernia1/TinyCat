<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/Core.php';

if (!function_exists('guard')) {
    function guard(): void
    {
        if (!defined('TINYCAT')) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return Core::config($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(?string $key = null, mixed $default = null): mixed
    {
        return Core::setting($key, $default);
    }
}

if (!function_exists('setting_set')) {
    function setting_set(string $key, mixed $value, string $type = 'string', string $group = 'general'): void
    {
        Core::setSetting($key, $value, $type, $group);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return Core::publicPath($path);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Core::basePath($path);
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        return Core::db();
    }
}

if (!function_exists('app_required_tables')) {
    function app_required_tables(): array
    {
        return ['users', 'content', 'media', 'terms', 'relations', 'menu_items', 'settings'];
    }
}

if (!function_exists('site_name')) {
    function site_name(): string
    {
        return (string) config('site.name', config('app.name', 'TinyCat'));
    }
}

if (!function_exists('media_record')) {
    function media_record(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        try {
            return find('media', ['id' => $id]);
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('media_url')) {
    function media_url(int $id): string
    {
        $media = media_record($id);

        return $media === null ? '' : (string) ($media['url'] ?? '');
    }
}

if (!function_exists('site_logo')) {
    function site_logo(): ?array
    {
        return media_record((int) config('site.logo_media_id', 0));
    }
}

if (!function_exists('site_favicon')) {
    function site_favicon(): ?array
    {
        return media_record((int) config('site.favicon_media_id', 0));
    }
}

if (!function_exists('site_footer_html')) {
    function site_footer_html(): string
    {
        return trim((string) config('site.footer_html', ''));
    }
}

if (!function_exists('frontend_menu_items')) {
    function frontend_menu_items(): array
    {
        try {
            $items = all(
                'SELECT label, url, target
                FROM menu_items
                WHERE is_active = 1
                ORDER BY position ASC, id ASC'
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(static function (array $item): array {
            return [
                'href' => (string) ($item['url'] ?? '#'),
                'label' => (string) ($item['label'] ?? ''),
                'icon' => 'link',
                'target' => (string) ($item['target'] ?? '_self'),
            ];
        }, $items);
    }
}

if (!function_exists('app_table_exists')) {
    function app_table_exists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return false;
        }

        $driver = (string) config('database.driver', 'mysql');

        if ($driver === 'sqlite') {
            return (int) val(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            ) > 0;
        }

        return (int) val(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        ) > 0;
    }
}

if (!function_exists('app_sql_identifier')) {
    function app_sql_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('app_auth_account_ready')) {
    function app_auth_account_ready(): bool
    {
        try {
            return (int) val(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND %s <> ?',
                    app_sql_identifier('users'),
                    app_sql_identifier('password'),
                    app_sql_identifier('password')
                ),
                ['']
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('app_db_status')) {
    function app_db_status(?array $requiredTables = null): array
    {
        $requiredTables ??= app_required_tables();
        $status = [
            'installed' => (bool) config('install.installed', false),
            'connected' => false,
            'account_ready' => false,
            'ready' => false,
            'missing_tables' => [],
            'error' => null,
        ];

        if (!$status['installed']) {
            return $status;
        }

        try {
            db()->query('SELECT 1');
            $status['connected'] = true;

            foreach ($requiredTables as $table) {
                if (!app_table_exists((string) $table)) {
                    $status['missing_tables'][] = (string) $table;
                }
            }

            if ($status['missing_tables'] === []) {
                $status['account_ready'] = app_auth_account_ready();
            }

            $status['ready'] = $status['missing_tables'] === [] && $status['account_ready'];
        } catch (Throwable $exception) {
            $status['error'] = $exception->getMessage();
        }

        return $status;
    }
}

if (!function_exists('app_db_ready')) {
    function app_db_ready(?array $requiredTables = null): bool
    {
        return (bool) app_db_status($requiredTables)['ready'];
    }
}

if (!function_exists('asset')) {
    function asset(string $path, ?bool $version = null): string
    {
        return Core::asset($path, $version);
    }
}

if (!function_exists('icon')) {
    function icon(string $name, string $class = 'icon', ?string $label = null, array $attributes = []): string
    {
        return Core::icon($name, $class, $label, $attributes);
    }
}

if (!function_exists('q')) {
    function q(string $sql, array $params = []): PDOStatement
    {
        return Core::query($sql, $params);
    }
}

if (!function_exists('query')) {
    function query(string $sql, array $params = []): PDOStatement
    {
        return Core::query($sql, $params);
    }
}

if (!function_exists('run')) {
    function run(string $sql, array $params = []): int
    {
        return Core::exec($sql, $params);
    }
}

if (!function_exists('all')) {
    function all(string $sql, array $params = []): array
    {
        return Core::all($sql, $params);
    }
}

if (!function_exists('one')) {
    function one(string $sql, array $params = []): ?array
    {
        return Core::one($sql, $params);
    }
}

if (!function_exists('val')) {
    function val(string $sql, array $params = []): mixed
    {
        return Core::value($sql, $params);
    }
}

if (!function_exists('insert')) {
    function insert(string $table, array $data): string
    {
        return Core::insert($table, $data);
    }
}

if (!function_exists('update')) {
    function update(string $table, array $data, array $where): int
    {
        return Core::update($table, $data, $where);
    }
}

if (!function_exists('delete')) {
    function delete(string $table, array $where): int
    {
        return Core::delete($table, $where);
    }
}

if (!function_exists('find')) {
    function find(string $table, array $where, array|string $columns = '*'): ?array
    {
        return Core::find($table, $where, $columns);
    }
}

if (!function_exists('records')) {
    function records(string $table, array $where = [], array|string $columns = '*', ?int $limit = null, ?int $offset = null): array
    {
        return Core::get($table, $where, $columns, $limit, $offset);
    }
}

if (!function_exists('total')) {
    function total(string $table, array $where = []): int
    {
        return Core::count($table, $where);
    }
}

if (!function_exists('paginate')) {
    function paginate(
        string $table,
        array $where = [],
        array|string $columns = '*',
        ?int $page = null,
        int $perPage = 15,
        ?string $orderBy = null,
        string $direction = 'ASC'
    ): array {
        return Core::paginate($table, $where, $columns, $page, $perPage, $orderBy, $direction);
    }
}

if (!function_exists('pagination')) {
    function pagination(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
    {
        return Core::pagination($pagination, $baseUrl, $pageName, $window);
    }
}

if (!function_exists('pager')) {
    function pager(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
    {
        return Core::pagination($pagination, $baseUrl, $pageName, $window);
    }
}

if (!function_exists('pagination_meta')) {
    function pagination_meta(int $total, ?int $page = null, int $perPage = 15): array
    {
        return Core::paginationMeta($total, $page, $perPage);
    }
}

if (!function_exists('pagination_sql')) {
    function pagination_sql(array $pagination): string
    {
        $perPage = max(1, min(200, (int) ($pagination['per_page'] ?? 15)));
        $offset = max(0, (int) ($pagination['offset'] ?? 0));

        return ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    }
}

if (!function_exists('admin_per_page_options')) {
    function admin_per_page_options(): array
    {
        $configured = (array) config('admin.per_page_options', [10, 25, 50, 100]);
        $options = [];

        foreach ($configured as $option) {
            $value = max(1, min(200, (int) $option));
            $options[$value] = $value;
        }

        if ($options === []) {
            $options = [10 => 10, 25 => 25, 50 => 50, 100 => 100];
        }

        ksort($options);

        return array_values($options);
    }
}

if (!function_exists('admin_per_page')) {
    function admin_per_page(?int $value = null): int
    {
        $options = admin_per_page_options();
        $default = (int) config('admin.per_page', $options[0] ?? 25);
        $default = in_array($default, $options, true) ? $default : ($options[0] ?? 25);
        $value ??= (int) get('per_page', $default);
        $value = max(1, min(200, $value));

        return in_array($value, $options, true) ? $value : $default;
    }
}

if (!function_exists('admin_page')) {
    function admin_page(string $name = 'page'): int
    {
        return max(1, (int) get($name, 1));
    }
}

if (!function_exists('admin_list_query')) {
    function admin_list_query(array $params = [], bool $ajax = true): array
    {
        $query = [];

        if ($ajax) {
            $query['api'] = 'list';
            $query['view'] = 'html';
        }

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $query[(string) $key] = $value;
        }

        return $query;
    }
}

if (!function_exists('admin_list_url')) {
    function admin_list_url(string $path, array $params = [], bool $ajax = true): string
    {
        $query = admin_list_query($params, $ajax);

        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}

if (!function_exists('admin_pagination')) {
    function admin_pagination(array $pagination, string $path, string $target, array $params = [], string $pageName = 'page', int $window = 2): string
    {
        $page = max(1, (int) ($pagination['page'] ?? 1));
        $lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
        $total = max(0, (int) ($pagination['total'] ?? 0));
        $from = max(0, (int) ($pagination['from'] ?? 0));
        $to = max(0, (int) ($pagination['to'] ?? 0));
        $window = max(1, $window);

        if ($lastPage <= 1) {
            return '';
        }

        $item = static function (string $label, int|string|null $targetPage, string $class = '', bool $disabled = false, bool $current = false) use ($path, $target, $params, $pageName): string {
            $classes = trim('pagination-link ' . $class . ($current ? ' is-active' : ''));

            if ($disabled || $targetPage === null) {
                return '<span class="' . e($classes) . '" aria-disabled="true">' . e($label) . '</span>';
            }

            if ($current) {
                return '<span class="' . e($classes) . '" aria-current="page">' . e($label) . '</span>';
            }

            $query = $params;
            $query[$pageName] = (int) $targetPage;
            $href = admin_list_url($path, $query, true);
            $history = admin_list_url($path, $query, false);

            return '<a class="' . e($classes) . '" href="' . e($href) . '" data-ajax data-ajax-target="' . e($target) . '" data-history="' . e($history) . '">' . e($label) . '</a>';
        };

        $pages = [1, $lastPage];
        $start = max(1, $page - $window);
        $end = min($lastPage, $page + $window);

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        $html = '<nav class="pagination admin-pagination" aria-label="' . et('common.pagination') . '">';
        $html .= '<div class="pagination-summary">' . e(t('common.pagination_summary', ['from' => (string) $from, 'to' => (string) $to, 'total' => (string) $total])) . '</div>';
        $html .= '<div class="pagination-list">';
        $html .= $item(t('common.previous', [], null, 'Previous'), $pagination['prev_page'] ?? null, 'pagination-prev', $page <= 1);

        $previous = null;

        foreach ($pages as $pageNumber) {
            if ($previous !== null && $pageNumber > $previous + 1) {
                $html .= '<span class="pagination-ellipsis" aria-hidden="true">...</span>';
            }

            $html .= $item((string) $pageNumber, $pageNumber, '', false, $pageNumber === $page);
            $previous = $pageNumber;
        }

        $html .= $item(t('common.next', [], null, 'Next'), $pagination['next_page'] ?? null, 'pagination-next', $page >= $lastPage);
        $html .= '</div></nav>';

        return $html;
    }
}

if (!function_exists('admin_per_page_control')) {
    function admin_per_page_control(string $path, string $target, array $params = [], ?int $selected = null): string
    {
        $selected ??= admin_per_page();
        $params['page'] = 1;

        ob_start();
        ?>
        <form class="admin-per-page-form" action="<?= e($path) ?>" method="get" data-ajax-form data-ajax-target="<?= e($target) ?>" data-history="<?= e($path) ?>">
            <input type="hidden" name="api" value="list">
            <input type="hidden" name="view" value="html">
            <?php foreach ($params as $key => $value): ?>
                <?php if ($key === 'per_page' || $value === '' || $value === null) {
                    continue;
                } ?>
                <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
            <label class="field-inline">
                <span class="label"><?= et('common.per_page') ?></span>
                <select class="select select-sm" name="per_page" data-submit-on-change>
                    <?php foreach (admin_per_page_options() as $option): ?>
                        <option value="<?= e((string) $option) ?>"<?= $selected === $option ? ' selected' : '' ?>><?= e((string) $option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return Core::e($value);
    }
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return Core::e($value);
    }
}

if (!function_exists('t')) {
    function t(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        return Core::t($key, $replace, $locale, $default);
    }
}

if (!function_exists('et')) {
    function et(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        return e(t($key, $replace, $locale, $default));
    }
}

if (!function_exists('locale')) {
    function locale(?string $locale = null): string
    {
        return Core::locale($locale);
    }
}

if (!function_exists('language_code')) {
    function language_code(string $code): string
    {
        $code = strtolower(trim(str_replace('_', '-', $code)));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $code) ? $code : '';
    }
}

if (!function_exists('language_names')) {
    function language_names(): array
    {
        $defaults = [
            'af' => 'Afrikaans',
            'ar' => 'العربية',
            'bg' => 'Български',
            'ca' => 'Català',
            'cs' => 'Česky',
            'da' => 'Dansk',
            'de' => 'Deutsch',
            'el' => 'Ελληνικά',
            'en' => 'English',
            'es' => 'Español',
            'et' => 'Eesti',
            'fi' => 'Suomi',
            'fr' => 'Français',
            'he' => 'עברית',
            'hi' => 'हिन्दी',
            'hr' => 'Hrvatski',
            'hu' => 'Magyar',
            'it' => 'Italiano',
            'ja' => '日本語',
            'ko' => '한국어',
            'lt' => 'Lietuvių',
            'lv' => 'Latviešu',
            'nl' => 'Nederlands',
            'no' => 'Norsk',
            'pl' => 'Polski',
            'pt' => 'Português',
            'pt-br' => 'Português (Brasil)',
            'ro' => 'Română',
            'ru' => 'Русский',
            'sk' => 'Slovensky',
            'sl' => 'Slovenščina',
            'sr' => 'Српски',
            'sv' => 'Svenska',
            'tr' => 'Türkçe',
            'uk' => 'Українська',
            'vi' => 'Tiếng Việt',
            'zh' => '中文',
        ];
        $configured = config('i18n.languages', []);
        $configured = is_array($configured) ? $configured : [];
        $languages = [];

        foreach ($configured as $code => $label) {
            $normalized = is_int($code) ? language_code((string) $label) : language_code((string) $code);

            if ($normalized !== '') {
                $languages[$normalized] = is_int($code) ? ($defaults[$normalized] ?? strtoupper($normalized)) : (string) $label;
            }
        }

        return $languages + $defaults;
    }
}

if (!function_exists('language_name')) {
    function language_name(string $code, ?string $default = null): string
    {
        $code = language_code($code);

        if ($code === '') {
            return (string) ($default ?? '');
        }

        $names = language_names();

        if (isset($names[$code])) {
            return (string) $names[$code];
        }

        $path = rtrim((string) config('i18n.directory', base_path('lang')), DIRECTORY_SEPARATOR . '/\\')
            . DIRECTORY_SEPARATOR . $code . '.json';

        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);

            if (is_array($data) && !empty($data['install']['language_label'])) {
                return (string) $data['install']['language_label'];
            }
        }

        return (string) ($default ?? strtoupper($code));
    }
}

if (!function_exists('languages')) {
    function languages(?array $codes = null, bool $includeFiles = true): array
    {
        $items = $codes === null ? language_names() : [];

        if ($codes !== null) {
            foreach ($codes as $key => $value) {
                $code = is_int($key) ? language_code((string) $value) : language_code((string) $key);

                if ($code !== '') {
                    $items[$code] = is_int($key) ? language_name($code) : (string) $value;
                }
            }
        }

        if ($includeFiles) {
            $directory = rtrim((string) config('i18n.directory', base_path('lang')), DIRECTORY_SEPARATOR . '/\\');

            foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                $code = language_code(pathinfo($file, PATHINFO_FILENAME));

                if ($code !== '') {
                    $items[$code] = language_name($code);
                }
            }
        }

        foreach ([locale()] as $code) {
            $code = language_code((string) $code);

            if ($code !== '' && !isset($items[$code])) {
                $items[$code] = language_name($code);
            }
        }

        ksort($items);

        return $items;
    }
}

if (!function_exists('language_packages')) {
    function language_packages(): array
    {
        $directory = rtrim((string) config('i18n.directory', base_path('lang')), DIRECTORY_SEPARATOR . '/\\');
        $defaults = language_names();
        $items = [];

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $code = language_code(pathinfo($file, PATHINFO_FILENAME));

            if ($code === '') {
                continue;
            }

            $data = json_decode((string) file_get_contents($file), true);
            $items[$code] = is_array($data) && !empty($data['install']['language_label'])
                ? (string) $data['install']['language_label']
                : ($defaults[$code] ?? strtoupper($code));
        }

        ksort($items);

        return $items;
    }
}

if (!function_exists('language_options')) {
    function language_options(?string $selected = null, ?array $codes = null): string
    {
        $selected = language_code((string) $selected);
        $html = '';
        $items = $codes === null ? language_packages() : languages($codes, false);

        foreach ($items as $code => $label) {
            $html .= '<option value="' . e($code) . '"' . ($selected === $code ? ' selected' : '') . '>' . e(strtoupper($code) . ' - ' . $label) . '</option>';
        }

        return $html;
    }
}

if (!function_exists('date_format_presets')) {
    function date_format_presets(): array
    {
        return [
            'd.m.Y' => 'd.m.Y',
            'j.n.Y' => 'j.n.Y',
            'd. m. Y' => 'd. m. Y',
            'Y-m-d' => 'Y-m-d',
            'd/m/Y' => 'd/m/Y',
            'm/d/Y' => 'm/d/Y',
            'd-m-Y' => 'd-m-Y',
            'M j, Y' => 'M j, Y',
        ];
    }
}

if (!function_exists('time_format_presets')) {
    function time_format_presets(): array
    {
        return [
            'H:i' => 'H:i',
            'H:i:s' => 'H:i:s',
            'G:i' => 'G:i',
            'g:i A' => 'g:i A',
            'h:i A' => 'h:i A',
        ];
    }
}

if (!function_exists('datetime_format_presets')) {
    function datetime_format_presets(): array
    {
        return [
            'd.m.Y H:i' => 'd.m.Y H:i',
            'j.n.Y H:i' => 'j.n.Y H:i',
            'd. m. Y H:i' => 'd. m. Y H:i',
            'Y-m-d H:i' => 'Y-m-d H:i',
            'Y-m-d H:i:s' => 'Y-m-d H:i:s',
            'd/m/Y H:i' => 'd/m/Y H:i',
            'm/d/Y g:i A' => 'm/d/Y g:i A',
            'M j, Y g:i A' => 'M j, Y g:i A',
            'Y-m-d\TH:i' => 'Y-m-d\TH:i',
        ];
    }
}

if (!function_exists('datetime_format_preset_options')) {
    function datetime_format_preset_options(string $type, ?string $selected = null): string
    {
        $selected = trim((string) $selected);
        $presets = match ($type) {
            'date' => date_format_presets(),
            'time' => time_format_presets(),
            'datetime' => datetime_format_presets(),
            default => [],
        };
        $sample = new DateTimeImmutable('2026-07-05 15:04:09');
        $html = '';

        foreach ($presets as $format => $label) {
            $example = $sample->format((string) $format);
            $html .= '<option value="' . e($format) . '"' . ($selected === $format ? ' selected' : '') . '>'
                . e((string) $label . ' - ' . $example)
                . '</option>';
        }

        return $html;
    }
}

if (!function_exists('datetime_format_preset_exists')) {
    function datetime_format_preset_exists(string $type, string $format): bool
    {
        $presets = match ($type) {
            'date' => date_format_presets(),
            'time' => time_format_presets(),
            'datetime' => datetime_format_presets(),
            default => [],
        };

        return array_key_exists($format, $presets);
    }
}

if (!function_exists('timezone_presets')) {
    function timezone_presets(): array
    {
        $defaults = [
            'UTC',
            'Europe/Prague',
            'Europe/Bratislava',
            'Europe/Warsaw',
            'Europe/Berlin',
            'Europe/Vienna',
            'Europe/London',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Toronto',
            'America/Sao_Paulo',
            'Asia/Dubai',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Australia/Sydney',
        ];
        $configured = config('datetime.timezones', []);
        $items = is_array($configured) && $configured !== [] ? $configured : $defaults;
        $valid = array_flip(timezone_identifiers_list());
        $presets = [];

        foreach ($items as $key => $value) {
            $timezone = is_int($key) ? (string) $value : (string) $key;
            $label = is_int($key) ? $timezone : (string) $value;

            if (isset($valid[$timezone])) {
                $presets[$timezone] = $label;
            }
        }

        return $presets;
    }
}

if (!function_exists('timezone_preset_label')) {
    function timezone_preset_label(string $timezone, ?string $label = null): string
    {
        $date = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $offset = $date->format('P');

        return '(UTC' . ($offset === '+00:00' ? '' : $offset) . ') ' . ($label ?: $timezone);
    }
}

if (!function_exists('timezone_options')) {
    function timezone_options(?string $selected = null): string
    {
        $selected = trim((string) $selected);
        $presets = timezone_presets();
        $valid = array_flip(timezone_identifiers_list());

        if ($selected !== '' && isset($valid[$selected]) && !isset($presets[$selected])) {
            $presets = [$selected => $selected] + $presets;
        }

        $html = '';

        foreach ($presets as $timezone => $label) {
            $html .= '<option value="' . e($timezone) . '"' . ($selected === $timezone ? ' selected' : '') . '>'
                . e(timezone_preset_label((string) $timezone, (string) $label))
                . '</option>';
        }

        return $html;
    }
}

if (!function_exists('timezone')) {
    function timezone(): DateTimeZone
    {
        return Core::timezone();
    }
}

if (!function_exists('now')) {
    function now(?string $format = null): DateTimeImmutable|string
    {
        return Core::now($format);
    }
}

if (!function_exists('datetime')) {
    function datetime(mixed $value = null, ?string $format = null, ?bool $relative = null): string
    {
        if ($relative === true || ($relative === null && $format === null && (bool) config('datetime.relative', false))) {
            return relative_time($value);
        }

        return Core::dateTime($value, $format);
    }
}

if (!function_exists('relative_time')) {
    function relative_time(mixed $value = null, mixed $base = null): string
    {
        $target = relative_datetime_value($value);
        $origin = relative_datetime_value($base);
        $seconds = $target->getTimestamp() - $origin->getTimestamp();
        $absolute = abs($seconds);

        if ($absolute < 45) {
            return t('relative.now');
        }

        [$unit, $count] = match (true) {
            $absolute < 90 => ['minute', 1],
            $absolute < 45 * 60 => ['minute', (int) round($absolute / 60)],
            $absolute < 90 * 60 => ['hour', 1],
            $absolute < 22 * 3600 => ['hour', (int) round($absolute / 3600)],
            $absolute < 36 * 3600 => ['day', 1],
            $absolute < 7 * 86400 => ['day', (int) round($absolute / 86400)],
            $absolute < 4 * 604800 => ['week', max(1, (int) round($absolute / 604800))],
            $absolute < 45 * 86400 => ['month', 1],
            $absolute < 345 * 86400 => ['month', max(1, (int) round($absolute / (30 * 86400)))],
            $absolute < 545 * 86400 => ['year', 1],
            default => ['year', max(1, (int) round($absolute / (365 * 86400)))],
        };
        $direction = $seconds < 0 ? 'past' : 'future';
        $form = relative_plural_form($count);

        return t('relative.' . $direction . '.' . $unit . '.' . $form, ['count' => (string) $count]);
    }
}

if (!function_exists('relative_date')) {
    function relative_date(mixed $value = null, mixed $base = null): string
    {
        return relative_time($value, $base);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(mixed $value = null, mixed $base = null): string
    {
        return relative_time($value, $base);
    }
}

if (!function_exists('relative_datetime_value')) {
    function relative_datetime_value(mixed $value = null): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(timezone());
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(timezone());
        }

        if (is_int($value) || is_float($value)) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone(timezone());
        }

        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return new DateTimeImmutable('now', timezone());
        }

        return new DateTimeImmutable($value, timezone());
    }
}

if (!function_exists('relative_plural_form')) {
    function relative_plural_form(int $count): string
    {
        $language = strtolower(strtok(locale(), '-_') ?: 'en');

        if (in_array($language, ['cs', 'sk'], true)) {
            return $count === 1 ? 'one' : ($count >= 2 && $count <= 4 ? 'few' : 'many');
        }

        return $count === 1 ? 'one' : 'many';
    }
}

if (!function_exists('date_value')) {
    function date_value(mixed $value = null, ?string $format = null): string
    {
        return Core::dateValue($value, $format);
    }
}

if (!function_exists('time_value')) {
    function time_value(mixed $value = null, ?string $format = null): string
    {
        return Core::timeValue($value, $format);
    }
}

if (!function_exists('date_iso')) {
    function date_iso(mixed $value = null): string
    {
        return Core::dateIso($value);
    }
}

if (!function_exists('date_db')) {
    function date_db(mixed $value = null): string
    {
        return Core::dateDb($value);
    }
}

if (!function_exists('slug')) {
    function slug(string $text, string $separator = '-'): string
    {
        return Core::slug($text, $separator);
    }
}

if (!function_exists('upload')) {
    function upload(array $file, string $directory = '', array $options = []): array
    {
        return Core::upload($file, $directory, $options);
    }
}

if (!function_exists('upload_options')) {
    function upload_options(?string $profile = null, array $overrides = []): array
    {
        return Core::uploadOptions($profile, $overrides);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): never
    {
        Core::redirect($url, $status);
    }
}

if (!function_exists('capture')) {
    function capture(callable $callback): string
    {
        return Core::capture($callback);
    }
}

if (!function_exists('render')) {
    function render(string $template, array $data = [], ?string $directory = null): string
    {
        return Core::render($template, $data, $directory);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = [], ?string $directory = null): string
    {
        return Core::render($template, $data, $directory);
    }
}

if (!function_exists('layout')) {
    function layout(string $template, array $data = [], mixed $content = null, ?string $directory = null): void
    {
        Core::layout($template, $data, $content, $directory);
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200): never
    {
        Core::json($data, $status);
    }
}

if (!function_exists('api')) {
    function api(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
    {
        Core::apiOk($data, $message, $status, $meta);
    }
}

if (!function_exists('api_ok')) {
    function api_ok(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
    {
        Core::apiOk($data, $message, $status, $meta);
    }
}

if (!function_exists('api_created')) {
    function api_created(mixed $data = null, ?string $message = 'Created.', array $meta = []): never
    {
        Core::apiCreated($data, $message, $meta);
    }
}

if (!function_exists('api_no_content')) {
    function api_no_content(): never
    {
        Core::apiNoContent();
    }
}

if (!function_exists('api_error')) {
    function api_error(string $message = 'Request failed.', int $status = 400, string $code = 'error', array $details = []): never
    {
        Core::apiError($message, $status, $code, $details);
    }
}

if (!function_exists('api_validation')) {
    function api_validation(array $errors, string $message = 'Validation failed.'): never
    {
        Core::apiValidation($errors, $message);
    }
}

if (!function_exists('api_endpoint')) {
    function api_endpoint(array|string $methods, callable $handler): never
    {
        Core::apiEndpoint($methods, $handler);
    }
}

if (!function_exists('route')) {
    function route(array|string $methods, string $path, callable $handler): void
    {
        Core::route($methods, $path, $handler);
    }
}

if (!function_exists('api_route')) {
    function api_route(array|string $methods, string $path, callable $handler): void
    {
        Core::apiRoute($methods, $path, $handler);
    }
}

if (!function_exists('route_get')) {
    function route_get(string $path, callable $handler): void
    {
        Core::route('GET', $path, $handler);
    }
}

if (!function_exists('route_post')) {
    function route_post(string $path, callable $handler): void
    {
        Core::route('POST', $path, $handler);
    }
}

if (!function_exists('route_put')) {
    function route_put(string $path, callable $handler): void
    {
        Core::route('PUT', $path, $handler);
    }
}

if (!function_exists('route_patch')) {
    function route_patch(string $path, callable $handler): void
    {
        Core::route('PATCH', $path, $handler);
    }
}

if (!function_exists('route_delete')) {
    function route_delete(string $path, callable $handler): void
    {
        Core::route('DELETE', $path, $handler);
    }
}

if (!function_exists('dispatch_routes')) {
    function dispatch_routes(?string $path = null, ?string $method = null): bool
    {
        return Core::dispatch($path, $method);
    }
}

if (!function_exists('autoroute')) {
    function autoroute(?string $path = null, ?string $directory = null): bool
    {
        return Core::autoroute($path, $directory);
    }
}

if (!function_exists('route_path')) {
    function route_path(?string $path = null): string
    {
        return Core::path($path);
    }
}

if (!function_exists('require_method')) {
    function require_method(array|string $methods): void
    {
        Core::requireMethod($methods);
    }
}

if (!function_exists('body')) {
    function body(?string $key = null, mixed $default = null): mixed
    {
        return Core::payload($key, $default);
    }
}

if (!function_exists('payload')) {
    function payload(?string $key = null, mixed $default = null): mixed
    {
        return Core::payload($key, $default);
    }
}

if (!function_exists('input')) {
    function input(?string $key = null, mixed $default = null): mixed
    {
        return Core::input($key, $default);
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        return Core::request($key, $default);
    }
}

if (!function_exists('validate')) {
    function validate(array $data, array $rules, array $messages = []): array
    {
        return Core::validate($data, $rules, $messages);
    }
}

if (!function_exists('api_validated')) {
    function api_validated(array $rules, ?array $data = null, array $messages = []): array
    {
        return Core::validated($rules, $data, $messages);
    }
}

if (!function_exists('wants_json')) {
    function wants_json(): bool
    {
        return Core::wantsJson();
    }
}

if (!function_exists('wants_partial')) {
    function wants_partial(): bool
    {
        return Core::wantsPartial();
    }
}

if (!function_exists('is_json')) {
    function is_json(): bool
    {
        return Core::isJson();
    }
}

if (!function_exists('bearer_token')) {
    function bearer_token(): ?string
    {
        return Core::bearerToken();
    }
}

if (!function_exists('get')) {
    function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }
}

if (!function_exists('post')) {
    function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('method')) {
    function method(): string
    {
        return Core::method();
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return Core::isPost();
    }
}

if (!function_exists('flash')) {
    function flash(string $key, mixed $value = null): mixed
    {
        if (func_num_args() === 2) {
            return Core::flash($key, $value);
        }

        return Core::flash($key);
    }
}

if (!function_exists('auth')) {
    function auth(?string $key = null, mixed $default = null): mixed
    {
        return Core::auth($key, $default);
    }
}

if (!function_exists('auth_id')) {
    function auth_id(): mixed
    {
        return Core::authId();
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool
    {
        return Core::authCheck();
    }
}

if (!function_exists('auth_attempt')) {
    function auth_attempt(array $credentials): bool
    {
        return Core::authAttempt($credentials);
    }
}

if (!function_exists('auth_login')) {
    function auth_login(array|int|string $user, bool $remember = false): bool
    {
        return Core::authLogin($user, $remember);
    }
}

if (!function_exists('auth_logout')) {
    function auth_logout(): void
    {
        Core::authLogout();
    }
}

if (!function_exists('require_auth')) {
    function require_auth(?string $redirect = null): array
    {
        return Core::requireAuth($redirect);
    }
}

if (!function_exists('guest_only')) {
    function guest_only(?string $redirect = null): void
    {
        Core::guestOnly($redirect);
    }
}

if (!function_exists('auth_is')) {
    function auth_is(array|string $roles): bool
    {
        return Core::authIs($roles);
    }
}

if (!function_exists('auth_password')) {
    function auth_password(string $password): string
    {
        return Core::authPassword($password);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Core::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Core::csrfField();
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(?string $token = null): bool
    {
        return Core::verifyCsrf($token);
    }
}

if (!function_exists('csrf_require')) {
    function csrf_require(?string $token = null): void
    {
        Core::requireCsrf($token);
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return Core::clientIp();
    }
}

if (!function_exists('rate_limit')) {
    function rate_limit(string $key, ?int $max = null, ?int $window = null, ?string $identity = null): array
    {
        return Core::rateLimit($key, $max, $window, $identity);
    }
}

if (!function_exists('guard_request_security')) {
    function guard_request_security(): void
    {
        Core::guardRequestSecurity();
    }
}

if (!function_exists('captcha_field')) {
    function captcha_field(string $context = 'form'): string
    {
        return Core::captchaField($context);
    }
}

if (!function_exists('captcha_check')) {
    function captcha_check(string $context = 'form'): bool
    {
        return Core::captchaCheck($context);
    }
}

if (!function_exists('captcha_refresh')) {
    function captcha_refresh(string $context = 'form'): string
    {
        return Core::captchaRefresh($context);
    }
}
