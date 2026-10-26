<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class ViewData
{
    /**
     * @param array<mixed> $item
     */
    public static function status_meta_title(array $item, int $limit = 110): string
    {
        $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? $item['username'] ?? ''));
        $body = \TinyCat\Status\Links::status_strip_external_urls((string) ($item['body'] ?? ''));
        $body = \TinyCat\Site\Presentation::meta_text($body, 90);

        if ($body !== '') {
            $title = $author !== '' ? $author . ': ' . $body : $body;

            return \TinyCat\Site\Presentation::meta_text($title, $limit);
        }

        $linkTitle = \TinyCat\Status\ViewData::status_meta_link_title($item, 90);

        if ($linkTitle !== '') {
            $title = $author !== '' ? $author . ': ' . $linkTitle : $linkTitle;

            return \TinyCat\Site\Presentation::meta_text($title, $limit);
        }

        if ($author !== '') {
            return \TinyCat\Status\Images::status_image_url($item) !== ''
                ? \TinyCat\Support\Localization::t('public.status_title_image_by', ['author' => $author])
                : \TinyCat\Support\Localization::t('public.status_title_by', ['author' => $author]);
        }

        return \TinyCat\Support\Localization::t('public.status_title');
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_link_title(array $item, int $limit = 90): string
    {
        $contentId = (int) ($item['id'] ?? $item['content_id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        foreach (\TinyCat\Status\Links::status_links_for_content($contentId) as $link) {
            $link = (array) $link;

            if (\TinyCat\Status\Links::status_link_is_internal($link)) {
                continue;
            }

            $title = \TinyCat\Site\Presentation::meta_text((string) ($link['title'] ?? ''), $limit);

            if ($title !== '' && !\TinyCat\Status\Links::status_link_title_is_placeholder($title)) {
                return $title;
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_link_image(array $item): string
    {
        $contentId = (int) ($item['id'] ?? $item['content_id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        foreach (\TinyCat\Status\Links::status_links_for_content($contentId) as $link) {
            $link = (array) $link;

            if (\TinyCat\Status\Links::status_link_is_internal($link)) {
                continue;
            }

            $thumbnail = \TinyCat\Status\Links::status_video_thumbnail_sources($link);
            $imageUrl = trim((string) ($thumbnail['fallback'] ?? ''));

            if ($imageUrl !== '') {
                return \TinyCat\Site\Presentation::absolute_url($imageUrl);
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_description(array $item): string
    {
        $body = \TinyCat\Status\Links::status_strip_external_urls((string) ($item['body'] ?? ''));
        $body = \TinyCat\Site\Presentation::meta_text($body, 180);

        if ($body !== '') {
            return $body;
        }

        $imageAlt = \TinyCat\Status\Images::status_image_alt_text($item);

        if (\TinyCat\Status\Images::status_image_url($item) !== '' && $imageAlt !== '') {
            return $imageAlt;
        }

        return \TinyCat\Support\Localization::t('public.status_meta_description', [
            'author' => (string) ($item['author_name'] ?? \TinyCat\Site\Presentation::site_name()),
        ]);
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_meta_image(array $item): string
    {
        return \TinyCat\Status\Images::status_image_url($item) ?: \TinyCat\Status\ViewData::status_meta_link_image($item) ?: \TinyCat\Users\Avatars::user_avatar_url($item) ?: \TinyCat\Site\Presentation::site_meta_image_url();
    }

    /**
     * @param array<mixed> $item
     * @return array<mixed>
     */
    public static function status_accessible_identity(array $item): array
    {
        $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? $item['username'] ?? ''));
        $body = \TinyCat\Status\Links::status_strip_external_urls((string) ($item['body'] ?? ''));
        $excerpt = \TinyCat\Site\Presentation::meta_text($body, 80);

        return [
            'author' => $author,
            'excerpt' => $excerpt,
            'id' => max(0, (int) ($item['id'] ?? $item['content_id'] ?? 0)),
        ];
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_permalink_label(array $item): string
    {
        ['author' => $author, 'excerpt' => $excerpt, 'id' => $id] = \TinyCat\Status\ViewData::status_accessible_identity($item);

        if ($author !== '' && $excerpt !== '') {
            return \TinyCat\Support\Localization::t('account.status_permalink_context', [
                'author' => $author,
                'excerpt' => $excerpt,
            ]);
        }

        if ($author !== '') {
            return \TinyCat\Support\Localization::t('account.status_permalink_author', ['author' => $author]);
        }

        if ($excerpt !== '') {
            return \TinyCat\Support\Localization::t('account.status_permalink_excerpt', ['excerpt' => $excerpt]);
        }

        return \TinyCat\Support\Localization::t('account.status_permalink_for', ['id' => $id]);
    }

    /**
     * @param array<mixed> $item
     */
    public static function status_accessible_context(array $item, bool $comment = false): string
    {
        ['author' => $author, 'excerpt' => $excerpt, 'id' => $id] = \TinyCat\Status\ViewData::status_accessible_identity($item);
        $prefix = $comment ? 'account.status_comment_context' : 'account.status_context';

        if ($author !== '' && $excerpt !== '') {
            return \TinyCat\Support\Localization::t($prefix . '_author_excerpt', [
                'author' => $author,
                'excerpt' => $excerpt,
            ]);
        }

        if ($author !== '') {
            return \TinyCat\Support\Localization::t($prefix . '_author', ['author' => $author]);
        }

        if ($excerpt !== '') {
            return \TinyCat\Support\Localization::t($prefix . '_excerpt', ['excerpt' => $excerpt]);
        }

        return \TinyCat\Support\Localization::t($prefix . '_for', ['id' => $id]);
    }

    public static function render_mentions(string $text): string
    {
        return \TinyCat\Status\ViewData::render_mentions_segment(\TinyCat\Status\Links::status_strip_external_urls($text));
    }

    /**
     * @param array<mixed> $hiddenLinkHashes
     */
    public static function render_status_text(string $text, array $hiddenLinkHashes = []): string
    {
        if ($text === '') {
            return '';
        }

        $hiddenLinkHashes = array_fill_keys(array_filter(array_map('strval', $hiddenLinkHashes)), true);

        if (!preg_match_all(\TinyCat\Status\LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
            return \TinyCat\Status\ViewData::render_mentions_segment($text);
        }

        $offset = 0;
        $html = '';

        foreach ((array) ($matches[0] ?? []) as $match) {
            $raw = (string) $match[0];
            $position = (int) $match[1];
            [$url, $tail] = \TinyCat\Status\LinkParser::splitTail($raw);

            $html .= \TinyCat\Status\ViewData::render_mentions_segment(substr($text, $offset, $position - $offset));

            if (\TinyCat\Status\Links::status_internal_url($url)) {
                $href = \TinyCat\Status\Links::status_internal_url_path($url);
                $html .= '<a class="status-inline-link" href="' . \TinyCat\Support\Html::e($href !== '' ? $href : $url) . '">' . \TinyCat\Support\Html::e($url) . '</a>';
            } else {
                $link = \TinyCat\Status\LinkParser::fromRaw($url);

                if ($link !== null) {
                    $hash = (string) ($link['url_hash'] ?? '');

                    if ($hash !== '' && isset($hiddenLinkHashes[$hash])) {
                        $html .= \TinyCat\Support\Html::e($tail);
                        $offset = $position + strlen($raw);
                        continue;
                    }

                    $html .= '<a class="status-inline-link" href="' . \TinyCat\Support\Html::e((string) ($link['normalized_url'] ?? $url)) . '" target="_blank" rel="nofollow noopener noreferrer ugc">' . \TinyCat\Support\Html::e($url) . '</a>';
                } else {
                    $html .= \TinyCat\Support\Html::e($url);
                }
            }

            $html .= \TinyCat\Support\Html::e($tail);
            $offset = $position + strlen($raw);
        }

        $html .= \TinyCat\Status\ViewData::render_mentions_segment(substr($text, $offset));

        return $html;
    }

    /**
     * @param array<mixed> $item
     */
    public static function render_status_body(array $item): string
    {
        $hiddenLinkHashes = [];
        $contentId = (int) ($item['id'] ?? 0);

        if ($contentId > 0) {
            foreach (\TinyCat\Status\Links::status_links_for_content($contentId) as $link) {
                if (\TinyCat\Status\Links::status_link_is_internal((array) $link)) {
                    continue;
                }

                $hash = (string) ($link['url_hash'] ?? '');

                if ($hash !== '') {
                    $hiddenLinkHashes[] = $hash;
                }
            }
        }

        return trim(\TinyCat\Status\ViewData::render_status_text((string) ($item['body'] ?? ''), $hiddenLinkHashes));
    }

    public static function render_mentions_segment(string $text): string
    {
        $text = \TinyCat\Status\ViewData::render_status_emoticons($text);
        $pattern = '/(?<![\p{L}\p{N}\p{M}_.@-])(?|(@)([\p{L}\p{N}](?:[\p{L}\p{N}\p{M}_-]|\.(?=[\p{L}\p{N}\p{M}_-]))*)|(#)([\p{L}\p{N}][\p{L}\p{N}_-]*))/u';
        $offset = 0;
        $html = '';

        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return nl2br(\TinyCat\Support\Html::e($text), false);
        }

        $mentionIds = [];

        foreach ($matches[0] as $index => $_match) {
            $symbol = (string) ($matches[1][$index][0] ?? '');
            $value = (string) ($matches[2][$index][0] ?? '');

            if ($symbol === '@' && ctype_digit($value) && (int) $value > 0) {
                $mentionIds[(int) $value] = (int) $value;
            }
        }

        $users = \TinyCat\Status\Mentions::author_mention_users_by_ids(array_values($mentionIds));

        foreach ($matches[0] as $index => $match) {
            $token = (string) $match[0];
            $position = (int) $match[1];
            $symbol = (string) ($matches[1][$index][0] ?? '');
            $handleRaw = (string) ($matches[2][$index][0] ?? '');
            $authorId = 0;

            $html .= \TinyCat\Support\Html::e(substr($text, $offset, $position - $offset));

            if ($symbol === '@') {
                if (ctype_digit($handleRaw) && isset($users[(int) $handleRaw])) {
                    $authorId = (int) $handleRaw;
                }

                if ($authorId > 0 && isset($users[$authorId])) {
                    $displayHandle = (string) ($users[$authorId]['handle'] ?? '');
                    $display = $displayHandle !== '' ? '@' . $displayHandle : '@' . $authorId;
                    $html .= '<a class="mention-link" href="' . \TinyCat\Support\Html::e(\TinyCat\Users\Profiles::author_url($authorId)) . '">' . \TinyCat\Support\Html::e($display) . '</a>';
                } else {
                    $html .= \TinyCat\Support\Html::e($token);
                }
            } elseif ($symbol === '#') {
                $tag = \TinyCat\Status\Tags::status_tag_normalize($handleRaw);
                $html .= $tag !== ''
                    ? '<a class="hashtag" href="' . \TinyCat\Support\Html::e(\TinyCat\Status\Tags::tag_url($tag)) . '">' . \TinyCat\Support\Html::e($token) . '</a>'
                    : \TinyCat\Support\Html::e($token);
            } else {
                $html .= \TinyCat\Support\Html::e($token);
            }

            $offset = $position + strlen($token);
        }

        $html .= \TinyCat\Support\Html::e(substr($text, $offset));

        return nl2br($html, false);
    }

    public static function render_status_emoticons(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Keep URL text untouched. Statuses render URLs separately, while
        // comments use this generic mention renderer directly.
        if (!preg_match_all(\TinyCat\Status\LinkParser::pattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
            return \TinyCat\Status\ViewData::render_status_emoticons_segment($text);
        }

        $offset = 0;
        $result = '';

        foreach ((array) ($matches[0] ?? []) as $match) {
            $raw = (string) $match[0];
            $position = (int) $match[1];

            $result .= \TinyCat\Status\ViewData::render_status_emoticons_segment(substr($text, $offset, $position - $offset));
            $result .= $raw;
            $offset = $position + strlen($raw);
        }

        return $result . \TinyCat\Status\ViewData::render_status_emoticons_segment(substr($text, $offset));
    }

    public static function render_status_emoticons_segment(string $text): string
    {
        $pattern = '/(?<![\\p{L}\\p{N}_])(<3|<2|:-?\\)|:-?\\(|:-?D|;-?\\)|;-?\\(|:-?P|;-?P|:-?\\/)(?![\\p{L}\\p{N}_])/iu';
        $map = [
            '<2' => '❤️',
            '<3' => '❤️',
            ':)' => '🙂',
            ':-)' => '🙂',
            ':(' => '🙁',
            ':-(' => '🙁',
            ':d' => '😄',
            ':-d' => '😄',
            ';)' => '😉',
            ';-)' => '😉',
            ';(' => '😢',
            ';-(' => '😢',
            ':p' => '😛',
            ':-p' => '😛',
            ';p' => '😜',
            ';-p' => '😜',
            ':-/' => '😕',
        ];

        return (string) preg_replace_callback($pattern, static function (array $match) use ($map): string {
            $token = strtolower((string) $match[1]);

            return $map[$token] ?? (string) $match[0];
        }, $text);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed>|null $user
     * @return array<int, array<string, mixed>>
     */
    public static function status_prepare_items_view(array $items, ?array $user): array
    {
        if ($items === []) {
            return [];
        }

        \TinyCat\Status\Feed::status_preload_feed($items);
        $userId = (int) ($user['id'] ?? 0);
        $ids = \TinyCat\Support\Text::positive_int_ids(array_column($items, 'id'));

        if ($userId > 0) {
            \TinyCat\Status\Reactions::status_preload_user_likes($ids, $userId);
        }

        foreach ($items as &$item) {
            $contentId = (int) ($item['id'] ?? 0);
            $publishedAt = (string) ($item['published_at'] ?? '');
            $links = array_values(array_filter(
                \TinyCat\Status\Links::status_links_for_content($contentId),
                static fn (array $link): bool => !\TinyCat\Status\Links::status_link_is_internal($link)
            ));

            $item['likes_count'] = (int) ($item['likes_count'] ?? 0);
            $item['comments_count'] = array_key_exists('comments_count', $item)
                ? (int) ($item['comments_count'] ?? 0)
                : \TinyCat\Status\Comments::status_comment_count($contentId);
            $item['viewer_liked'] = $userId > 0 && \TinyCat\Status\Reactions::status_user_liked($contentId, $userId);
            $item['_view'] = [
                'anchor' => Posts::status_anchor($contentId),
                'modal_id' => self::status_post_modal_id($contentId),
                'modal_url' => self::status_post_modal_url($contentId),
                'body_html' => \TinyCat\Status\ViewData::render_status_body($item),
                'author_url' => \TinyCat\Users\Profiles::author_url((int) ($item['author_id'] ?? 0)),
                'status_url' => \TinyCat\Status\Posts::status_url($contentId),
                'permalink_label' => \TinyCat\Status\ViewData::status_permalink_label($item),
                'context' => \TinyCat\Status\ViewData::status_accessible_context($item),
                'time' => [
                    'iso' => $publishedAt !== '' ? \TinyCat\Support\Dates::dateIso($publishedAt) : '',
                    'label' => $publishedAt !== '' ? \TinyCat\Support\Dates::displayDateTime($publishedAt) : '',
                ],
                'image' => [
                    'url' => \TinyCat\Status\Images::status_image_url($item),
                    'alt' => \TinyCat\Status\Images::status_image_alt_text($item),
                ],
                'links' => array_map('TinyCat\\Status\\ViewData::status_prepare_link_view', $links),
                'manage' => [
                    'edit_modal_id' => self::status_edit_modal_id($contentId),
                    'edit_modal_url' => self::status_action_modal_url('edit', $contentId),
                    'report_modal_id' => self::status_report_modal_id($contentId),
                    'report_modal_url' => self::status_action_modal_url('report', $contentId),
                    'delete_url' => Posts::status_api_url('delete', ['id' => $contentId]),
                    'is_locked' => \TinyCat\Status\Posts::status_edit_locked($item) && !\TinyCat\Users\Profiles::user_is_admin($user),
                    'can_edit' => \TinyCat\Status\Posts::status_can_edit($item, $user),
                    'can_delete' => \TinyCat\Status\Posts::status_can_delete($item, $user),
                    'can_report' => $user !== null
                        && (int) ($item['author_id'] ?? 0) !== $userId,
                ],
            ];
        }
        unset($item);

        return $items;
    }

    /**
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    public static function status_prepare_link_view(array $link): array
    {
        $url = (string) ($link['normalized_url'] ?? '');
        $displayUrl = \TinyCat\Status\Links::status_link_display_url($link);
        $title = trim((string) ($link['title'] ?? ''));
        $embedUrl = \TinyCat\Status\Links::status_video_embed_url($link);
        $thumbnail = \TinyCat\Status\Links::status_video_thumbnail_sources($link);

        return $link + [
            'display_url' => $displayUrl,
            'resolved_title' => $title !== '' ? $title : ($displayUrl !== '' ? $displayUrl : $url),
            'embed_url_resolved' => $embedUrl,
            'embed_allowed' => (string) ($link['link_type'] ?? 'link') === 'video'
                && \TinyCat\Status\Links::status_video_embed_allowed($embedUrl),
            'thumbnail_url' => (string) ($thumbnail['fallback'] ?? ''),
            'thumbnail_webp_url' => (string) ($thumbnail['webp'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $item
     * @param array<string, mixed>|null $user
     */

    /**
     * @param array<int, array<string, mixed>> $comments
     * @param array<string, mixed>|null $user
     * @return array<int, array<string, mixed>>
     */
    public static function status_prepare_comments_view(array $comments, ?array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        \TinyCat\Status\Comments::status_preload_comment_tree_user_likes($comments, $userId);
        $mentionIds = [];

        foreach (\TinyCat\Status\Comments::status_comment_tree_rows($comments) as $comment) {
            if (preg_match_all(\TinyCat\Status\Mentions::mention_id_pattern(), (string) ($comment['body'] ?? ''), $matches)) {
                foreach ($matches[1] as $mentionId) {
                    $mentionId = (int) $mentionId;

                    if ($mentionId > 0) {
                        $mentionIds[$mentionId] = $mentionId;
                    }
                }
            }
        }

        \TinyCat\Status\Mentions::author_mention_users_by_ids(array_values($mentionIds));

        return array_map(
            static fn (array $comment): array => \TinyCat\Status\ViewData::status_prepare_comment_view($comment, $user),
            $comments
        );
    }

    /**
     * @param array<string, mixed> $comment
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    public static function status_prepare_comment_view(array $comment, ?array $user): array
    {
        $commentId = (int) ($comment['id'] ?? 0);
        $userId = (int) ($user['id'] ?? 0);
        $createdAt = (string) ($comment['created_at'] ?? '');
        $replies = [];

        foreach ((array) ($comment['replies'] ?? []) as $reply) {
            if (is_array($reply)) {
                $replies[] = \TinyCat\Status\ViewData::status_prepare_comment_view($reply, $user);
            }
        }

        $comment['replies'] = $replies;
        $comment['likes_count'] = array_key_exists('likes_count', $comment)
            ? (int) ($comment['likes_count'] ?? 0)
            : \TinyCat\Status\Comments::status_comment_like_count($commentId);
        $comment['viewer_liked'] = $userId > 0 && \TinyCat\Status\Comments::status_comment_user_liked($commentId, $userId);
        $comment['can_edit'] = \TinyCat\Status\Comments::status_comment_can_edit($comment, $user);
        $comment['can_delete'] = \TinyCat\Status\Comments::status_comment_can_delete($comment, $user);
        $comment['_view'] = [
            'body_html' => \TinyCat\Status\ViewData::render_mentions((string) ($comment['body'] ?? '')),
            'author_url' => \TinyCat\Users\Profiles::author_url((int) ($comment['user_id'] ?? 0)),
            'context' => \TinyCat\Status\ViewData::status_accessible_context($comment, true),
            'mention' => \TinyCat\Status\Comments::status_comment_mention((string) ($comment['author_name'] ?? '')),
            'time' => [
                'iso' => $createdAt !== '' ? \TinyCat\Support\Dates::dateIso($createdAt) : '',
                'label' => $createdAt !== '' ? \TinyCat\Support\Dates::displayDateTime($createdAt) : '',
            ],
        ];

        return $comment;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed>|null $user
     * @return array{item: array<string, mixed>, comments: array<int, array<string, mixed>>}
     */
    public static function status_prepare_detail_view(array $item, ?array $user): array
    {
        $items = \TinyCat\Status\ViewData::status_prepare_items_view([$item], $user);
        $prepared = $items[0] ?? $item;
        $comments = \TinyCat\Status\Comments::status_comments((int) ($item['id'] ?? 0));

        return [
            'item' => $prepared,
            'comments' => \TinyCat\Status\ViewData::status_prepare_comments_view($comments, $user),
        ];
    }

    public static function status_comment_history_diff_html(string $before, string $after): string
    {
        $beforeChars = preg_split('//u', $before, -1, PREG_SPLIT_NO_EMPTY);
        $afterChars = preg_split('//u', $after, -1, PREG_SPLIT_NO_EMPTY);

        if ($beforeChars === false || $afterChars === false) {
            $beforeChars = str_split($before);
            $afterChars = str_split($after);
        }

        $beforeLength = count($beforeChars);
        $afterLength = count($afterChars);
        $prefixLength = 0;

        while ($prefixLength < $beforeLength
            && $prefixLength < $afterLength
            && $beforeChars[$prefixLength] === $afterChars[$prefixLength]) {
            $prefixLength++;
        }

        $suffixLength = 0;
        while ($suffixLength < $beforeLength - $prefixLength
            && $suffixLength < $afterLength - $prefixLength
            && $beforeChars[$beforeLength - 1 - $suffixLength] === $afterChars[$afterLength - 1 - $suffixLength]) {
            $suffixLength++;
        }

        $prefix = implode('', array_slice($beforeChars, 0, $prefixLength));
        $removed = implode('', array_slice($beforeChars, $prefixLength, $beforeLength - $prefixLength - $suffixLength));
        $added = implode('', array_slice($afterChars, $prefixLength, $afterLength - $prefixLength - $suffixLength));
        $suffix = $suffixLength > 0 ? implode('', array_slice($beforeChars, -$suffixLength)) : '';
        $html = \TinyCat\Support\Html::e($prefix);

        if ($removed !== '') {
            $html .= '<del class="status-comment-diff-removed">' . \TinyCat\Support\Html::e($removed) . '</del>';
        }

        if ($added !== '') {
            $html .= '<ins class="status-comment-diff-added">' . \TinyCat\Support\Html::e($added) . '</ins>';
        }

        return $html . \TinyCat\Support\Html::e($suffix);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function status_editor_view_data(array $item = []): array
    {
        $tags = json_encode(
            \TinyCat\Status\Tags::status_tag_suggestions(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return [
            'item' => $item,
            'tags_json' => is_string($tags) ? $tags : '[]',
            'image' => [
                'enabled' => \TinyCat\Status\Images::status_images_enabled(),
                'url' => \TinyCat\Status\Images::status_image_url($item),
                'alt' => \TinyCat\Status\Images::status_image_alt_text($item),
            ],
        ];
    }

    public static function status_post_modal_id(int $contentId): string
    {
        return 'status-post-modal-' . max(0, $contentId);
    }

    public static function status_edit_modal_id(int $contentId): string
    {
        return 'status-edit-modal-' . max(0, $contentId);
    }

    public static function status_comment_edit_modal_id(int $commentId): string
    {
        return 'status-comment-edit-modal-' . max(0, $commentId);
    }

    public static function status_comment_history_modal_id(int $commentId): string
    {
        return 'status-comment-history-modal-' . max(0, $commentId);
    }

    public static function status_post_modal_url(int $contentId, string $action = ''): string
    {
        return '/api/statuses/' . $contentId;
    }

    public static function status_action_modal_url(string $type, int $contentId, string $action = ''): string
    {
        return $type === 'report' ? '/api/moderation/report-options?id=' . $contentId : '/api/statuses/' . $contentId . '?panel=edit';
    }

    public static function status_comment_edit_modal_url(int $commentId, int $contentId = 0): string
    {
        $id = $contentId > 0 ? $contentId : (int) (\TinyCat\Status\Comments::status_comment_find($commentId)['content_id'] ?? 0);
        return '/api/statuses/' . $id . '?panel=comment-edit&comment_id=' . $commentId;
    }

    public static function status_comment_history_modal_url(int $commentId, int $contentId = 0): string
    {
        $id = $contentId > 0 ? $contentId : (int) (\TinyCat\Status\Comments::status_comment_find($commentId)['content_id'] ?? 0);
        return '/api/statuses/' . $id . '/comments/' . $commentId . '/revisions';
    }

    public static function status_report_modal_id(int $contentId): string
    {
        return 'status-report-modal-' . max(0, $contentId);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function status_feed_more_view_data(
        string $feedId,
        string $context,
        array $items,
        int $limit,
        array $params = [],
        ?int $nextLimit = null
    ): array {
        $loaded = count($items);
        $params += \TinyCat\Status\Feed::status_feed_cursor_params($items);
        $nextLimit = $nextLimit === null ? $limit : max(1, min(50, $nextLimit));

        return [
            'feed_id' => $feedId,
            'loaded' => $loaded,
            'limit' => $limit,
            'next_url' => $loaded >= $limit ? \TinyCat\Status\Feed::status_feed_next_url($context, $nextLimit, $params) : '',
        ];
    }

    /**
     * @param array<mixed> $user
     * @param array<mixed> $items
     * @return array<mixed>
     */
    public static function public_home_feed_data(string $feed = 'all', ?array $user = null, ?array $items = null): array
    {
        $user ??= \TinyCat\Users\Authentication::auth();
        $feed = $feed === 'following' ? 'following' : 'all';
        $currentFeedUrl = $feed === 'following' ? '/?feed=following' : '/';
        $followingLoginRequired = $feed === 'following' && $user === null;
        $limit = \TinyCat\Status\Feed::public_status_initial_page_limit();
        $nextLimit = \TinyCat\Status\Feed::public_status_page_limit();

        if ($items === null) {
            $items = $followingLoginRequired
                ? []
                : ($feed === 'following'
                    ? \TinyCat\Status\Feed::public_status_items_for_user_cursor((int) ($user['id'] ?? 0), $limit)
                    : \TinyCat\Status\Feed::public_status_items_cursor($limit));
        }

        $feedId = 'status-feed-' . $feed;
        $items = \TinyCat\Status\ViewData::status_prepare_items_view($items, $user);

        return [
            'feed' => $feed,
            'user' => $user,
            'current_feed_url' => $currentFeedUrl,
            'following_login_required' => $followingLoginRequired,
            'limit' => $limit,
            'items' => $items,
            'feed_id' => $feedId,
            'feed_more' => \TinyCat\Status\ViewData::status_feed_more_view_data(
                $feedId,
                'home',
                $items,
                $limit,
                ['feed' => $feed],
                $nextLimit
            ),
        ];
    }
}
