<?php
declare(strict_types=1);

namespace TinyCat\Status;

final class ReadCache
{
    /**
     * @return array<mixed>
     */
    public static function &author_mention_user_cache(): array
    {
        static $users = [];

        return $users;
    }

    /**
     * @return array<mixed>
     */
    public static function &status_user_liked_cache(): array
    {
        static $cache = [];

        return $cache;
    }

    /**
     * @return array<mixed>
     */
    public static function &status_comments_cache(): array
    {
        static $cache = [];

        return $cache;
    }

    /**
     * @return array<mixed>
     */
    public static function &status_comment_user_liked_cache(): array
    {
        static $cache = [];

        return $cache;
    }
}
