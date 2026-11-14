<?php
declare(strict_types=1);

namespace TinyCat\Social\Presentation;


/** Current product policy and email delivery, outside the reusable account services. */
final class Accounts
{
    public static function configure(): void
    {
        \TinyCat\Core\Identity\Integration::provide('tinycat.site', [
            'landing' => static fn (?array $user): string => \TinyCat\Core\Identity\Roles::isAdmin($user) ? '/admin' : \TinyCat\Social\Profiles\Profiles::author_url((int) ($user['id'] ?? 0)),
            'profile' => \TinyCat\Social\Profiles\Profiles::author_url(...),
            'next' => static function (string $url): string {
                $fragment = (string) (parse_url($url, PHP_URL_FRAGMENT) ?: '');
                return preg_match('/^status-(?:comments-thread-)?([1-9][0-9]*)$/', $fragment, $match) === 1 ? '/status/' . $match[1] : $url;
            },
        ]);
    }

}
