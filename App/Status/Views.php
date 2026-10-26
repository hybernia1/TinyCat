<?php
declare(strict_types=1);

namespace TinyCat\Status;

use TinyCat\Support\Components;

use TinyCat\Support\View;
use TinyCat\Users\Login;
use TinyCat\Users\Profiles;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{
    /** @param array<mixed> $data */
    public static function feedMore(array $data): string
    {
        if ((int) ($data['loaded'] ?? 0) < (int) ($data['limit'] ?? 0)) { return ''; }
        return Components::render('status/feed-more', ['target' => '#' . (string) ($data['feed_id'] ?? ''), 'url' => (string) ($data['next_url'] ?? '')]);
    }

    /** @param array<mixed> $data */
    public static function card(array $data): string
    {
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $user = is_array($data['user'] ?? null) ? $data['user'] : null;
        if ((int) ($item['id'] ?? 0) < 1) { return ''; }
        return Components::render('status/card', [
            'item' => Resource::post($item, $user),
            'anchor' => ($data['anchor'] ?? null) !== '',
            'open_modal' => (bool) ($data['open_modal'] ?? true),
            'action' => (string) ($data['action'] ?? '/'),
        ]);
    }

    /** @param array<mixed> $data */
    public static function actions(array $data = []): string
    {
        $item = (array) ($data['item'] ?? []);
        $user = is_array($data['user'] ?? null) ? $data['user'] : null;
        return Components::render('status/actions', ['item' => Resource::post($item, $user), 'open_modal' => (bool) ($data['open_comments_modal'] ?? true)]);
    }

    /** @param array<mixed> $data */
    public static function commentForm(array $data = []): string
    {
        $user = (array) ($data['user'] ?? []);
        if ($user === [] || (int) ($data['content_id'] ?? 0) < 1) { return ''; }
        return Components::render('status/comment-form', $data + ['viewer' => \TinyCat\Users\Resource::profile($user), 'parent_id' => 0, 'mention' => '']);
    }

    /** @param array<mixed> $data */
    public static function commentItem(array $data = []): string
    {
        $user = is_array($data['user'] ?? null) ? $data['user'] : null;
        $comment = (array) ($data['comment'] ?? []);
        if ((int) ($comment['id'] ?? 0) < 1) { return ''; }
        return Components::render('status/comment', [
            'item' => Resource::comment($comment, $user),
            'viewer' => $user !== null ? \TinyCat\Users\Resource::profile($user) : null,
            'context' => (string) ($data['context'] ?? ''),
            'show_replies' => (bool) ($data['show_replies'] ?? true),
            'show_reply_form' => (bool) ($data['show_reply_form'] ?? true),
            'preview' => !($data['show_replies'] ?? true) && !($data['show_reply_form'] ?? true),
        ]);
    }

    /** @param array<mixed> $data */
    public static function commentsThread(array $data = []): string
    {
        $user = is_array($data['user'] ?? null) ? $data['user'] : null;
        return Components::render('status/thread', [
            'item' => Resource::post((array) ($data['item'] ?? []), $user),
            'comments' => Resource::comments((array) ($data['comments'] ?? []), $user),
            'viewer' => $user !== null ? \TinyCat\Users\Resource::profile($user) : null,
            'context' => (string) ($data['context'] ?? ''),
        ]);
    }

    /** @param array<mixed> $data */
    public static function composerBody(array $data = []): string
    {
        $editor = (array) ($data['editor'] ?? []);
        return Components::render('status/composer-body', [
            'viewer' => \TinyCat\Users\Resource::profile((array) ($data['user'] ?? [])),
            'value' => (string) ($editor['item']['body'] ?? ''),
            'image' => (array) ($editor['image'] ?? []),
            'enabled' => (bool) ($editor['image']['enabled'] ?? false),
            'submit_name' => (string) ($data['submit_name'] ?? ''),
            'submit_value' => (string) ($data['submit_value'] ?? '1'),
            'submit_label' => (string) ($data['submit_label'] ?? ''),
            'submit_icon' => (string) ($data['submit_icon'] ?? 'save'),
        ]);
    }

    /** @param array<mixed> $data */
    public static function composer(array $data = []): string
    {
        $editor = $data['editor'] ?? null;
        $user = $data['user'] ?? null;
        $user = is_array($user ?? null) ? $user : [];
        $editor = is_array($editor ?? null) ? $editor : [];
        $prepared = [];
        $prepared['status_api_url'] = Posts::status_api_url('create');
        $prepared['author_profile_edit_modal_url'] = Profiles::author_profile_edit_modal_url((int) ($user['id'] ?? 0), 'email');
        $prepared['author_profile_edit_modal_id'] = Profiles::author_profile_edit_modal_id((int) ($user['id'] ?? 0));
        return View::part('Status/composer', array_replace($data, compact('editor', 'user', 'prepared')));
    }

    /** @param array<mixed> $data */
    public static function homeFeed(array $data = []): string
    {
        $current_feed_url = $data['current_feed_url'] ?? null;
        $feed = $data['feed'] ?? null;
        $feed_id = $data['feed_id'] ?? null;
        $feed_more = $data['feed_more'] ?? null;
        $following_login_required = $data['following_login_required'] ?? null;
        $items = $data['items'] ?? null;
        $limit = $data['limit'] ?? null;
        $user = $data['user'] ?? null;
        $feed = (string) ($feed ?? 'all');
        $feed = $feed === 'following' ? 'following' : 'all';
        $user = is_array($user ?? null) ? $user : null;
        $currentFeedUrl = (string) ($current_feed_url ?? ($feed === 'following' ? '/?feed=following' : '/'));
        $followingLoginRequired = (bool) ($following_login_required ?? false);
        $limit = max(0, (int) ($limit ?? 0));
        $items = is_array($items ?? null) ? $items : [];
        $feedId = (string) ($feed_id ?? ('status-feed-' . $feed));
        $feedMore = is_array($feed_more ?? null) ? $feed_more : [];
        $prepared = [];
        $prepared['status_login_url'] = Actions::status_login_url('', $currentFeedUrl);
        $loginUrl = $prepared['status_login_url'];
        $prepared['auth_modal_url'] = Login::auth_modal_url('login', Login::auth_next_from_url($loginUrl));
        $prepared['auth_modal_id'] = Login::auth_modal_id();
        $prepared['following_feed_url'] = Posts::public_home_feed_api_url('following', true);
        $prepared['all_feed_url'] = Posts::public_home_feed_api_url('all', true);
        return View::part('Status/home-feed', array_replace($data, compact('currentFeedUrl', 'feed', 'feedId', 'feedMore', 'followingLoginRequired', 'items', 'user', 'prepared')));
    }
}
