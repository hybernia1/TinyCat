<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

use TinyCat\Support\Components;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{
    /** @param array<mixed> $data */
    public static function followingProfile(array $data = []): string
    {
        return Components::render('users/following-profile', ['user' => Resource::profile((array) ($data['profile'] ?? []))]);
    }

}
