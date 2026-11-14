<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use \TinyCat\Http\Api;

interface Module
{
    public static function register(Api $api): void;
}
