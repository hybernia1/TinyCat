<?php
declare(strict_types=1);
namespace TinyCat\Security;

use \TinyCat\Http\Result;
use \TinyCat\Http\Response;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->post('/security/challenges/search', static function (): Result {
            if (!\TinyCat\Search\Guard::public_search_captcha_verify()) { Response::error(\TinyCat\Support\Localization::t('auth.invalid_captcha'), 422, 'captcha_invalid', ['captcha' => \TinyCat\Security\Captcha::configuration('search')]); }
            return new Result(['unlocked' => true], \TinyCat\Support\Localization::t('public.search_captcha_unlocked'));
        }, 'public', 200);
    }
}
