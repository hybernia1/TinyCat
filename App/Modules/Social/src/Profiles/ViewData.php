<?php
declare(strict_types=1);

namespace TinyCat\Social\Profiles;

final class ViewData
{

    /**
     * @return array<mixed>
     */
    public static function tc_admin_users_view_data(): array
    {
        return \TinyCat\Core\Identity\ViewData::tc_admin_users_view_data();
    }
}
