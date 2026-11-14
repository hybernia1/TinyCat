<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

/** HTTP actions orchestrate input, policies and domain operations. */
final class Actions
{
    /**
     * @return array<mixed>
     */
    public static function registration_input(): array
    {
        return \TinyCat\Core\Identity\Actions::registration_input();
    }

    /**
     * @return array<mixed>
     */
    public static function registration_request(): array
    {
        return \TinyCat\Core\Identity\Actions::registration_request();
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_filters(): array
    {
        return \TinyCat\Core\Identity\Actions::tc_admin_users_filters();
    }

    /** @param array<mixed>|int $user */
    public static function tc_admin_user_require_deletable(array|int $user): void
    {
        \TinyCat\Core\Identity\Actions::tc_admin_user_require_deletable($user);
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_user_payload(?int $id = null): array
    {
        return \TinyCat\Core\Identity\Actions::tc_admin_user_payload($id);
    }

    /**
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    public static function user_profile_require_edit_target(array $actor, int $authorId): array
    {
        return \TinyCat\Core\Identity\Actions::user_profile_require_edit_target($actor, $authorId);
    }

    /**
     * @param array<mixed> $user
     * @param array<mixed> $actor
     * @return array<mixed>
     */
    public static function user_profile_update_request(array $user, array $actor): array
    {
        return \TinyCat\Core\Identity\Actions::user_profile_update_request($user, $actor);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function user_email_update_request(array $user): array
    {
        return \TinyCat\Core\Identity\Actions::user_email_update_request($user);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function user_password_update_request(array $user): array
    {
        return \TinyCat\Core\Identity\Actions::user_password_update_request($user);
    }
    /**
     * @param array<mixed> $params
     */
    public static function tc_admin_users_api_url(array $params = [], bool $withFilters = true): string
    {
        return \TinyCat\Core\Identity\Actions::tc_admin_users_api_url($params, $withFilters);
    }

    /**
     * @param array<mixed> $filters
     * @param array<mixed> $pagination
     * @return array<mixed>
     */
    public static function tc_admin_users_list_params(?array $filters = null, ?array $pagination = null): array
    {
        return \TinyCat\Core\Identity\Actions::tc_admin_users_list_params($filters, $pagination);
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_page(?array $filters = null): array
    {
        return \TinyCat\Core\Identity\Actions::tc_admin_users_page($filters);
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_api_payload(?int $id = null): array
    {
        return \TinyCat\Core\Identity\Actions::tc_admin_users_api_payload($id);
    }

    public static function adminUserCreate(): int
    {
        return \TinyCat\Core\Identity\Actions::adminUserCreate();
    }

    /** @param array<mixed> $user
     * @return array<mixed> */
    public static function adminUserUpdate(array $user): array
    {
        return \TinyCat\Core\Identity\Actions::adminUserUpdate($user);
    }
}
