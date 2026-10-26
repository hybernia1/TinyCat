<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use \TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->post('/scheduler/tokens', static function (): Result { \TinyCat\Scheduler\Tokens::cron_token_rotate(); return new Result(['token' => \TinyCat\Scheduler\Tokens::cron_token(), 'rotated' => true], \TinyCat\Support\Localization::t('cron.messages.token_rotated'), [], 'scheduler_token_rotated', []); }, 'admin');
    }
}
