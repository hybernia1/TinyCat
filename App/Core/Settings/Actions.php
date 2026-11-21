<?php
declare(strict_types=1);
namespace TinyCat\Core\Settings;

use InvalidArgumentException;

final class Actions
{
    /**
     * @return array<mixed>
     */
    public static function adminSettingsSave(): array
    {
        try {
            Administration::tc_admin_settings_save();
        } catch (InvalidArgumentException $exception) {
            \TinyCat\Http\Response::error($exception->getMessage(), 422, 'settings_save_failed');
        }

        return Resource::editor() + ['message' => \TinyCat\Support\Localization::t('settings.messages.saved'), 'code' => 'settings_saved'];
    }
}
