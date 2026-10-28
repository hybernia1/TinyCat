<?php
declare(strict_types=1);

namespace TinyCat\Users;

use TinyCat\Http\Request;
use TinyCat\Http\Response;
use TinyCat\Support\Localization;

final class EmailAvailability
{
    /** @return array{available: bool} */
    public static function check(?int $ignoreId = null): array
    {
        header('Cache-Control: no-store, max-age=0');
        \TinyCat\Security\RequestLimits::requireAllowed('email-check');
        Request::current()->validated(['email' => 'required|string']);
        $email = Profiles::user_email_normalize((string) Request::current()->input('email', ''));
        if (!Profiles::user_email_valid($email)) {
            Response::validation(['email' => [Response::issue('email_invalid', Localization::t('account.messages.email_invalid'))]]);
        }
        return ['available' => !Profiles::user_email_taken($email, $ignoreId)];
    }
}
