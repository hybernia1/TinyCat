<?php
declare(strict_types=1);
namespace TinyCat\Search;

use TinyCat\Http\Request;
use TinyCat\Http\Web;
use TinyCat\Security\Captcha;
use TinyCat\Site\Presentation;
use TinyCat\Status\Feed;
use TinyCat\Status\ViewData;
use TinyCat\Support\Localization;
use TinyCat\Users\Authentication;

/** HTTP page actions and prepared presentation composition. */
final class Pages
{
    public static function search(): void
    {
        \TinyCat\Site\PublicPages::render('search', self::data());
    }

    /** @return array<string, mixed> */
    public static function data(): array
    {
        Request::current()->validated(['q' => 'string', 'type' => 'string']);
        $query = Queries::public_search_normalize_query((string) Web::get('q', ''));
        $type = (string) Web::get('type', 'content');
        $type = in_array($type, ['content', 'users', 'tags'], true) ? $type : 'content';
        $minimumLength = $type === 'content' ? 3 : 2;
        $hasQuery = Queries::length($query) >= $minimumLength && Queries::length($query) <= Queries::MAX_QUERY_LENGTH;
        $current = '/search?' . http_build_query(['q' => $query, 'type' => $type]);

        $searchBlocked = $hasQuery ? Guard::public_search_guard($query) : null;
        $results = $hasQuery && $searchBlocked === null ? Queries::collection($query, $type, [], Feed::public_status_initial_page_limit()) : ['items' => [], 'next_url' => '', 'done' => true];
        $authUser = Authentication::auth();
        $statusItems = $type === 'content' && $results['items'] !== []
            ? ViewData::status_prepare_items_view(Feed::public_status_items_by_ids(array_column($results['items'], 'id')), $authUser)
            : [];

        $tabs = [];
        foreach (['content', 'users', 'tags'] as $tab) {
            $tabs[] = ['type' => $tab, 'active' => $type === $tab, 'url' => \TinyCat\Support\LocalUrls::resolve('/search?' . http_build_query(['q' => $query, 'type' => $tab]))];
        }
        return ['page' => [
            'title' => $hasQuery ? Localization::t('public.search_title_query', ['query' => $query]) : Localization::t('public.search_title'),
            'current' => '/search',
            'meta' => [
                'description' => $hasQuery
                    ? Localization::t('public.search_meta_query', ['query' => $query])
                    : Localization::t('public.search_meta'),
                'url' => $current,
                'image' => Presentation::site_meta_image_url(),
                'robots' => 'noindex,follow',
            ],
            'search_query' => $query,
        ], 'context' => [
            'authUser' => $authUser, 'statusItems' => $statusItems,
            'feedId' => 'search-results',
            'feedMore' => ['loaded' => count($results['items']), 'limit' => Feed::public_status_initial_page_limit(), 'next_url' => $results['next_url']],
            'search' => ['query' => $query, 'type' => $type, 'valid' => $hasQuery, 'minimum_length' => $minimumLength,
                'blocked' => $searchBlocked !== null, 'block_code' => $searchBlocked['code'] ?? '',
                'captcha' => Captcha::configuration('search', ($searchBlocked['code'] ?? '') === 'captcha_required'),
                'tabs' => $tabs, 'entities' => $type === 'content' ? [] : $results['items'], 'empty' => $results['items'] === []],
        ]];
    }
}
