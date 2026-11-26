<?php
declare(strict_types=1);

namespace TinyCat\Social\Site;

use TinyCat\Http\Response;
use TinyCat\Social\Posts\Resource as Posts;
use TinyCat\Core\Ui\Components;
use TinyCat\Support\Localization;
use TinyCat\Social\Profiles\Profiles as Users;

/** Shared public page resources and SSR composition; APIs contain no markup. */
final class PublicPages
{
    /** @return list<array{string, string, callable, callable, list<string>}> */
    private static function routes(): array
    {
        return [
            ['/social', 'home', static fn () => \TinyCat\Social\Posts\Pages::home(), static fn () => \TinyCat\Social\Posts\Pages::homeData(), ['feed', 'compact', 'media']],
            ['/status/{status_id:[0-9]+}', 'status', static fn (string $status_id) => \TinyCat\Social\Posts\Pages::detail((int) $status_id), static fn (string $status_id) => \TinyCat\Social\Posts\Pages::detailData((int) $status_id), ['compact']],
            ['/author/{author_id:[0-9]+}', 'author', static fn (string $author_id) => \TinyCat\Social\Posts\AuthorPages::author((int) $author_id), static fn (string $author_id) => \TinyCat\Social\Posts\AuthorPages::authorData((int) $author_id), ['feed', 'compact', 'media']],
            ['/tag/{tag}', 'tag', static fn (string $tag) => \TinyCat\Social\Posts\Pages::tag($tag), static fn (string $tag) => \TinyCat\Social\Posts\Pages::tagData($tag), ['feed', 'compact', 'media']],
        ];
    }

    /** @return list<\TinyCat\Core\Modules\LocalizedRoute> */
    public static function localizedRoutes(): array
    {
        return array_map(static fn (array $route): \TinyCat\Core\Modules\LocalizedRoute => new \TinyCat\Core\Modules\LocalizedRoute('social.' . $route[1], \TinyCat\Http\Router::pattern($route[0]), $route[4]), self::routes());
    }

    public static function register(): void
    {
        foreach (self::routes() as [$path, $kind, $handler, $load, $query]) {
            \TinyCat\Http\Router::page('tinycat.social', $path, $handler, static function (string ...$params) use ($kind, $load): ?array {
                $data = $load(...$params);
                return $data !== null ? self::resource($kind, $data) : null;
            }, $query);
        }
        \TinyCat\Http\Router::route('GET', '/author', static fn () => \TinyCat\Social\Posts\AuthorPages::author(), publicWeb: true);
    }

    /** @return array<string, mixed> */
    public static function find(string $path): array
    {
        if (\TinyCat\Http\LocaleRouting::split($path)['locale'] !== null) { $path = \TinyCat\Http\LocaleRouting::prepare($path); \TinyCat\Http\LocaleRouting::apply(); }
        $resource = \TinyCat\Http\Router::pageResource('tinycat.social', $path);
        if ($resource === null) { Response::error(Localization::t('public.not_found_title'), 404, 'not_found'); }
        header('Cache-Control: no-store, private');
        return $resource;
    }

    /** @param array<string, mixed> $data */
    public static function render(string $kind, array $data): void
    {
        $resource = self::resource($kind, $data);
        \TinyCat\Core\Ui\Page::render($data['page'] + ['public' => true, 'surface' => 'public', 'privacyFooter' => false], Components::render('site/public-page', $resource + ['csrf' => \TinyCat\Core\Security\Csrf::token()]), $resource['document']);
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
        $feedId = $context['feedId'] ?? $home['feed_id'] ?? ($kind === 'author' ? 'status-feed-author-' . (int) ($author['id'] ?? 0) : 'status-feed-tag-' . \TinyCat\Core\Text\Text::slug((string) ($context['tag'] ?? '')));
        $editor = $context['editor'] ?? [];
        $url = $kind === 'home' ? (string) ($home['current_feed_url'] ?? '/') : (string) $meta['url'];
        $compactQuery = !empty($context['compact']) ? ['compact' => '1'] : [];
        $query = $compactQuery;
        $item = $context['item'] ?? null;
        $archiveType = (string) ($context['archiveType'] ?? '');
        $archiveOptions = [];
        if (in_array($kind, ['home', 'tag', 'author'], true)) {
            if (in_array($kind, ['tag', 'author'], true) && $archiveType !== '') { $query['media'] = $archiveType; }
            $filterBase = $kind === 'home' && ($context['feed'] ?? '') === 'following' ? \TinyCat\Social\Posts\Feed::homePath() . '?feed=following' : (string) ($context['archiveBaseUrl'] ?? $page['current']);
            $filterBase = \TinyCat\Core\Links\Tracking::append($filterBase, $compactQuery);
            foreach (['' => 'all', 'images' => 'images', 'video' => 'video', 'audio' => 'audio', 'links' => 'links', 'text' => 'text'] as $type => $label) {
                $archiveOptions[] = ['url' => \TinyCat\Http\LocaleRouting::url($filterBase . ($type !== '' ? (str_contains($filterBase, '?') ? '&' : '?') . 'media=' . $type : '')),
                    'type' => $label, 'active' => $type === $archiveType];
            }
        }
        $url = \TinyCat\Core\Links\Tracking::append($url, $query);
        return [
            'constraints' => Posts::constraints(),
            'routes' => \TinyCat\Http\Router::pageDefinitions('tinycat.social'), 'version' => \TinyCat\Support\Version::CURRENT, 'kind' => $kind, 'url' => \TinyCat\Http\LocaleRouting::url($url), 'locale' => Localization::locale(), 'viewer' => $viewer !== null ? Users::resource($viewer) : null,
            'document' => \TinyCat\Core\Ui\Metadata::document($page),
            'tag' => (string) ($context['tag'] ?? ''), 'compact' => (bool) ($context['compact'] ?? false),
            'feed' => (string) ($context['feed'] ?? 'all'), 'feed_id' => $feedId,
            'login_required' => (bool) ($home['following_login_required'] ?? false),
            'items' => Posts::posts($items, $viewer),
            'item' => $item !== null ? Posts::post($item, $viewer) : null,
            'comments' => Posts::comments($context['comments'] ?? [], $viewer),
            'comments_next_url' => (string) ($context['commentsNextUrl'] ?? ''),
            'next_url' => (string) ($more['next_url'] ?? ''),
            'sidebar_url' => '/api/site/sidebar' . (!empty($context['tag']) ? '?tag=' . rawurlencode($context['tag']) : ''),
            'sidebar' => in_array($kind, ['home', 'tag', 'status'], true) && empty($context['compact']),
            'author' => $author !== null ? Users::resource($author) : null,
            'bio' => (string) ($context['bio'] ?? ''), 'member_since' => !empty($context['memberSince']) ? \TinyCat\Support\Dates::dateIso($context['memberSince']) : '',
            'follow_counts' => $context['followCounts'] ?? [], 'activity' => $context['activityStats'] ?? [],
            'can_edit' => (bool) ($context['canEditProfile'] ?? false), 'can_follow' => (bool) ($context['canFollow'] ?? false), 'following' => (bool) ($context['isFollowing'] ?? false),
            'muted_until' => (($context['canSeeMute'] ?? false) || ($context['userIsMuted'] ?? false)) ? (string) ($context['mutedUntil'] ?? $context['userMutedUntil'] ?? '') : '',
            'composer' => $viewer !== null && empty($context['userIsMuted']) && ($kind === 'home' || (!empty($context['canPost']) && empty($context['mutedUntil']))),
            'viewer_profile_id' => $viewer !== null ? \TinyCat\Core\Identity\Views::accountEditorId((int) $viewer['id']) : '',
            'viewer_profile_url' => $viewer !== null ? \TinyCat\Core\Identity\Views::accountEditorUrl((int) $viewer['id'], 'email') : '',
            'email_missing' => $viewer !== null && trim((string) ($viewer['email'] ?? '')) === '',
            'images_enabled' => (bool) ($editor['image']['enabled'] ?? false),
            'profile_edit_url' => (string) ($context['authorProfileEditModalUrl'] ?? ''), 'profile_edit_id' => (string) ($context['authorProfileEditModalId'] ?? ''),
            'avatar_edit_url' => (string) ($context['authorAvatarEditModalUrl'] ?? ''), 'avatar_edit_id' => (string) ($context['authorAvatarEditModalId'] ?? ''),
            'following_url' => (string) ($context['authorFollowingModalUrl'] ?? ''), 'following_id' => (string) ($context['authorFollowingModalId'] ?? ''),
            'all_feed_url' => \TinyCat\Http\LocaleRouting::url(\TinyCat\Core\Links\Tracking::append(\TinyCat\Social\Posts\Feed::homePath() . ($archiveType !== '' ? '?media=' . $archiveType : ''), $compactQuery)),
            'following_feed_url' => \TinyCat\Http\LocaleRouting::url(\TinyCat\Core\Links\Tracking::append(\TinyCat\Social\Posts\Feed::homePath() . '?feed=following' . ($archiveType !== '' ? '&media=' . $archiveType : ''), $compactQuery)),
            'clear_filter_url' => \TinyCat\Http\LocaleRouting::url(\TinyCat\Core\Links\Tracking::append($kind === 'home' && ($context['feed'] ?? '') === 'following' ? \TinyCat\Social\Posts\Feed::homePath() . '?feed=following' : (string) ($context['archiveBaseUrl'] ?? $page['current']), $compactQuery)),
            'archive_options' => $archiveOptions,
            'archive_type' => $archiveType,
            'rss_url' => (string) ($context['authorFeedUrl'] ?? $context['tagFeedUrl'] ?? ''),
            'login_url' => \TinyCat\Core\Identity\Login::auth_url_with_next('/login', $url),
            'login_modal_url' => \TinyCat\Core\Identity\Login::auth_modal_url('login', $url),
        ];
    }
}
