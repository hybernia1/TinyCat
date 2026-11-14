<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Core\Identity\Avatars;

use TinyCat\Core\Identity\Authentication;

use TinyCat\Http\Request;
use TinyCat\Http\Web;
use TinyCat\Social\Moderation\Restrictions;
use TinyCat\Social\Presentation\Pages as SitePages;
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
        \TinyCat\Social\Presentation\PublicPages::render('author', $data);
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
        $avatarUrl = Avatars::user_avatar_url($author);
        $memberSince = (string) ($author['created_at'] ?? '');
        $current = Profiles::author_url($authorId);
        $archiveType = Feed::archive_type((string) Web::get('media', ''));

        $statusLimit = Feed::public_status_initial_page_limit();
        $nextStatusLimit = Feed::public_status_page_limit();
        $pageUrl = $current;
        $statusItems = Feed::public_status_items_by_author_cursor($authorId, $statusLimit, '', 0, $archiveType);
        $authUser = Authentication::auth();
        $statusItems = StatusViewData::status_prepare_items_view($statusItems, $authUser);
        $feedMore = StatusViewData::status_feed_more_view_data(
            'status-feed-author-' . $authorId,
            'author',
            $statusItems,
            $statusLimit,
            ['author_id' => $authorId, 'media' => $archiveType],
            $nextStatusLimit
        );
        $canPost = $authUser !== null && (int) ($authUser['id'] ?? 0) === $authorId;
        $editor = $canPost ? StatusViewData::status_editor_view_data() : [];
        $canEditProfile = Profiles::user_can_edit_profile($author, $authUser);
        $canSeeMute = $authUser !== null && ($canPost || Profiles::user_is_admin($authUser));
        $mutedUntil = Restrictions::user_muted_until($author);
        $canFollow = $authUser !== null && (int) ($authUser['id'] ?? 0) !== $authorId;
        $isFollowing = $canFollow && Following::author_is_followed((int) ($authUser['id'] ?? 0), $authorId);
        $followCounts = Following::author_follow_counts($authorId);
        $activityStats = Repository::author_activity_stats($authorId);
        $publicPostCount = (int) ($activityStats['posts'] ?? 0);
        $authorEntityId = Presentation::absolute_url($current) . '#author';
        $authorStructuredData = [
            '@context' => 'https://schema.org',
            '@type' => 'ProfilePage',
            '@id' => Presentation::absolute_url($pageUrl),
            'url' => Presentation::absolute_url($pageUrl),
            'dateCreated' => $memberSince !== '' ? Dates::dateTime($memberSince, DATE_ATOM) : null,
            'dateModified' => (string) ($author['updated_at'] ?? '') !== '' ? Dates::dateTime((string) $author['updated_at'], DATE_ATOM) : null,
            'mainEntity' => [
                '@type' => Roles::profileSchemaType((string) ($author['role'] ?? '')),
                '@id' => $authorEntityId,
                'url' => Presentation::absolute_url($current),
                'name' => $authorName,
                'alternateName' => (string) ($author['username'] ?? ''),
                'identifier' => (string) $authorId,
                'description' => $bio !== '' ? $bio : null,
                'image' => $avatarUrl !== '' ? Presentation::absolute_url($avatarUrl) : null,
            ],
            'hasPart' => array_map(\TinyCat\Social\Posts\StructuredData::post(...), array_values($statusItems)),
        ];
        $authorStructuredData = array_filter($authorStructuredData, static fn (mixed $value): bool => $value !== null && $value !== []);
        $authorStructuredData['mainEntity'] = array_filter($authorStructuredData['mainEntity']);

        $authorProfileEditModalId = Profiles::author_profile_edit_modal_id($authorId);
        $authorProfileEditModalUrl = Profiles::author_profile_edit_modal_url($authorId, 'bio');
        $authorAvatarEditModalId = Avatars::author_avatar_edit_modal_id($authorId);
        $authorAvatarEditModalUrl = Avatars::author_avatar_edit_modal_url($authorId);
        $authorFollowingModalId = Following::author_following_modal_id($authorId);
        $authorFollowingModalUrl = Following::author_following_modal_url($authorId);
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
                'rss' => Profiles::author_feed_url($authorId, $archiveType),
                'robots' => $publicPostCount > 0 ? '' : 'noindex,follow',
                'jsonld' => $authorStructuredData,
            ],
        ], 'context' => compact('author', 'authUser', 'statusItems', 'feedMore', 'editor', 'bio', 'memberSince', 'followCounts', 'activityStats', 'canEditProfile', 'canFollow', 'isFollowing', 'canPost', 'canSeeMute', 'mutedUntil', 'authorProfileEditModalId', 'authorProfileEditModalUrl', 'authorAvatarEditModalId', 'authorAvatarEditModalUrl', 'authorFollowingModalId', 'authorFollowingModalUrl', 'authorFeedUrl', 'archiveType')];
    }
}
