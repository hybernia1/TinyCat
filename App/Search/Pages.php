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
        Request::current()->validated(['q' => 'string', 'type' => 'string', 'media' => 'string']);
        $query = Queries::public_search_normalize_query((string) Web::get('q', ''));
        $archiveType = Feed::archive_type((string) Web::get('media', ''));
        $minimumLength = 3;
        $hasQuery = Queries::length($query) >= $minimumLength && Queries::length($query) <= Queries::MAX_QUERY_LENGTH;
        $current = '/search?' . http_build_query(['q' => $query]);
        $filteredUrl = $current . ($archiveType !== '' ? '&media=' . $archiveType : '');
        $searchFeedUrl = '/search/feed?' . http_build_query(['q' => $query, ...($archiveType !== '' ? ['media' => $archiveType] : [])]);

        $searchBlocked = $hasQuery ? Guard::public_search_guard($query) : null;
        $results = $hasQuery && $searchBlocked === null ? Queries::collection($query, 'content', [], Feed::public_status_initial_page_limit(), $archiveType) : ['items' => [], 'next_url' => '', 'done' => true];
        $authUser = Authentication::auth();
        $statusItems = $results['items'] !== []
            ? ViewData::status_prepare_items_view(Feed::public_status_items_by_ids(array_column($results['items'], 'id')), $authUser)
            : [];

        return ['page' => [
            'title' => $hasQuery ? Localization::t('public.search_title_query', ['query' => $query]) : Localization::t('public.search_title'),
            'current' => '/search',
            'meta' => [
                'description' => $hasQuery
                    ? Localization::t('public.search_meta_query', ['query' => $query])
                    : Localization::t('public.search_meta'),
                'url' => $filteredUrl,
                'rss' => $hasQuery ? $searchFeedUrl : '',
                'image' => Presentation::site_meta_image_url(),
                'robots' => 'noindex,follow',
            ],
            'search_query' => $query,
        ], 'context' => [
            'authUser' => $authUser, 'statusItems' => $statusItems,
            'archiveType' => $archiveType, 'archiveBaseUrl' => $current, 'searchFeedUrl' => $hasQuery ? $searchFeedUrl : '',
            'feedId' => 'search-results',
            'feedMore' => ['loaded' => count($results['items']), 'limit' => Feed::public_status_initial_page_limit(), 'next_url' => $results['next_url']],
            'search' => ['query' => $query, 'type' => 'content', 'valid' => $hasQuery, 'minimum_length' => $minimumLength,
                'blocked' => $searchBlocked !== null, 'block_code' => $searchBlocked['code'] ?? '',
                'captcha' => Captcha::configuration('search', ($searchBlocked['code'] ?? '') === 'captcha_required'),
                'empty' => $results['items'] === []],
        ]];
    }
}
