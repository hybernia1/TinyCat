<?php
declare(strict_types=1);

namespace TinyCat\Site;

use TinyCat\Http\Request;
use TinyCat\Http\Response;
use TinyCat\Status\Resource as Posts;
use TinyCat\Support\Components;
use TinyCat\Support\Localization;
use TinyCat\Users\Resource as Users;

/** Shared public page resources and SSR composition; APIs contain no markup. */
final class PublicPages
{
    /** @return array<string, mixed> */
    public static function find(string $path): array
    {
        if (LocaleRouting::split($path)['locale'] !== null) { $path = LocaleRouting::prepare($path); LocaleRouting::apply(); }
        $kind = ''; $data = null;
        if ($path === '/') { $kind = 'home'; $data = \TinyCat\Status\Pages::homeData(); }
        elseif ($path === '/search') { $kind = 'search'; $data = \TinyCat\Search\Pages::data(); }
        elseif (preg_match('~^/status/([1-9][0-9]*)$~D', $path, $match)) { $kind = 'status'; $data = \TinyCat\Status\Pages::detailData((int) $match[1]); }
        elseif (preg_match('~^/author/([1-9][0-9]*)$~D', $path, $match)) { $kind = 'author'; $data = \TinyCat\Users\Pages::authorData((int) $match[1]); }
        elseif (preg_match('~^/tag/([^/]+)$~D', $path, $match)) { $kind = 'tag'; $data = \TinyCat\Status\Pages::tagData($match[1]); }
        if ($data === null) { Response::error(Localization::t('public.not_found_title'), 404, 'not_found'); }
        header('Cache-Control: no-store, private');
        return self::resource($kind, $data);
    }

    /** @param array<string, mixed> $data */
    public static function render(string $kind, array $data): void
    {
        $resource = self::resource($kind, $data);
        Layout::render($data['page'], Components::render('site/public-page', $resource + ['csrf' => \TinyCat\Security\Csrf::token()]));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function resource(string $kind, array $data): array
    {
        $context = $data['context']; $page = $data['page']; $meta = $page['meta'];
        $viewer = $context['authUser'] ?? null;
        $home = $context['publicHomeFeedData'] ?? [];
        $items = $context['statusItems'] ?? $home['items'] ?? [];
        $more = $context['feedMore'] ?? $home['feed_more'] ?? [];
        $author = $context['author'] ?? null;
        $feedId = $context['feedId'] ?? $home['feed_id'] ?? ($kind === 'author' ? 'status-feed-author-' . (int) ($author['id'] ?? 0) : 'status-feed-tag-' . \TinyCat\Support\Text::slug((string) ($context['tag'] ?? '')));
        $editor = $context['editor'] ?? [];
        $url = $kind === 'home' ? (string) ($home['current_feed_url'] ?? '/') : (string) $meta['url'] . (!empty($context['compact']) ? '?compact=1' : '');
        $item = $context['item'] ?? null;
        return [
            'version' => \TinyCat\Support\Version::CURRENT, 'kind' => $kind, 'url' => LocaleRouting::url($url), 'locale' => Localization::locale(), 'viewer' => $viewer !== null ? Users::profile($viewer) : null,
            'document' => [
                'title' => $page['title'] === Presentation::site_name() ? (string) $page['title'] : (string) $page['title'] . ' | ' . Presentation::site_name(),
                'description' => Presentation::meta_text((string) ($meta['description'] ?? ''), 180),
                'url' => Presentation::absolute_url(LocaleRouting::canonical((string) $meta['url'])), 'type' => (string) ($meta['type'] ?? 'website'),
                'image' => !empty($meta['image']) ? Presentation::absolute_url((string) $meta['image']) : '',
                'image_type' => (string) ($meta['image_type'] ?? ''), 'image_width' => (int) ($meta['image_width'] ?? 0), 'image_height' => (int) ($meta['image_height'] ?? 0), 'image_alt' => (string) ($meta['image_alt'] ?? $page['title']),
                'rss' => !empty($meta['rss']) ? Presentation::absolute_url((string) $meta['rss']) : '',
                'robots' => (string) ($meta['robots'] ?? ''), 'jsonld' => isset($meta['jsonld']) && is_array($meta['jsonld']) ? LocaleRouting::schema($meta['jsonld']) : null,
                'published_time' => !empty($meta['published_time']) ? \TinyCat\Support\Dates::dateIso((string) $meta['published_time']) : '', 'author' => (string) ($meta['author'] ?? ''),
            ],
            'tag' => (string) ($context['tag'] ?? ''), 'compact' => (bool) ($context['compact'] ?? false),
            'feed' => (string) ($context['feed'] ?? 'all'), 'feed_id' => $feedId,
            'login_required' => (bool) ($home['following_login_required'] ?? false),
            'items' => Posts::posts($items, $viewer),
            'item' => $item !== null ? Posts::post($item, $viewer) : null,
            'comments' => Posts::comments($context['comments'] ?? [], $viewer),
            'next_url' => (int) ($more['loaded'] ?? 0) >= (int) ($more['limit'] ?? 1) ? (string) ($more['next_url'] ?? '') : '',
            'sidebar_url' => '/api/site/sidebar' . (!empty($context['tag']) ? '?tag=' . rawurlencode($context['tag']) : ''),
            'sidebar' => in_array($kind, ['home', 'tag', 'status', 'search'], true) && empty($context['compact']),
            'author' => $author !== null ? Users::profile($author) : null,
            'bio' => (string) ($context['bio'] ?? ''), 'member_since' => !empty($context['memberSince']) ? \TinyCat\Support\Dates::dateIso($context['memberSince']) : '',
            'follow_counts' => $context['followCounts'] ?? [], 'activity' => $context['activityStats'] ?? [],
            'can_edit' => (bool) ($context['canEditProfile'] ?? false), 'can_follow' => (bool) ($context['canFollow'] ?? false), 'following' => (bool) ($context['isFollowing'] ?? false),
            'muted_until' => (($context['canSeeMute'] ?? false) || ($context['userIsMuted'] ?? false)) ? (string) ($context['mutedUntil'] ?? $context['userMutedUntil'] ?? '') : '',
            'composer' => $viewer !== null && empty($context['userIsMuted']) && ($kind === 'home' || (!empty($context['canPost']) && empty($context['mutedUntil']))),
            'viewer_profile_id' => $viewer !== null ? \TinyCat\Users\Profiles::author_profile_edit_modal_id((int) $viewer['id']) : '',
            'viewer_profile_url' => $viewer !== null ? \TinyCat\Users\Profiles::author_profile_edit_modal_url((int) $viewer['id'], 'email') : '',
            'email_missing' => $viewer !== null && trim((string) ($viewer['email'] ?? '')) === '',
            'images_enabled' => (bool) ($editor['image']['enabled'] ?? false),
            'profile_edit_url' => (string) ($context['authorProfileEditModalUrl'] ?? ''), 'profile_edit_id' => (string) ($context['authorProfileEditModalId'] ?? ''),
            'avatar_edit_url' => (string) ($context['authorAvatarEditModalUrl'] ?? ''), 'avatar_edit_id' => (string) ($context['authorAvatarEditModalId'] ?? ''),
            'following_url' => (string) ($context['authorFollowingModalUrl'] ?? ''), 'following_id' => (string) ($context['authorFollowingModalId'] ?? ''),
            'rss_url' => (string) ($context['authorFeedUrl'] ?? $context['tagFeedUrl'] ?? ''),
            'login_url' => \TinyCat\Users\Login::auth_url_with_next('/login', $url),
            'login_modal_url' => \TinyCat\Users\Login::auth_modal_url('login', $url),
        ] + ($kind === 'search' ? ['search' => $context['search']] : []);
    }
}
