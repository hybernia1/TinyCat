<?php
declare(strict_types=1);
namespace TinyCat\Settings;

use InvalidArgumentException;

final class Actions
{
    /**
     * @return array<mixed>
     */
    public static function adminSettingsSave(): array
    {
        try {
            \TinyCat\Settings\Administration::tc_admin_settings_save();
        } catch (InvalidArgumentException $exception) {
            \TinyCat\Http\Response::error($exception->getMessage(), 422, 'settings_save_failed');
        }

        return \TinyCat\Settings\Administration::tc_admin_settings_payload() + ['message' => \TinyCat\Support\Localization::t('settings.messages.saved'), 'code' => 'settings_saved'];
    }
}
