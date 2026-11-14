<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

final class Mentions
{
    /**
     * @param array<mixed> $userIds
     * @return array<mixed>
     */
    public static function author_mention_users_by_ids(array $userIds): array
    {
        $userIds = \TinyCat\Support\Text::positive_int_ids($userIds);

        if ($userIds === []) {
            return [];
        }

        $users =& \TinyCat\Social\Content\ReadCache::author_mention_user_cache();
        $missing = array_values(array_filter($userIds, static fn (int $id): bool => !array_key_exists($id, $users)));

        if ($missing !== []) {
            foreach ($missing as $id) {
                $users[$id] = null;
            }

            foreach (\TinyCat\Database\Queries::db_select('SELECT id, username FROM users')
                ->where('status = ?', 'active')
                ->whereIn('id', $missing)
                ->all() as $user) {
                $id = (int) ($user['id'] ?? 0);
                $name = (string) ($user['username'] ?? '');

                if ($id > 0) {
                    $users[$id] = [
                        'id' => $id,
                        'name' => $name,
                        'handle' => \TinyCat\Core\Identity\Usernames::normalize($name),
                    ];
                }
            }
        }

        $result = [];

        foreach ($userIds as $id) {
            if (isset($users[$id])) {
                $result[$id] = $users[$id];
            }
        }

        return $result;
    }

    public static function status_author_url_mention(string $url): string
    {
        $path = \TinyCat\Social\Content\Links::status_internal_url_path($url);
        $path = \TinyCat\Social\Content\Integration::unlocalizedPath($path);

        if ($path === '' || preg_match('~^/author/([0-9]+)/?$~', $path, $match) !== 1) {
            return '';
        }

        $authorId = (int) $match[1];

        return $authorId > 0 && isset(\TinyCat\Social\Posts\Mentions::author_mention_users_by_ids([$authorId])[$authorId]) ? '@' . $authorId : '';
    }

    public static function normalize_author_urls_for_storage(string $text): string
    {
        $pattern = '~(?<![\p{L}\p{N}_])((?:https?://|www\.)[^\s<>"\']+|/(?:[a-z]{2}/)?author/[0-9]+[^\s<>"\']*)~iu';

        return (string) preg_replace_callback($pattern, static function (array $match): string {
            $raw = (string) $match[1];
            [$url, $tail] = \TinyCat\Core\Text\LinkParser::splitTail($raw);
            $mention = \TinyCat\Social\Posts\Mentions::status_author_url_mention($url);

            return $mention !== '' ? $mention . $tail : $raw;
        }, $text);
    }

    public static function normalize_mentions_for_storage(string $text): string
    {
        $text = \TinyCat\Social\Posts\Mentions::normalize_author_urls_for_storage($text);
        $pattern = '/(?<![\p{L}\p{N}\p{M}_.@#-])@([\p{L}\p{N}](?:[\p{L}\p{N}\p{M}_.-]{0,30}[\p{L}\p{N}\p{M}_])?)(?![\p{L}\p{N}\p{M}_-]|\.[\p{L}\p{N}\p{M}_.-])/u';

        if (!preg_match_all($pattern, \TinyCat\Core\Text\InlineText::withoutLinks($text), $matches)) {
            return $text;
        }

        $ids = [];
        $handles = [];

        foreach ((array) $matches[1] as $token) {
            $token = \TinyCat\Core\Identity\Usernames::key((string) $token);

            if (ctype_digit($token)) {
                $id = (int) $token;

                if ($id > 0) {
                    $ids[$id] = $id;
                }
            } else {
                $handle = \TinyCat\Core\Identity\Usernames::key($token);

                if ($handle !== '') {
                    $handles[$handle] = $handle;
                }
            }
        }

        $users = \TinyCat\Social\Posts\Mentions::author_mention_users_by_ids(array_values($ids));
        $map = [];

        if ($handles !== []) {
            foreach (\TinyCat\Database\Queries::db_select('SELECT id, username FROM users')
                ->where('status = ?', 'active')
                ->whereIn('username', array_values($handles))
                ->all() as $user) {
                $id = (int) ($user['id'] ?? 0);
                $handle = \TinyCat\Core\Identity\Usernames::key((string) ($user['username'] ?? ''));

                if ($id > 0 && $handle !== '') {
                    $map[$handle] = $id;
                }
            }
        }

        return \TinyCat\Core\Text\InlineText::map($text, static fn (string $part): string => (string) preg_replace_callback($pattern, static function (array $match) use ($map, $users): string {
            $token = \TinyCat\Core\Identity\Usernames::key((string) $match[1]);

            if (ctype_digit($token) && isset($users[(int) $token])) {
                return '@' . (int) $token;
            }

            if (isset($map[$token])) {
                return '@' . (int) $map[$token];
            }

            return (string) $match[0];
        }, $part));
    }

    public static function mention_id_pattern(): string
    {
        return '/(?<![\p{L}\p{N}\p{M}_.@#-])@([1-9][0-9]*)(?![\p{L}\p{N}\p{M}_-]|\.[\p{L}\p{N}\p{M}_.-])/u';
    }

    public static function mentions_for_editing(string $text): string
    {
        if ($text === '' || !preg_match_all(\TinyCat\Social\Posts\Mentions::mention_id_pattern(), \TinyCat\Core\Text\InlineText::withoutLinks($text), $matches)) {
            return $text;
        }

        $ids = \TinyCat\Support\Text::positive_int_ids($matches[1]);
        $users = \TinyCat\Social\Posts\Mentions::author_mention_users_by_ids($ids);

        return \TinyCat\Core\Text\InlineText::map($text, static fn (string $part): string => (string) preg_replace_callback(
            \TinyCat\Social\Posts\Mentions::mention_id_pattern(),
            static function (array $match) use ($users): string {
                $id = (int) $match[1];
                $handle = (string) ($users[$id]['handle'] ?? '');

                return $handle !== '' ? '@' . $handle : (string) $match[0];
            },
            $part
        ));
    }
}
