<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class Accounts
{
    /** @param array<mixed> $user */
    public static function setActive(array $user, bool $active): void
    {
        if (!$active && \TinyCat\Core\Identity\Administration::isPrimary((int) ($user['id'] ?? 0))) {
            throw new \DomainException('super_admin_protected');
        }
        \TinyCat\Core\Identity\Lifecycle::setActive($user, $active, \TinyCat\Core\Identity\Participants::current());
    }

    /**
     * @param array<mixed> $registration
     * @return array<mixed>
     */
    public static function registration_create_user(array $registration): array
    {
        return \TinyCat\Core\Identity\Integration::register($registration);
    }

    public static function registration_enabled(): bool
    {
        return \TinyCat\Core\Identity\Integration::registrationEnabled();
    }

    public static function registration_auto_approve(): bool
    {
        return (bool) \TinyCat\Core\Runtime\Startup::option('auth.registration.auto_approve', false);
    }

    /**
     * @return array<mixed>|null
     */
    public static function user_delete_account(int $userId): ?array
    {
        return \TinyCat\Core\Identity\Lifecycle::delete($userId, \TinyCat\Core\Identity\Participants::current(true));
    }

    public static function tc_admin_user_filter_date(string $value): string
    {
        return \TinyCat\Core\Identity\ReadModel::tc_admin_user_filter_date($value);
    }

    /**
     * @param array<mixed> $filters
     * @return array<mixed>
     */
    public static function tc_admin_users_active_filters(array $filters, bool $includeSearch = true): array
    {
        return \TinyCat\Core\Identity\ReadModel::tc_admin_users_active_filters($filters, $includeSearch);
    }

    /** @param array<mixed> $filters */
    public static function tc_admin_users_query(array $filters): \TinyCat\Database\Query
    {
        return \TinyCat\Core\Identity\ReadModel::tc_admin_users_query($filters);
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_stats(): array
    {
        return \TinyCat\Core\Identity\ReadModel::tc_admin_users_stats();
    }

    /**
     * @return array<mixed>|null
     */
    public static function tc_admin_user_by_id(int $id): ?array
    {
        return \TinyCat\Core\Identity\ReadModel::tc_admin_user_by_id($id);
    }

    /** @param array<mixed>|int $user */
    public static function tc_admin_user_is_super_admin(array|int $user): bool
    {
        return \TinyCat\Core\Identity\ReadModel::tc_admin_user_is_super_admin($user);
    }

    /**
     * @param array<mixed> $user
     * @return array<mixed>
     */
    public static function tc_admin_user_resource(array $user): array
    {
        return \TinyCat\Core\Identity\Resource::legacyUser($user);
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_user_validation_messages(): array
    {
        return \TinyCat\Core\Identity\ReadModel::tc_admin_user_validation_messages();
    }
}
