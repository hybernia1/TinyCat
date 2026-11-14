<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

use TinyCat\Support\Components;
use TinyCat\Support\View;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{

    /** @param array<mixed> $data */
    public static function adminFormFields(array $data = []): string
    {
        return \TinyCat\Core\Identity\Views::adminFormFields($data);
    }

    /** @param array<mixed> $data */
    public static function adminList(array $data = []): string
    {
        return \TinyCat\Core\Identity\Views::adminList($data);
    }

    /** @param array<mixed> $data */
    public static function authContent(array $data = []): string
    {
        return \TinyCat\Core\Identity\Views::authContent($data);
    }

    /** @param array<mixed> $data */
    public static function authForm(array $data = []): string
    {
        return \TinyCat\Core\Identity\Views::authForm($data);
    }

    /** @param array<mixed> $data */
    public static function followButton(array $data = []): string
    {
        $author_id = $data['author_id'] ?? null;
        $is_following = $data['is_following'] ?? null;
        $authorId = max(0, (int) ($author_id ?? 0));
        $isFollowing = (bool) ($is_following ?? false);

        if ($authorId < 1) {
            return '';
        }
        $prepared = [];
        $prepared['author_api_url'] = Profiles::author_api_url($authorId);
        return View::part('Social/users/follow-button', array_replace($data, compact('authorId', 'isFollowing', 'prepared')));
    }

    /** @param array<mixed> $data */
    public static function followingProfile(array $data = []): string
    {
        return Components::render('users/following-profile', ['user' => Resource::profile((array) ($data['profile'] ?? []))]);
    }

    /** @param array<mixed> $data */
    public static function avatar(array $data = []): string
    {
        return \TinyCat\Core\Identity\Views::avatar($data);
    }
}
