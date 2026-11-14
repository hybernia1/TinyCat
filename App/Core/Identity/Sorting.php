<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** SQL allowlists and navigation owned by these lists. */
final class Sorting
{
    public const LISTS = [
        'users' => ['/api/users', '/admin/users', '#users-list', ['username' => 'username', 'role' => 'role', 'status' => 'status', 'updated_at' => 'updated_at'], 'id DESC', 'id'],
    ];
}
