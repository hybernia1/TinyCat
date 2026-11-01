<?php
declare(strict_types=1);

namespace TinyCat\Status;

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

        $users =& \TinyCat\Status\ReadCache::author_mention_user_cache();
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
                        'handle' => \TinyCat\Users\Usernames::username_normalize($name),
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
        $path = \TinyCat\Status\Links::status_internal_url_path($url);
        $path = \TinyCat\Site\LocaleRouting::split($path)['path'];

        if ($path === '' || preg_match('~^/author/([0-9]+)/?$~', $path, $match) !== 1) {
            return '';
        }

        $authorId = (int) $match[1];

        return $authorId > 0 && isset(\TinyCat\Status\Mentions::author_mention_users_by_ids([$authorId])[$authorId]) ? '@' . $authorId : '';
    }

    public static function normalize_author_urls_for_storage(string $text): string
    {
        $pattern = '~(?<![\p{L}\p{N}_])((?:https?://|www\.)[^\s<>"\']+|/(?:[a-z]{2}/)?author/[0-9]+[^\s<>"\']*)~iu';

        return (string) preg_replace_callback($pattern, static function (array $match): string {
            $raw = (string) $match[1];
            [$url, $tail] = \TinyCat\Status\LinkParser::splitTail($raw);
            $mention = \TinyCat\Status\Mentions::status_author_url_mention($url);

            return $mention !== '' ? $mention . $tail : $raw;
        }, $text);
    }

    public static function normalize_mentions_for_storage(string $text): string
    {
        $text = \TinyCat\Status\Mentions::normalize_author_urls_for_storage($text);
        $pattern = '/(?<![\p{L}\p{N}\p{M}_.@#-])@([\p{L}\p{N}](?:[\p{L}\p{N}\p{M}_.-]{0,30}[\p{L}\p{N}\p{M}_])?)(?![\p{L}\p{N}\p{M}_-]|\.[\p{L}\p{N}\p{M}_.-])/u';

        if (!preg_match_all($pattern, InlineText::withoutLinks($text), $matches)) {
            return $text;
        }

        $ids = [];
        $handles = [];

        foreach ((array) $matches[1] as $token) {
            $token = \TinyCat\Users\Usernames::username_key((string) $token);

            if (ctype_digit($token)) {
                $id = (int) $token;

                if ($id > 0) {
                    $ids[$id] = $id;
                }
            } else {
                $handle = \TinyCat\Users\Usernames::username_key($token);

                if ($handle !== '') {
                    $handles[$handle] = $handle;
                }
            }
        }

        $users = \TinyCat\Status\Mentions::author_mention_users_by_ids(array_values($ids));
        $map = [];

        if ($handles !== []) {
            foreach (\TinyCat\Database\Queries::db_select('SELECT id, username FROM users')
                ->where('status = ?', 'active')
                ->whereIn('username', array_values($handles))
                ->all() as $user) {
                $id = (int) ($user['id'] ?? 0);
                $handle = \TinyCat\Users\Usernames::username_key((string) ($user['username'] ?? ''));

                if ($id > 0 && $handle !== '') {
                    $map[$handle] = $id;
                }
            }
        }

        return InlineText::map($text, static fn (string $part): string => (string) preg_replace_callback($pattern, static function (array $match) use ($map, $users): string {
            $token = \TinyCat\Users\Usernames::username_key((string) $match[1]);

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
        if ($text === '' || !preg_match_all(\TinyCat\Status\Mentions::mention_id_pattern(), InlineText::withoutLinks($text), $matches)) {
            return $text;
        }

        $ids = \TinyCat\Support\Text::positive_int_ids($matches[1]);
        $users = \TinyCat\Status\Mentions::author_mention_users_by_ids($ids);

        return InlineText::map($text, static fn (string $part): string => (string) preg_replace_callback(
            \TinyCat\Status\Mentions::mention_id_pattern(),
            static function (array $match) use ($users): string {
                $id = (int) $match[1];
                $handle = (string) ($users[$id]['handle'] ?? '');

                return $handle !== '' ? '@' . $handle : (string) $match[0];
            },
            $part
        ));
    }
}
