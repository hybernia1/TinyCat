<?php
declare(strict_types=1);
namespace TinyCat\Search;

use TinyCat\Http\Request;
use TinyCat\Http\Session;
use TinyCat\Http\Web;
use TinyCat\Security\Captcha;
use TinyCat\Security\Csrf;
use TinyCat\Site\Layout;
use TinyCat\Site\Presentation;
use TinyCat\Status\Feed;
use TinyCat\Status\ViewData;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Users\Authentication;
use TinyCat\Users\Login;

/** HTTP page actions and prepared presentation composition. */
final class Pages
{
    public static function search(): void
    {
        $query = trim((string) Web::get('q', ''));
        $hasQuery = (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) >= 2;
        $current = '/search' . ($query !== '' ? '?q=' . rawurlencode($query) : '');

        if (Request::current()->isPost() && (string) Web::post('_search_captcha', '') === '1') {
            Csrf::requireToken();
            if (Guard::public_search_captcha_verify()) {
                Web::redirect($current);
            }

            Session::flash('error', Localization::t('auth.invalid_captcha'));
            Web::redirect($current);
        }

        $searchBlocked = $hasQuery ? Guard::public_search_guard($query) : null;
        $results = $hasQuery && $searchBlocked === null ? Queries::public_search_results($query, 24) : [
            'query' => $query,
            'tags' => [],
            'users' => [],
            'content' => [],
        ];
        $authUser = Authentication::auth();
        $statusItems = $hasQuery && $searchBlocked === null
            ? ViewData::status_prepare_items_view(self::statusItems($results), $authUser)
            : [];

        $field = Captcha::field('search');
        $authModalId = Login::auth_modal_id();
        $authModalUrl = Login::auth_modal_url();
        $publicSidebar = Presentation::public_sidebar();
        $pageUrls = ['/search' => \TinyCat\Support\LocalUrls::resolve('/search'), '/login' => \TinyCat\Support\LocalUrls::resolve('/login')];
        Layout::render([
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
        ], View::render('search', compact('pageUrls', 'query', 'hasQuery', 'searchBlocked', 'statusItems', 'current', 'authUser', 'field', 'authModalId', 'authModalUrl', 'publicSidebar'), __DIR__ . '/templates'));
    }
    /** @param array<mixed> $results
     * @return array<mixed>
     */
    private static function statusItems(array $results): array
    {
        $ids = [];

        foreach ((array) ($results['content'] ?? []) as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = true;
            }
        }

        foreach (array_slice((array) ($results['users'] ?? []), 0, 3) as $user) {
            foreach (Feed::public_status_ids_by_author((int) ($user['id'] ?? 0), 4) as $id) {
                if ($id > 0 && !isset($ids[$id])) {
                    $ids[$id] = true;
                }

                if (count($ids) >= 48) {
                    break 2;
                }
            }
        }

        foreach (array_slice((array) ($results['tags'] ?? []), 0, 3) as $tag) {
            $name = trim((string) ($tag['title'] ?? ''), '# ');

            foreach (Feed::public_status_ids_by_tag($name, 4) as $id) {
                if ($id > 0 && !isset($ids[$id])) {
                    $ids[$id] = true;
                }

                if (count($ids) >= 48) {
                    break 2;
                }
            }
        }

        return Feed::public_status_items_by_ids(array_slice(array_keys($ids), 0, 48));
    }
}
