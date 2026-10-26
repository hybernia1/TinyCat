<?php
declare(strict_types=1);

namespace TinyCat\Users;

use TinyCat\Http\Request;
use TinyCat\Http\Response;
use TinyCat\Security\RateLimiter;
use TinyCat\Support\Localization;

final class EmailAvailability
{
    /** @return array{available: bool} */
    public static function check(?int $ignoreId = null): array
    {
        header('Cache-Control: no-store, max-age=0');
        if (RateLimiter::rate_limit_ip_action_count('email-check', 60) >= 60) {
            header('Retry-After: 60');
            Response::error(Localization::t('account.messages.email_check_limited'), 429, 'email_check_limited');
        }
        RateLimiter::rate_limit_ip_action_record('email-check', 60);
        Request::current()->validated(['email' => 'required|string']);
        $email = Profiles::user_email_normalize((string) Request::current()->input('email', ''));
        if (!Profiles::user_email_valid($email)) {
            Response::validation(['email' => [Response::issue('email_invalid', Localization::t('account.messages.email_invalid'))]]);
        }
        return ['available' => !Profiles::user_email_taken($email, $ignoreId)];
    }
}
