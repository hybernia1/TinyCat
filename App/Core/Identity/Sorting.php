<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;

/** SQL allowlists and navigation owned by these lists. */
final class Sorting
{
    /** @return array{string, string, string, array<string, string>, string, string} */
    public static function users(): array
    {
        return \TinyCat\Core\Ui\Admin\Definitions::sorting('core.users', ['username' => 'username', 'role' => 'role', 'status' => 'status', 'updated_at' => 'updated_at'], 'id DESC', 'id');
    }
}
