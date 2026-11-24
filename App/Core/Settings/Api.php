<?php
declare(strict_types=1);
namespace TinyCat\Core\Settings;

use \TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/settings', static fn (): array => ['items' => Resource::items()], 'admin');
        $api->get('/settings/editor', static fn (): array => Resource::editor(), 'admin');
        $api->patch('/settings', static fn (): Result => Result::from(self::adminSettingsSave()), 'admin');
    }

    /**
     * @return array<mixed>
     */
    private static function adminSettingsSave(): array
    {
        try {
            Administration::tc_admin_settings_save();
        } catch (\InvalidArgumentException $exception) {
            \TinyCat\Http\Response::error($exception->getMessage(), 422, 'settings_save_failed');
        }

        return Resource::editor() + ['message' => \TinyCat\Support\Localization::t('settings.messages.saved'), 'code' => 'settings_saved'];
    }
}
