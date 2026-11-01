<?php
declare(strict_types=1);
namespace TinyCat\Settings;

use \TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/settings', static fn (): array => ['items' => Resource::items()], 'admin');
        $api->get('/settings/editor', static fn (): array => Resource::editor(), 'admin');
        $api->patch('/settings', static fn (): Result => Result::from(Actions::adminSettingsSave()), 'admin');
    }
}
