<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** Runtime availability is independent of module activation and public themes. */
final class PublicWeb
{
    public static function seed(): void
    {
        if (\TinyCat\Core\Settings\Store::setting('site.public_enabled', null) === null) {
            \TinyCat\Core\Settings\Store::setSetting('site.public_enabled', false, 'bool', 'site');
        }
    }

    public static function enabled(): bool { return (bool) \TinyCat\Core\Runtime\Startup::option('site.public_enabled', true); }
}
