<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Core\Identity\Avatars;

use TinyCat\Core\Identity\Authentication;

use TinyCat\Http\Request;
use TinyCat\Http\Web;
use TinyCat\Social\Moderation\Restrictions;
use TinyCat\Core\Ui\Site as Presentation;
use TinyCat\Social\Posts\Feed;
use TinyCat\Social\Posts\ViewData as StatusViewData;
use TinyCat\Support\Dates;
use TinyCat\Support\Localization;

use TinyCat\Social\Profiles\Repository;
use TinyCat\Social\Profiles\Profiles;
use TinyCat\Social\Profiles\Following;
use TinyCat\Social\Profiles\Roles;

final class AuthorPages
{
    public static function author(?int $requestedId = null): void
    {
        $data = self::authorData($requestedId);
        if ($data === null) { \TinyCat\Core\Runtime\Errors::respond(404); return; }
        if (Request::current()->method() === 'GET' && (int) Web::get('page', 0) > 1) { Web::redirect($data['page']['current'], 301); }
        \TinyCat\Social\Site\PublicPages::render('author', $data);
    }

    /** @return array<string, mixed>|null */
    public static function authorData(?int $requestedId = null): ?array
    {
        $author = Repository::public_author_find(($requestedId ?? (int) Web::get('id', 0)));

        if ($author === null) {
            return null;
        }

        $authorId = (int) $author['id'];
        $authorName = Profiles::user_display_name($author);
        $bio = trim((string) ($author['bio'] ?? ''));
        $avatarUrl = \TinyCat\Social\Profiles\Profiles::avatarUrl($author);
        $memberSince = (string) ($author['created_at'] ?? '');
        $current = Profiles::author_url($authorId);
        $archiveType = Archive::type((string) Web::get('media', ''));
        $compact = Web::get('compact', '') === '1';

        $statusLimit = Feed::public_status_initial_page_limit();
        $nextStatusLimit = Feed::public_status_page_limit();
        $pageUrl = $current;
        $statusItems = Feed::public_status_items_by_author_cursor($authorId, $statusLimit, '', 0, $archiveType, $selection);
        $authUser = Authentication::auth();
        $statusItems = StatusViewData::status_prepare_items_view($statusItems, $authUser);
        $feedMore = StatusViewData::status_feed_more_view_data(
            'status-feed-author-' . $authorId,
            'author',
            $statusItems,
            $statusLimit,
            ['author_id' => $authorId, 'media' => $archiveType],
            $nextStatusLimit,
            $selection
        );
        $canPost = $authUser !== null && (int) ($authUser['id'] ?? 0) === $authorId;
        $editor = $canPost ? StatusViewData::status_editor_view_data() : [];
        $canEditProfile = \TinyCat\Core\Identity\Profile::canEdit($author, $authUser);
        $canSeeMute = $authUser !== null && ($canPost || \TinyCat\Core\Identity\Roles::isAdmin($authUser));
        $mutedUntil = Restrictions::user_muted_until($author);
        $canFollow = $authUser !== null && (int) ($authUser['id'] ?? 0) !== $authorId;
        $isFollowing = $canFollow && Following::author_is_followed((int) ($authUser['id'] ?? 0), $authorId);
        $followCounts = Following::author_follow_counts($authorId);
        $activityStats = AuthorStatistics::author_activity_stats($authorId);
        $publicPostCount = (int) ($activityStats['posts'] ?? 0);
        $authorEntityId = \TinyCat\Http\LocalUrls::absolute($current) . '#author';
        $authorStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            '@id' => \TinyCat\Http\LocalUrls::absolute($pageUrl),
            'url' => \TinyCat\Http\LocalUrls::absolute($pageUrl),
            'dateCreated' => $memberSince !== '' ? Dates::dateTime($memberSince, DATE_ATOM) : null,
            'dateModified' => (string) ($author['updated_at'] ?? '') !== '' ? Dates::dateTime((string) $author['updated_at'], DATE_ATOM) : null,
            'mainEntity' => [
                '@type' => Roles::profileSchemaType((string) ($author['role'] ?? '')),
                '@id' => $authorEntityId,
                'url' => \TinyCat\Http\LocalUrls::absolute($current),
                'name' => $authorName,
                'alternateName' => (string) ($author['username'] ?? ''),
                'identifier' => (string) $authorId,
                'description' => $bio !== '' ? $bio : null,
                'image' => $avatarUrl !== '' ? \TinyCat\Http\LocalUrls::absolute($avatarUrl) : null,
            ],
            'hasPart' => array_map(\TinyCat\Social\Posts\StructuredData::post(...), array_values($statusItems)),
        ];
        $authorStructuredData = array_filter($authorStructuredData, static fn (mixed $value): bool => $value !== null && $value !== []);
        $authorStructuredData['mainEntity'] = array_filter($authorStructuredData['mainEntity']);

        $authorProfileEditModalId = \TinyCat\Core\Identity\Views::accountEditorId($authorId);
        $authorProfileEditModalUrl = \TinyCat\Core\Identity\Views::accountEditorUrl($authorId, 'bio');
        $authorAvatarEditModalId = Avatars::avatarEditModalId($authorId);
        $authorAvatarEditModalUrl = Avatars::avatarEditModalUrl($authorId);
        $authorFollowingModalId = Following::author_following_modal_id($authorId);
        $authorFollowingModalUrl = Following::author_following_api_url($authorId);
        $authorFeedUrl = Profiles::author_feed_url($authorId, $archiveType);
        return ['page' => [
            'title' => Localization::t('public.author_archive_title', ['author' => $authorName]),
            'current' => $current,
            'meta' => [
                'description' => $bio !== ''
                    ? $bio
                    : Localization::t('public.author_meta', ['author' => $authorName]),
                'url' => $pageUrl,
                'image' => $avatarUrl ?: Presentation::site_meta_image_url(),
                'type' => 'profile',
                'rss' => $authorFeedUrl,
                'robots' => $publicPostCount > 0 ? '' : 'noindex,follow',
                'jsonld' => $authorStructuredData,
            ],
        ], 'context' => compact('author', 'authUser', 'statusItems', 'feedMore', 'editor', 'bio', 'memberSince', 'followCounts', 'activityStats', 'canEditProfile', 'canFollow', 'isFollowing', 'canPost', 'canSeeMute', 'mutedUntil', 'authorProfileEditModalId', 'authorProfileEditModalUrl', 'authorAvatarEditModalId', 'authorAvatarEditModalUrl', 'authorFollowingModalId', 'authorFollowingModalUrl', 'authorFeedUrl', 'archiveType', 'compact')];
    }
}
