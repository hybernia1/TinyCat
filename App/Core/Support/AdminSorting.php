<?php
declare(strict_types=1);

namespace TinyCat\Support;

/** Allowlisted SQL expressions and shared list navigation state. */
final class AdminSorting
{
    /** @param array{string, string, string, array<string, string>, string, string} $config
     * @param array<string, mixed>|null $input
     * @return array{sort: string, direction: string}
     */
    public static function state(array $config, ?array $input = null): array
    {
        $sort = $input['sort'] ?? \TinyCat\Http\Web::get('sort', '');
        $direction = $input['direction'] ?? \TinyCat\Http\Web::get('direction', 'asc');
        return ['sort' => is_string($sort) && isset($config[3][$sort]) ? $sort : '',
            'direction' => $direction === 'desc' ? 'desc' : 'asc'];
    }

    /** @param array{string, string, string, array<string, string>, string, string} $config
     * @param array<string, mixed>|null $input */
    public static function order(array $config, ?array $input = null): string
    {
        $state = self::state($config, $input);
        return $state['sort'] === '' ? $config[4] : $config[3][$state['sort']] . ' ' . strtoupper($state['direction']) . ', ' . $config[5] . ' DESC';
    }

    /** @param array{string, string, string, array<string, string>, string, string} $config
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function headings(array $config, array $params = [], string $apiPath = '', string $historyPath = ''): array
    {
        $state = self::state($config, $params);
        $headings = [];
        foreach ($config[3] as $key => $_expression) {
            $active = $state['sort'] === $key;
            $next = array_replace($params, ['sort' => $key, 'direction' => $active && $state['direction'] === 'asc' ? 'desc' : 'asc', 'page' => 1]);
            $headings[$key] = ['url' => AdminViews::admin_list_url($apiPath !== '' ? $apiPath : $config[0], $next),
                'history' => AdminViews::admin_list_url($historyPath !== '' ? $historyPath : $config[1], $next), 'target' => $config[2],
                'aria' => $active ? ($state['direction'] === 'asc' ? 'ascending' : 'descending') : 'none',
                'ascending' => $active && $state['direction'] === 'asc', 'descending' => $active && $state['direction'] === 'desc'];
        }
        return $headings;
    }
}
